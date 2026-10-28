<?php

namespace App\Authorization;

use App\Models\AuthorizationScope;
use App\Models\ControlledDocument;
use App\Models\ControlledDocumentAudit;
use App\Models\ControlledDocumentReview;
use App\Models\ControlledRevision;
use App\Models\ControlledRevisionApproval;
use App\Models\DocumentSecurityAudit;
use App\Models\DocumentSecurityProfile;
use App\Models\DocumentType;
use App\Models\NumberingSeries;
use App\Models\RegisterConfiguration;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use LogicException;

class ManageControlledDocuments
{
    public function __construct(private AccessControl $roles, private DocumentAccess $documents, private DocumentNumbering $numbering, private MembershipAccess $memberships) {}

    public function authorizeContext(User $actor, DocumentScope $scope, ?string $permission = null): void
    {
        abort_unless($scope->isActive(), 403, 'rbacForbidden');
        $canonical = $scope->authorizationScope();
        abort_unless(($permission === null || $this->roles->allows($actor, $permission, $canonical, $scope->departmentId))
            && in_array('documents.metadata.view', $this->roles->grantedPermissions($actor, $canonical), true)
            && ($this->roles->isSystemAdministrator($actor) || $this->memberships->matches($actor, $canonical, $scope->departmentId)), 403, 'rbacForbidden');
    }

    /** @return Builder<ControlledDocument> */
    public function query(DocumentScope $scope): Builder
    {
        return ControlledDocument::query()->where('scope_id', $scope->authorizationScope()->id)
            ->whereHas('profile', fn (Builder $query): Builder => $query->where('company_id', $scope->companyId)->where('site_id', $scope->siteId)->where('department_id', $scope->departmentId));
    }

    public function canRead(User $actor, ControlledDocument $record): bool
    {
        return $this->documents->allows($actor, DocumentSecurityDescriptor::fromProfile($record->profile), DocumentOperation::Metadata);
    }

    public function find(User $actor, DocumentScope $scope, string $id, bool $lock = false): ControlledDocument
    {
        $this->authorizeContext($actor, $scope);
        $record = $this->query($scope)->when($lock, fn (Builder $query): Builder => $query->lockForUpdate())->findOrFail($id);
        abort_unless($this->canRead($actor, $record), 404);

        return $record;
    }

