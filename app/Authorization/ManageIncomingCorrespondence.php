<?php

namespace App\Authorization;

use App\Models\AuthorizationScope;
use App\Models\DocumentSecurityAudit;
use App\Models\DocumentSecurityProfile;
use App\Models\DocumentType;
use App\Models\IncomingCorrespondence;
use App\Models\IncomingCorrespondenceAudit;
use App\Models\NumberingSeries;
use App\Models\RegisterConfiguration;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use LogicException;

class ManageIncomingCorrespondence
{
    public function __construct(private AccessControl $roles, private DocumentAccess $documents, private DocumentNumbering $numbering) {}

    public function authorizeContext(User $actor, DocumentScope $scope, ?string $permission = null): void
    {
        abort_unless($scope->isActive(), 403, 'rbacForbidden');
        if ($permission !== null) {
            abort_unless($this->roles->allows($actor, $permission, $scope->authorizationScope(), $scope->departmentId), 403, 'rbacForbidden');
        }
        abort_unless(in_array('documents.metadata.view', $this->roles->grantedPermissions($actor, $scope->authorizationScope()), true)
            && ($this->roles->isSystemAdministrator($actor) || app(MembershipAccess::class)->matches($actor, $scope->authorizationScope(), $scope->departmentId)), 403, 'rbacForbidden');
    }

    /** @return Builder<IncomingCorrespondence> */
    public function query(DocumentScope $scope): Builder
    {
        $canonical = $scope->authorizationScope();

        return IncomingCorrespondence::query()->where('scope_id', $canonical->id)->whereHas('profile', fn (Builder $query): Builder => $query
            ->where('company_id', $scope->companyId)->where('site_id', $scope->siteId)->where('department_id', $scope->departmentId));
    }

    public function canRead(User $actor, IncomingCorrespondence $record): bool
    {
        return $this->documents->allows($actor, DocumentSecurityDescriptor::fromProfile($record->profile), DocumentOperation::Metadata);
    }

    public function find(User $actor, DocumentScope $scope, string $id, bool $lock = false): IncomingCorrespondence
    {
        $this->authorizeContext($actor, $scope);
        $record = $this->query($scope)->when($lock, fn (Builder $query): Builder => $query->lockForUpdate())->findOrFail($id);
        abort_unless($this->canRead($actor, $record), 404);

        return $record;
    }

    /** @param array<string, mixed> $data */
    public function save(User $actor, DocumentScope $scope, array $data, ?string $id = null): IncomingCorrespondence
    {
        return DB::transaction(function () use ($actor, $scope, $data, $id): IncomingCorrespondence {
            if (DB::getDriverName() === 'sqlite') {
                DB::table('register_configurations')->whereIn('scope_id', AuthorizationScope::query()->where('scope_key', 'site:'.$scope->siteId)->select('id'))->where('register_key', 'incoming')->update(['id' => DB::raw('id')]);
            }
            $this->authorizeContext($actor, $scope, 'incoming.drafts.manage');
            $record = $id !== null ? $this->find($actor, $scope, $id, true) : null;
            if ($record?->isFormallyRegistered()) {
                throw ValidationException::withMessages(['incoming' => 'incomingImmutable']);
            }
            $validated = $this->validate($data, $record === null);
            $register = RegisterConfiguration::query()->where('scope_id', $scope->authorizationScope()->id)->where('register_key', 'incoming')->firstOrFail();
            $type = DocumentType::query()->where('scope_id', $register->scope_id)->find($validated['type_id']);
            if (! $type || ! $type->effectiveFor($register)) {
                throw ValidationException::withMessages(['type_id' => 'incomingInvalidType']);
            }
            if (! $record) {
                $profile = new DocumentSecurityProfile([
                    'resource_type' => DocumentResourceType::Incoming, 'resource_key' => (string) Str::uuid(),
                    'company_id' => $scope->companyId, 'site_id' => $scope->siteId, 'department_id' => $scope->departmentId,
                    'classification' => $validated['classification'], 'restricted' => $validated['classification'] === Confidentiality::HighlyConfidential->value, 'active' => true,
                ]);
                $profile->created_by = $actor->id;
                $profile->updated_by = $actor->id;
                if (! $profile->save()) {
                    throw new LogicException('Profile persistence cancelled.');
                }
                $securityAudit = DocumentSecurityAudit::query()->create(['actor_id' => $actor->id, 'profile_id' => $profile->id, 'event' => 'profile.registered', 'after' => $profile->getAttributes()]);
                if (! $securityAudit->exists) {
                    throw new LogicException('Profile audit persistence cancelled.');
                }
                $record = new IncomingCorrespondence(['scope_id' => $register->scope_id, 'register_id' => $register->id, 'profile_id' => $profile->id, 'created_by' => $actor->id, 'status' => 'draft']);
                $record->setRelation('profile', $profile);
                abort_unless($this->canRead($actor, $record), 403, 'rbacForbidden');
            }
            unset($validated['classification']);
            $record->fill($validated);
            $record->updated_by = $actor->id;
            $changed = array_keys($record->getDirty());
            $creating = ! $record->exists;
            if (! $record->save()) {
                throw new LogicException('Incoming persistence cancelled.');
            }
            $this->audit($actor, $record, $creating ? 'created' : 'updated', $changed);

            return $record->refresh();
        }, 1);
    }

