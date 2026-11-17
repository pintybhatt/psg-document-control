<?php

namespace App\Authorization;

use App\Models\AuthorizationScope;
use App\Models\DocumentSecurityAudit;
use App\Models\DocumentSecurityProfile;
use App\Models\DocumentType;
use App\Models\NumberingSeries;
use App\Models\OutgoingCorrespondence;
use App\Models\OutgoingCorrespondenceAudit;
use App\Models\OutgoingRecipient;
use App\Models\RegisterConfiguration;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use LogicException;

class ManageOutgoingCorrespondence
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

    /** @return Builder<OutgoingCorrespondence> */
    public function query(DocumentScope $scope): Builder
    {
        $canonical = $scope->authorizationScope();

        return OutgoingCorrespondence::query()->where('scope_id', $canonical->id)->whereHas('profile', fn (Builder $query): Builder => $query
            ->where('company_id', $scope->companyId)->where('site_id', $scope->siteId)->where('department_id', $scope->departmentId));
    }

    public function canRead(User $actor, OutgoingCorrespondence $record): bool
    {
        return $this->documents->allows($actor, DocumentSecurityDescriptor::fromProfile($record->profile), DocumentOperation::Metadata);
    }

    public function find(User $actor, DocumentScope $scope, string $id, bool $lock = false): OutgoingCorrespondence
    {
        $this->authorizeContext($actor, $scope);
        $record = $this->query($scope)->when($lock, fn (Builder $query): Builder => $query->lockForUpdate())->findOrFail($id);
        abort_unless($this->canRead($actor, $record), 404);

        return $record;
    }

    /** @param array<string, mixed> $data */
    public function save(User $actor, DocumentScope $scope, array $data, ?string $id = null): OutgoingCorrespondence
    {
        return DB::transaction(function () use ($actor, $scope, $data, $id): OutgoingCorrespondence {
            if (DB::getDriverName() === 'sqlite') {
                DB::table('register_configurations')->whereIn('scope_id', AuthorizationScope::query()->where('scope_key', 'site:'.$scope->siteId)->select('id'))->where('register_key', 'outgoing')->update(['id' => DB::raw('id')]);
            }
            $this->authorizeContext($actor, $scope, 'outgoing.drafts.manage');
            $record = $id !== null ? $this->find($actor, $scope, $id, true) : null;
            if ($record?->isFormallyRegistered()) {
                throw ValidationException::withMessages(['outgoing' => 'outgoingImmutable']);
            }
            $validated = $this->validate($data, $record === null);
            $register = RegisterConfiguration::query()->where('scope_id', $scope->authorizationScope()->id)->where('register_key', 'outgoing')->firstOrFail();
            $type = DocumentType::query()->where('scope_id', $register->scope_id)->find($validated['type_id']);
            if (! $type || ! $type->effectiveFor($register)) {
                throw ValidationException::withMessages(['type_id' => 'outgoingInvalidType']);
            }
            if (! $record) {
                $profile = new DocumentSecurityProfile([
                    'resource_type' => DocumentResourceType::Outgoing, 'resource_key' => (string) Str::uuid(),
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
                $record = new OutgoingCorrespondence(['scope_id' => $register->scope_id, 'register_id' => $register->id, 'profile_id' => $profile->id, 'created_by' => $actor->id, 'status' => 'draft']);
                $record->setRelation('profile', $profile);
                abort_unless($this->canRead($actor, $record), 403, 'rbacForbidden');
            }
            $recipients = $validated['recipients'];
            unset($validated['classification'], $validated['recipients']);
            $record->fill($validated);
            $record->updated_by = $actor->id;
            $changed = array_keys($record->getDirty());
            $creating = ! $record->exists;
            if (! $record->save()) {
                throw new LogicException('Outgoing persistence cancelled.');
            }
            $recipientFields = ['kind', 'organization', 'contact_name', 'email', 'phone', 'address'];
            $currentRecipients = $record->recipients()->orderBy('id')->get()->map(fn (OutgoingRecipient $recipient): array => array_map(fn (string $field): mixed => $recipient->{$field}, $recipientFields))->all();
            $nextRecipients = array_map(fn (array $recipient): array => array_map(fn (string $field): mixed => $recipient[$field] ?? null, $recipientFields), $recipients);
            $recipientsChanged = $currentRecipients !== $nextRecipients;
            if ($recipientsChanged) {
                $record->recipients()->delete();
                foreach ($recipients as $recipient) {
                    $recipient = new OutgoingRecipient($recipient);
                    $recipient->correspondence_id = $record->id;
                    if (! $recipient->save()) {
                        throw new LogicException('Recipient persistence cancelled.');
                    }
                }
            }
            $this->audit($actor, $record, $creating ? 'created' : 'updated', [...$changed, ...($recipientsChanged ? ['recipients'] : [])]);

            return $record->refresh();
        }, 1);
    }

    public function register(User $actor, DocumentScope $scope, string $id, ?int $namespaceId = null): OutgoingCorrespondence
    {
        return DB::transaction(function () use ($actor, $scope, $id, $namespaceId): OutgoingCorrespondence {
            XpplNumbering::lockCompany($scope->companyId);
            if (DB::getDriverName() === 'sqlite') {
                DB::table('numbering_series')->whereIn('scope_id', AuthorizationScope::query()->where('scope_key', 'site:'.$scope->siteId)->select('id'))->update(['id' => DB::raw('id')]);
            }
            $this->authorizeContext($actor, $scope, 'outgoing.register');
            $canonical = $scope->authorizationScope();
            $engine = app(XpplNumbering::class);
            $automatic = $engine->policyActive($scope->companyId, 'outgoing');
            if ($automatic) {
                app(AccessControl::class)->authorize($actor, 'numbering.issue', $canonical);
                if ($namespaceId !== null) {
                    throw ValidationException::withMessages(['namespace_id' => 'numberingXpplConflict']);
                }
                $draft = $this->find($actor, $scope, $id);
                if ($draft->isFormallyRegistered()) {
                    return $draft;
                }
                if ($scope->departmentId === null) {
                    throw ValidationException::withMessages(['outgoing' => 'outgoingXpplOriginRequired']);
                }
                if (! $draft->type->effectiveFor(RegisterConfiguration::query()->findOrFail($draft->register_id))) {
                    throw ValidationException::withMessages(['type_id' => 'outgoingInvalidType']);
                }
                $support = app(ManageNumbering::class)->forCorrespondencePolicy($actor, $scope, 'outgoing');
                $officialSupport = app(ManageNumbering::class)->forCorrespondencePolicy($actor, $scope, 'outgoing', NumberingPurpose::OfficialOutgoing);
                if ($draft->type->code !== 'LTR') {
                    $configuration = $officialSupport->currentConfiguration;
                    if (! $configuration?->active || $officialSupport->reset_policy !== NumberingResetPolicy::Annual || $configuration->padding !== 4
                        || str_replace('{prefix}', $configuration->prefix, $configuration->format['pattern'] ?? '') !== 'XPPL-OUT-{year2}-{sequence}') {
                        throw ValidationException::withMessages(['outgoing' => 'outgoingOfficialNumberingUnavailable']);
                    }
                }
                $engine->initializeInternalPolicy($actor, $scope, $support);
                if ($draft->type->code === 'LTR') {
                    $namespaceId = $engine->initializeLetterPolicy($actor, $scope, $draft->type_id, $officialSupport);
                }
            }
            $series = NumberingSeries::query()->where('scope_id', $canonical->id)
                ->whereHas('register', fn (Builder $query): Builder => $query->where('register_key', 'outgoing'))->get()->keyBy(fn (NumberingSeries $series): string => $series->purpose->value);
            $internal = $series->get(NumberingPurpose::InternalRegister->value);
            $official = $series->get(NumberingPurpose::OfficialOutgoing->value);
            if (! $internal || ! $official || $internal->register_id !== $official->register_id) {
                throw ValidationException::withMessages(['outgoing' => 'outgoingNumberingUnavailable']);
            }
            $xppl = null;
            if ($namespaceId !== null) {
                $draft = $this->find($actor, $scope, $id);
                $xppl = ['namespace_id' => $namespaceId, 'department_id' => $draft->profile->department_id, 'type_id' => $draft->type_id];
            }
            $record = null;
            $officialIssuance = null;
            // Both callbacks persist one complete formal identity; neither number survives an outer rollback.
            $internalIssuance = $this->numbering->register($actor, $canonical, $internal->id, function (string $internalNumber) use ($actor, $scope, $canonical, $official, $id, &$record, &$officialIssuance, $xppl, $automatic): OutgoingCorrespondence {
                $officialIssuance = $this->numbering->register($actor, $canonical, $official->id, function (string $officialNumber) use ($actor, $scope, $id, $internalNumber, &$record, $xppl, $automatic): OutgoingCorrespondence {
                    if ($automatic && $xppl === null && ! preg_match('/^XPPL-OUT-'.now()->format('y').'-[0-9]{4,12}$/D', $officialNumber)) {
                        throw ValidationException::withMessages(['outgoing' => 'outgoingOfficialNumberingUnavailable']);
                    }
                    // Preserve register -> series -> correspondence lock ordering on every registration request.
                    $record = $this->find($actor, $scope, $id, true);
                    $this->authorizeContext($actor, $scope, 'outgoing.register');
                    if ($record->isFormallyRegistered()) {
                        throw ValidationException::withMessages(['outgoing' => 'outgoingAlreadyRegistered']);
                    }
                    $register = RegisterConfiguration::query()->findOrFail($record->register_id);
                    if (! $record->type->effectiveFor($register)) {
                        throw ValidationException::withMessages(['type_id' => 'outgoingInvalidType']);
                    }
                    if (! $record->recipients()->where('kind', 'primary')->exists()) {
                        throw ValidationException::withMessages(['recipients' => 'outgoingPrimaryRequired']);
                    }
                    app(DocumentApprovals::class)->requireApproved($actor, $record);
                    $record->fill(['status' => 'registered', 'internal_number' => $internalNumber, 'official_number' => $officialNumber,
                        'registered_by' => $actor->id, 'registered_at' => now(), 'updated_by' => $actor->id]);
                    if (! $record->save()) {
                        throw new LogicException('Outgoing registration persistence cancelled.');
                    }
                    $this->audit($actor, $record, 'registered', ['status', 'internal_number', 'official_number', 'registered_by', 'registered_at']);

                    return $record;
                }, $xppl);

                return $record;
            }, $automatic ? ['format' => 'xppl_outgoing'] : null);
            DB::table('outgoing_registration_links')->insert([
                ['correspondence_id' => $record->id, 'purpose' => NumberingPurpose::InternalRegister->value, 'issuance_id' => $internalIssuance->id],
                ['correspondence_id' => $record->id, 'purpose' => NumberingPurpose::OfficialOutgoing->value, 'issuance_id' => $officialIssuance->id],
            ]);

            return $record->refresh();
        }, 1);
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function validate(array $data, bool $creating): array
    {
        $rules = [
            'document_revision' => ['nullable', 'string', 'max:30'],
            'type_id' => ['required', 'integer'], 'subject' => ['required', 'string', 'max:500'],
            'signatory_name' => ['required', 'string', 'max:255'], 'signatory_title' => ['nullable', 'string', 'max:255'],
            'document_date' => ['required', 'date_format:Y-m-d'],
            'description_en' => ['nullable', 'string', 'max:5000'], 'description_lo' => ['nullable', 'string', 'max:5000'],
            'priority' => ['required', Rule::in(['normal', 'urgent'])],
            'classification' => $creating ? ['required', Rule::enum(Confidentiality::class)] : ['prohibited'],
            'recipients' => ['required', 'array', 'list', 'min:1', 'max:50'],
            'recipients.*' => ['required', 'array:kind,organization,contact_name,email,phone,address'],
            'recipients.*.kind' => ['required', Rule::in(['primary', 'copy'])],
            'recipients.*.organization' => ['required', 'string', 'max:255'],
            'recipients.*.contact_name' => ['nullable', 'string', 'max:255'],
            'recipients.*.email' => ['nullable', 'email', 'max:255'],
            'recipients.*.phone' => ['nullable', 'string', 'max:64'],
            'recipients.*.address' => ['nullable', 'string', 'max:500'],
        ];
        foreach (['scope_id', 'profile_id', 'register_id', 'category_id', 'internal_number', 'official_number', 'status', 'registered_by', 'registered_at', 'created_by', 'updated_by', 'issuance_id', 'id'] as $field) {
            $rules[$field] = ['prohibited'];
        }

        $validated = Validator::make($data, $rules, array_fill_keys(['required', 'string', 'max', 'min', 'array', 'list', 'integer', 'email', 'date_format', 'in', 'enum', 'prohibited'], 'outgoingValidation'))->validate();
        if (! in_array('primary', array_column($validated['recipients'], 'kind'), true)) {
            throw ValidationException::withMessages(['recipients' => 'outgoingPrimaryRequired']);
        }

        return $validated;
    }

    /** @param list<string> $changed */
    private function audit(User $actor, OutgoingCorrespondence $record, string $action, array $changed): void
    {
        $audit = OutgoingCorrespondenceAudit::query()->create(['correspondence_id' => $record->id, 'actor_id' => $actor->id, 'action' => $action, 'changed_fields' => $changed]);
        if (! $audit->exists) {
            throw new LogicException('Outgoing audit persistence cancelled.');
        }
    }
}