    /** All writes use register -> series (registration only) -> document -> revision lock order. */
    private function lockRegister(DocumentScope $scope): RegisterConfiguration
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::table('register_configurations')->whereIn('scope_id', AuthorizationScope::query()->where('scope_key', 'site:'.$scope->siteId)->select('id'))->where('register_key', 'controlled')->update(['id' => DB::raw('id')]);
        }

        return RegisterConfiguration::query()->where('scope_id', $scope->authorizationScope()->id)->where('register_key', 'controlled')->lockForUpdate()->firstOrFail();
    }

    /** @param array<string, mixed> $data */
    public function save(User $actor, DocumentScope $scope, array $data, ?string $id = null): ControlledDocument
    {
        return DB::transaction(function () use ($actor, $scope, $data, $id): ControlledDocument {
            $register = $this->lockRegister($scope);
            $this->authorizeContext($actor, $scope, 'controlled.drafts.manage');
            $record = $id === null ? null : $this->find($actor, $scope, $id, true);
            if ($record) {
                $this->version($record, $data);
                $this->editable($record);
            }
            $rules = $this->revisionRules();
            if (! $record?->isFormallyRegistered()) {
                $rules += ['document_code' => ['required', 'string', 'max:100', 'regex:/^[A-Z0-9][A-Z0-9.\/-]*$/', Rule::unique('controlled_documents')->where('scope_id', $register->scope_id)->ignore($record?->id)], 'type_id' => ['required', 'integer']];
            }
            $rules['classification'] = $record ? ['prohibited'] : ['required', Rule::enum(Confidentiality::class)];
            $this->rejectUnknown($data, [...array_keys($rules), ...($record ? ['lock_version'] : [])]);
            $validated = Validator::make($data, $rules, $this->messages())->validate();
            $type = $record?->isFormallyRegistered() ? $record->type : DocumentType::query()->where('scope_id', $register->scope_id)->find($validated['type_id']);
            $this->applicable($type, $register);
            $this->validatePeople($scope, $validated);
            if (! $record) {
                $profile = new DocumentSecurityProfile(['resource_type' => DocumentResourceType::Controlled, 'resource_key' => (string) Str::uuid(),
                    'company_id' => $scope->companyId, 'site_id' => $scope->siteId, 'department_id' => $scope->departmentId,
                    'classification' => $validated['classification'], 'restricted' => $validated['classification'] === Confidentiality::HighlyConfidential->value, 'active' => true]);
                $profile->created_by = $profile->updated_by = $actor->id;
                $this->persist($profile);
                $this->persist(new DocumentSecurityAudit(['actor_id' => $actor->id, 'profile_id' => $profile->id, 'event' => 'profile.registered', 'after' => $profile->getAttributes()]));
                $record = new ControlledDocument(['scope_id' => $register->scope_id, 'register_id' => $register->id, 'profile_id' => $profile->id,
                    'document_code' => $validated['document_code'], 'type_id' => $type->id, 'status' => 'draft', 'lock_version' => 1, 'created_by' => $actor->id, 'updated_by' => $actor->id]);
                $record->setRelation('profile', $profile);
                abort_unless($this->canRead($actor, $record), 403, 'rbacForbidden');
                $this->persist($record);
                $revision = new ControlledRevision(['document_id' => $record->id, 'revision_number' => 0, 'draft_slot' => 1, 'created_by' => $actor->id]);
                $action = 'created';
            } else {
                $revision = $record->revisions()->whereNull('released_at')->lockForUpdate()->first();
                if (! $revision) {
                    throw ValidationException::withMessages(['controlled' => 'controlledNoDraft']);
                }
                if (! $record->isFormallyRegistered()) {
                    $record->fill(['document_code' => $validated['document_code'], 'type_id' => $type->id]);
                }
                $this->touch($record, $actor);
                $action = 'draft_updated';
            }
            unset($validated['classification'], $validated['document_code'], $validated['type_id']);
            $revision->fill($validated);
            $revision->updated_by = $actor->id;
            $changed = array_keys($revision->getDirty());
            $this->persist($revision);
            $this->audit($actor, $record, $revision, $action, $revision->revision_reason, $changed);

            return $record->refresh();
        }, 1);
    }

    /** @param array<string, mixed> $data */
    public function register(User $actor, DocumentScope $scope, string $id, array $data): ControlledDocument
    {
        $this->rejectUnknown($data, ['lock_version']);

        return DB::transaction(function () use ($actor, $scope, $id, $data): ControlledDocument {
            $register = $this->lockRegister($scope);
            $this->authorizeContext($actor, $scope, 'controlled.register');
            $series = NumberingSeries::query()->where('register_id', $register->id)->where('purpose', NumberingPurpose::InternalRegister)->first();
            if (! $series) {
                throw ValidationException::withMessages(['controlled' => 'controlledNumberingUnavailable']);
            }
            $record = null;
            $issuance = $this->numbering->register($actor, $scope->authorizationScope(), $series->id, function (string $number) use ($actor, $scope, $id, $data, $register, &$record): ControlledDocument {
                $record = $this->find($actor, $scope, $id, true);
                $this->version($record, $data);
                if ($record->isFormallyRegistered()) {
                    throw ValidationException::withMessages(['controlled' => 'controlledAlreadyRegistered']);
                }
                $this->applicable($record->type, $register);
                $record->fill(['internal_number' => $number, 'registered_at' => now(), 'registered_by' => $actor->id]);
                $this->touch($record, $actor);
                $this->audit($actor, $record, null, 'registered', null, ['internal_number', 'registered_at', 'registered_by']);

                return $record;
            });
            DB::table('controlled_registration_links')->insert(['document_id' => $record->id, 'issuance_id' => $issuance->id]);

            return $record->refresh();
        }, 1);
    }

    /** @param array<string, mixed> $data */
    public function revise(User $actor, DocumentScope $scope, string $id, array $data): ControlledDocument
    {
        $this->rejectUnknown($data, ['lock_version', 'revision_reason', 'change_description']);
        $validated = Validator::make($data, ['revision_reason' => ['required', 'string', 'max:5000'], 'change_description' => ['required', 'string', 'max:5000']], $this->messages())->validate();

        return DB::transaction(function () use ($actor, $scope, $id, $data, $validated): ControlledDocument {
            $this->lockRegister($scope);
            $this->authorizeContext($actor, $scope, 'controlled.drafts.manage');
            $record = $this->find($actor, $scope, $id, true);
            $this->version($record, $data);
            if ($record->status !== 'current' || $record->revisions()->whereNull('released_at')->exists()) {
                throw ValidationException::withMessages(['controlled' => 'controlledRevisionUnavailable']);
            }
            $revision = $record->currentRevision->replicate(['released_at', 'released_by', 'release_reason', 'owner_name', 'custodian_name']);
            $revision->fill($validated + ['revision_number' => $record->currentRevision->revision_number + 1, 'draft_slot' => 1, 'supersedes_id' => $record->current_revision_id, 'created_by' => $actor->id, 'updated_by' => $actor->id]);
            $this->persist($revision);
            $this->touch($record, $actor);
            $this->audit($actor, $record, $revision, 'revision_prepared', $validated['revision_reason'], ['revision_number', 'supersedes_id', 'change_description']);

            return $record->refresh();
        }, 1);
    }

    /** @param array<string, mixed> $data */
    public function release(User $actor, DocumentScope $scope, string $id, array $data): ControlledDocument
    {
        $this->rejectUnknown($data, ['lock_version', 'revision_id', 'reason']);
        $validated = Validator::make($data, ['revision_id' => ['required', 'integer'], 'reason' => ['required', 'string', 'max:5000']], $this->messages())->validate();

        return DB::transaction(function () use ($actor, $scope, $id, $data, $validated): ControlledDocument {
            $register = $this->lockRegister($scope);
            $this->authorizeContext($actor, $scope, 'controlled.release');
            $record = $this->find($actor, $scope, $id, true);
            $this->version($record, $data);
            $this->editable($record);
            if (! $record->isFormallyRegistered()) {
                throw ValidationException::withMessages(['controlled' => 'controlledRegistrationRequired']);
            }
            $this->applicable($record->type, $register);
            $revision = $record->revisions()->whereKey($validated['revision_id'])->whereNull('released_at')->lockForUpdate()->first();
            if (! $revision || $revision->supersedes_id !== $record->current_revision_id) {
                throw ValidationException::withMessages(['controlled' => 'controlledNoDraft']);
            }
            $metadata = $revision->getAttributes();
            foreach (['issue_date', 'effective_date', 'review_date'] as $field) {
                $metadata[$field] = $revision->{$field}?->format('Y-m-d');
            }
            Validator::make($metadata, $this->revisionRules(), $this->messages())->validate();
            if ($revision->effective_date->isAfter(today())) {
                throw ValidationException::withMessages(['effective_date' => 'controlledEffectiveFuture']);
            }
            $this->validatePeople($scope, $revision->getAttributes());
            app(DocumentApprovals::class)->requireApproved($actor, $record, $revision);
            $revision->fill(['released_at' => now(), 'released_by' => $actor->id, 'release_reason' => $validated['reason'], 'draft_slot' => null,
                'owner_name' => $revision->owner->name, 'custodian_name' => $revision->custodian->name, 'updated_by' => $actor->id]);
            $this->persist($revision);
            $record->fill(['status' => 'current', 'current_revision_id' => $revision->id]);
            $this->touch($record, $actor);
            $this->audit($actor, $record, $revision, 'released', $validated['reason'], ['current_revision_id', 'released_at', 'released_by', 'status']);

            return $record->refresh();
        }, 1);
    }

    /**
     * Records evidence, enforcing a pinned sequential workflow when configured.
     * Each event snapshots its revision and attachment versions, so later edits do not
     * turn historical decisions into approval of newly edited content.
     *
     * @param  array<string, mixed>  $data
     */
    public function recordApproval(User $actor, DocumentScope $scope, string $id, string $revisionId, array $data): ControlledDocument
    {
        return DB::transaction(function () use ($actor, $scope, $id, $revisionId, $data): ControlledDocument {
            $this->lockRegister($scope);
            $permission = ($data['decision'] ?? null) === 'submitted' ? 'controlled.drafts.manage' : 'controlled.approvals.record';
            $this->authorizeContext($actor, $scope, $permission);
            $record = $this->find($actor, $scope, $id, true);
            $this->rejectUnknown($data, ['lock_version', 'decision', 'comments', 'content_hash', 'expected_event_id']);
            $validated = Validator::make($data, ['decision' => ['required', Rule::in(['submitted', 'approved', 'rejected'])],
                'comments' => ['nullable', 'string', 'max:5000']], $this->messages())->validate();
            $this->version($record, $data);
            $this->editable($record);
            $revision = $record->revisions()->whereKey($revisionId)->lockForUpdate()->firstOrFail();
            if ($revision->released_at !== null) {
                throw ValidationException::withMessages(['controlled' => 'controlledImmutable']);
            }
            $workflow = app(DocumentApprovals::class)->prepareControlled($actor, $record, $revision, $data);
            $files = DB::table('attachment_versions as v')->join('document_attachments as a', 'a.id', '=', 'v.attachment_id')
                ->where('a.profile_id', $record->profile_id)->where('a.revision_id', $revision->id)->orderBy('v.attachment_id')->orderBy('v.version')
                ->get(['v.id', 'v.attachment_id', 'v.version', 'v.sha256'])->map(fn (object $file): array => (array) $file)->all();
            $entry = new ControlledRevisionApproval(['revision_id' => $revision->id, 'actor_id' => $actor->id,
                'decision' => $validated['decision'], 'document_version' => $record->lock_version,
                'comments' => $validated['comments'] ?? null, 'recorded_at' => now(),
                'snapshot' => ['revision' => $revision->attributesToArray(), 'files' => $files] + ($workflow ? ['workflow' => $workflow] : [])]);
            $this->persist($entry);
            $this->touch($record, $actor);
            $this->audit($actor, $record, $revision, 'approval_'.$validated['decision'], $entry->comments, ['approval:'.$entry->id]);

            return $record->refresh();
        }, 1);
    }

    /** Record a review against the current released revision without changing its content.
     * @param  array<string, mixed>  $data
     */
    public function recordReview(User $actor, DocumentScope $scope, string $id, string $revisionId, array $data): ControlledDocument
    {
        return DB::transaction(function () use ($actor, $scope, $id, $revisionId, $data): ControlledDocument {
            $register = $this->lockRegister($scope);
            $this->authorizeContext($actor, $scope, 'controlled.reviews.record');
            $record = $this->find($actor, $scope, $id, true);
            $this->rejectUnknown($data, ['lock_version', 'outcome', 'comments']);
            $validated = Validator::make($data, ['outcome' => ['required', Rule::in(['no_changes_required', 'revision_required'])],
                'comments' => ['nullable', 'string', 'max:5000']], $this->messages())->validate();
            $this->version($record, $data);
            $revision = $record->revisions()->whereKey($revisionId)->lockForUpdate()->firstOrFail();
            if ($record->status !== 'current' || $record->current_revision_id !== $revision->id || $revision->released_at === null) {
                throw ValidationException::withMessages(['controlled' => 'controlledReviewCurrentOnly']);
            }
            $type = DocumentType::query()->where('scope_id', $record->scope_id)->lockForUpdate()->findOrFail($record->type_id);
            $previous = ControlledDocumentReview::query()->where('revision_id', $revision->id)->latest('id')->first();
            $next = $previous?->next_review_date ?? $revision->review_date;
            $timestamp = now();
            $calculate = $validated['outcome'] === 'no_changes_required' && $type->review_enabled && $type->effectiveFor($register)
                && $type->review_baseline === 'completion_date' && $type->review_interval_months >= 1 && $type->review_interval_months <= 1200;
            if ($calculate) {
                $next = $timestamp->copy()->addMonthsNoOverflow($type->review_interval_months);
            }
            $entry = new ControlledDocumentReview(['revision_id' => $revision->id, 'actor_id' => $actor->id,
                'document_version' => $record->lock_version, 'outcome' => $validated['outcome'], 'comments' => $validated['comments'] ?? null,
                'recorded_at' => $timestamp, 'interval_months' => $calculate ? $type->review_interval_months : null,
                'baseline' => $calculate ? $type->review_baseline : null, 'next_review_date' => $next?->format('Y-m-d')]);
            $this->persist($entry);
            $this->touch($record, $actor);
            $this->audit($actor, $record, $revision, 'review_completed', $entry->comments, ['review:'.$entry->id]);

            return $record->refresh();
        }, 1);
    }

    /** @param array<string, mixed> $data */
    public function changeStatus(User $actor, DocumentScope $scope, string $id, array $data): ControlledDocument
    {
        $this->rejectUnknown($data, ['lock_version', 'status', 'reason', 'replaced_by_id']);
        $validated = Validator::make($data, ['status' => ['required', Rule::in(['superseded', 'obsolete', 'archived'])], 'reason' => ['required', 'string', 'max:5000'], 'replaced_by_id' => ['nullable', 'integer', 'required_if:status,superseded', 'prohibited_unless:status,superseded']], $this->messages())->validate();

        return DB::transaction(function () use ($actor, $scope, $id, $data, $validated): ControlledDocument {
            $this->lockRegister($scope);
            $this->authorizeContext($actor, $scope, 'controlled.status.manage');
            $record = $this->find($actor, $scope, $id, true);
            $this->version($record, $data);
            if (! (($record->status === 'current' && in_array($validated['status'], ['obsolete', 'superseded'], true))
                || (in_array($record->status, ['obsolete', 'superseded'], true) && $validated['status'] === 'archived'))) {
                throw ValidationException::withMessages(['controlled' => 'controlledInvalidStatus']);
            }
            if ($validated['status'] === 'superseded') {
                $replacement = $this->find($actor, $scope, (string) $validated['replaced_by_id'], true);
                if ($replacement->id === $record->id || $replacement->status !== 'current') {
                    throw ValidationException::withMessages(['controlled' => 'controlledInvalidReplacement']);
                }
                $record->replaced_by_id = $replacement->id;
            }
            $record->status = $validated['status'];
            $this->touch($record, $actor);
            $this->audit($actor, $record, null, $validated['status'], $validated['reason'], ['status', 'replaced_by_id']);

            return $record->refresh();
        }, 1);
    }

    /** @return Builder<User> */
    public function people(DocumentScope $scope): Builder
    {
        return User::query()->where('active', true)->whereIn('id', $this->memberships->effectiveQuery()->where('membership_type', $scope->departmentId === null ? 'site' : 'department')
            ->where('company_id', $scope->companyId)->where('site_id', $scope->siteId)->where('department_id', $scope->departmentId)->select('user_id'));
    }

    /** @param array<string, mixed> $data */
    private function validatePeople(DocumentScope $scope, array $data): void
    {
        $ids = $this->people($scope)->whereIn('id', [$data['owner_id'], $data['custodian_id']])->pluck('id')->all();
        foreach (['owner_id', 'custodian_id'] as $field) {
            if (! in_array((int) $data[$field], $ids, true)) {
                throw ValidationException::withMessages([$field => 'controlledInvalidPerson']);
            }
        }
    }

    /** @return array<string, mixed> */
    private function revisionRules(): array
    {
        return ['title_en' => ['required', 'string', 'max:500'], 'title_lo' => ['nullable', 'string', 'max:500'],
            'description_en' => ['nullable', 'string', 'max:5000'], 'description_lo' => ['nullable', 'string', 'max:5000'],
            'owner_id' => ['required', 'integer'], 'custodian_id' => ['required', 'integer'],
            'issue_date' => ['required', 'date_format:Y-m-d'], 'effective_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:issue_date'],
            'review_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:effective_date'],
            'revision_reason' => ['required', 'string', 'max:5000'], 'change_description' => ['required', 'string', 'max:5000']];
    }

    /** @return array<string, string> */
    private function messages(): array
    {
        return array_fill_keys(['required', 'required_if', 'prohibited_unless', 'string', 'min', 'max', 'integer', 'regex', 'unique', 'date_format', 'after_or_equal', 'enum', 'in', 'prohibited'], 'controlledValidation');
    }

    /** @param array<string, mixed> $data
     * @param  list<string>  $allowed
     */
    private function rejectUnknown(array $data, array $allowed): void
    {
        if (array_diff(array_keys($data), $allowed) !== []) {
            throw ValidationException::withMessages(['controlled' => 'controlledValidation']);
        }
    }

    /** @param array<string, mixed> $data */
    private function version(ControlledDocument $record, array $data): void
    {
        Validator::make($data, ['lock_version' => ['required', 'integer', 'min:1']], $this->messages())->validate();
        if ((int) $data['lock_version'] !== $record->lock_version) {
            throw ValidationException::withMessages(['controlled' => 'controlledStale']);
        }
    }

    private function editable(ControlledDocument $record): void
    {
        if (! in_array($record->status, ['draft', 'current'], true)) {
            throw ValidationException::withMessages(['controlled' => 'controlledImmutable']);
        }
    }

    private function applicable(?DocumentType $type, RegisterConfiguration $register): void
    {
        if (! $type || ! $type->effectiveFor($register)) {
            throw ValidationException::withMessages(['type_id' => 'controlledInvalidType']);
        }
    }

    private function touch(ControlledDocument $record, User $actor): void
    {
        $record->lock_version++;
        $record->updated_by = $actor->id;
        $this->persist($record);
    }

    private function persist(Model $model): void
    {
        if (! $model->save()) {
            throw new LogicException('Controlled document persistence cancelled.');
        }
    }

    /** @param list<string> $changed */
    private function audit(User $actor, ControlledDocument $record, ?ControlledRevision $revision, string $action, ?string $reason, array $changed): void
    {
        $this->persist(new ControlledDocumentAudit(['document_id' => $record->id, 'revision_id' => $revision?->id, 'actor_id' => $actor->id,
            'action' => $action, 'reason' => $reason, 'changed_fields' => $changed]));
    }
}