    public function register(User $actor, DocumentScope $scope, string $id): IncomingCorrespondence
    {
        return DB::transaction(function () use ($actor, $scope, $id): IncomingCorrespondence {
            // Acquire the allocator's SQLite writer boundary before any authorization reads.
            if (DB::getDriverName() === 'sqlite') {
                DB::table('numbering_series')->whereIn('scope_id', AuthorizationScope::query()->where('scope_key', 'site:'.$scope->siteId)->select('id'))->update(['id' => DB::raw('id')]);
            }
            $this->authorizeContext($actor, $scope, 'incoming.register');
            $canonical = $scope->authorizationScope();
            $series = NumberingSeries::query()->where('scope_id', $canonical->id)->where('purpose', NumberingPurpose::InternalRegister->value)
                ->whereHas('register', fn (Builder $query): Builder => $query->where('register_key', 'incoming'))->first();
            if (! $series) {
                throw ValidationException::withMessages(['incoming' => 'incomingNumberingUnavailable']);
            }
            $record = null;
            $issued = $this->numbering->register($actor, $canonical, $series->id, function (string $number) use ($actor, $scope, $id, &$record): IncomingCorrespondence {
                // MySQL register/series locks precede the document lock, including duplicate requests.
                $record = $this->find($actor, $scope, $id, true);
                $this->authorizeContext($actor, $scope, 'incoming.register');
                if ($record->isFormallyRegistered()) {
                    throw ValidationException::withMessages(['incoming' => 'incomingAlreadyRegistered']);
                }
                $register = RegisterConfiguration::query()->findOrFail($record->register_id);
                if (! $record->type->effectiveFor($register)) {
                    throw ValidationException::withMessages(['type_id' => 'incomingInvalidType']);
                }
                $record->status = 'registered';
                $record->internal_number = $number;
                $record->registered_by = $actor->id;
                $record->registered_at = now();
                $record->updated_by = $actor->id;
                if (! $record->save()) {
                    throw new LogicException('Registration persistence cancelled.');
                }
                $this->audit($actor, $record, 'registered', ['status', 'internal_number', 'registered_by', 'registered_at']);

                return $record;
            });
            DB::table('incoming_registration_links')->insert(['correspondence_id' => $record->id, 'issuance_id' => $issued->id]);

            return $record->refresh();
        }, 1);
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function validate(array $data, bool $creating): array
    {
        $rules = [
            'type_id' => ['required', 'integer'], 'subject' => ['required', 'string', 'max:500'],
            'sender_organization' => ['required', 'string', 'max:255'], 'sender_contact' => ['nullable', 'string', 'max:255'],
            'sender_email' => ['nullable', 'email', 'max:255'], 'sender_phone' => ['nullable', 'string', 'max:64'],
            'external_reference' => ['nullable', 'string', 'max:255'],
            'correspondence_date' => ['required', 'date_format:Y-m-d'], 'received_date' => ['required', 'date_format:Y-m-d'],
            'description_en' => ['nullable', 'string', 'max:5000'], 'description_lo' => ['nullable', 'string', 'max:5000'],
            'priority' => ['required', Rule::in(['normal', 'urgent'])],
            'channel' => [$creating ? 'required' : 'sometimes', Rule::in(['email', 'courier', 'hand_delivery', 'post', 'other'])],
            'classification' => $creating ? ['required', Rule::enum(Confidentiality::class)] : ['prohibited'],
        ];
        foreach (['scope_id', 'profile_id', 'register_id', 'category_id', 'internal_number', 'status', 'registered_by', 'registered_at', 'created_by', 'updated_by', 'issuance_id', 'id'] as $field) {
            $rules[$field] = ['prohibited'];
        }

        return Validator::make($data, $rules, array_fill_keys(['required', 'string', 'max', 'integer', 'email', 'date_format', 'in', 'enum', 'prohibited'], 'incomingValidation'))->validate();
    }

    /** @param list<string> $changed */
    private function audit(User $actor, IncomingCorrespondence $record, string $action, array $changed): void
    {
        $audit = IncomingCorrespondenceAudit::query()->create(['correspondence_id' => $record->id, 'actor_id' => $actor->id, 'action' => $action, 'changed_fields' => $changed]);
        if (! $audit->exists) {
            throw new LogicException('Incoming audit persistence cancelled.');
        }
    }
}
