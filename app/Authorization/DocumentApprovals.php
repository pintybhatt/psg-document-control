<?php

namespace App\Authorization;

use App\Models\ApprovalWorkflowConfiguration;
use App\Models\ControlledDocument;
use App\Models\ControlledRevision;
use App\Models\ControlledRevisionApproval;
use App\Models\CorrespondenceApproval;
use App\Models\DocumentApprovalSubmission;
use App\Models\IncomingCorrespondenceAudit;
use App\Models\OutgoingCorrespondenceAudit;
use App\Models\User;
use App\Models\UserRoleAssignment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use LogicException;

class DocumentApprovals
{
    public function __construct(private AccessControl $access) {}

    public function manager(string $register): ManageControlledDocuments|ManageIncomingCorrespondence|ManageOutgoingCorrespondence
    {
        return app(match ($register) {
            'controlled' => ManageControlledDocuments::class, 'incoming' => ManageIncomingCorrespondence::class,
            'outgoing' => ManageOutgoingCorrespondence::class, default => abort(404),
        });
    }

    private function kind(Model $record): string
    {
        return $record->profile->resource_type->value;
    }

    private function latest(Model $record, ?ControlledRevision $revision): ?DocumentApprovalSubmission
    {
        return DocumentApprovalSubmission::query()->where('profile_id', $record->profile_id)->where('revision_id', $revision?->id)->latest('id')->first();
    }

    private function configuration(Model $record): ?ApprovalWorkflowConfiguration
    {
        return ApprovalWorkflowConfiguration::query()->where('scope_id', $record->scope_id)->where('register_key', $this->kind($record))->latest('version')->first();
    }

    /** Snapshot content and edit generations, not lifecycle counters or number allocation. */
    private function content(Model $record, ?ControlledRevision $revision): array
    {
        if ($revision) {
            $metadata = $revision->only(['id', 'revision_number', 'title_en', 'title_lo', 'description_en', 'description_lo', 'owner_id', 'custodian_id', 'issue_date', 'effective_date', 'review_date', 'revision_reason', 'change_description', 'supersedes_id']);
            $metadata += $record->only(['document_code', 'type_id']);
            $edit = DB::table('controlled_document_audits')->where('revision_id', $revision->id)->whereIn('action', ['created', 'draft_updated', 'revision_prepared'])->max('id');
        } else {
            $metadata = $record->attributesToArray();
            foreach (['created_at', 'updated_at', 'created_by', 'updated_by', 'status', 'registered_by', 'registered_at', 'internal_number', 'official_number'] as $key) {
                unset($metadata[$key]);
            }
            if ($this->kind($record) === 'outgoing') {
                $metadata['recipients'] = $record->recipients()->orderBy('id')->get()->map(fn (Model $recipient): array => $recipient->only(['id', 'kind', 'organization', 'contact_name', 'email', 'phone', 'address']))->all();
            }
            $prefix = $this->kind($record);
            $edit = [DB::table($prefix.'_correspondence_audits')->where('correspondence_id', $record->id)->whereIn('action', ['created', 'updated'])->whereJsonDoesntContain('changed_fields', 'approval')->max('id'),
                DB::table($prefix.'_operation_audits')->where('correspondence_id', $record->id)->whereNotNull('after->details')->max('id')];
        }
        $files = DB::table('attachment_versions as v')->join('document_attachments as a', 'a.id', '=', 'v.attachment_id')
            ->where('a.profile_id', $record->profile_id)->where('a.revision_id', $revision?->id)->orderBy('v.attachment_id')->orderBy('v.version')
            ->get(['v.id', 'v.attachment_id', 'v.version', 'v.sha256'])->map(fn (object $file): array => (array) $file)->all();

        return ['metadata' => $metadata, 'edit' => $edit, 'files' => $files];
    }

    private function fingerprint(Model $record, ?ControlledRevision $revision): string
    {
        return hash('sha256', json_encode($this->content($record, $revision), JSON_THROW_ON_ERROR));
    }

    /** @return Collection<int, array<string, mixed>> */
    private function events(DocumentApprovalSubmission $submission): Collection
    {
        if ($submission->revision_id !== null) {
            return ControlledRevisionApproval::query()->where('revision_id', $submission->revision_id)->where('snapshot->workflow->submission_id', $submission->id)->orderBy('id')->get()
                ->map(fn (ControlledRevisionApproval $event): array => $event->only(['id', 'decision', 'actor_id', 'comments', 'recorded_at']) + ['level' => $event->snapshot['workflow']['level']]);
        }

        return CorrespondenceApproval::query()->where('submission_id', $submission->id)->orderBy('id')->get()->map(fn (CorrespondenceApproval $event): array => $event->toArray());
    }

    private function decisionPermission(Model $record): string
    {
        return $record instanceof ControlledDocument ? 'controlled.approvals.record' : $this->kind($record).'.operations.manage';
    }

    private function submissionPermission(Model $record): string
    {
        return $this->kind($record).($record instanceof ControlledDocument || ! $record->isFormallyRegistered() ? '.drafts.manage' : '.operations.manage');
    }

    private function permitted(User $actor, Model $record, string $permission): bool
    {
        return $this->access->allows($actor, $permission, $record->profile->documentScope()->authorizationScope(), $record->profile->department_id);
    }

    private function eligible(User $actor, Model $record, DocumentApprovalSubmission $submission, int $level): bool
    {
        if (in_array($actor->id, [...$submission->snapshot['authors'], $submission->submitted_by], true) || ! $this->permitted($actor, $record, $this->decisionPermission($record))) {
            return false;
        }
        $roleId = $submission->snapshot['levels'][$level - 1]['role']['id'] ?? null;
        $scope = $record->profile->documentScope()->authorizationScope();

        return UserRoleAssignment::query()->where('user_id', $actor->id)->where('role_id', $roleId)->where('active', true)->with(['role.scope', 'scope'])->get()
            ->contains(fn (UserRoleAssignment $assignment): bool => $assignment->role->active && $assignment->role->scope->isActive()
                && $assignment->role->scope->covers($assignment->scope) && $assignment->scope->covers($scope));
    }

    /** @return array<string, mixed> */
    public function state(User $actor, Model $record, ?ControlledRevision $revision = null): array
    {
        $submission = $this->latest($record, $revision);
        $configuration = $submission ? ApprovalWorkflowConfiguration::query()->findOrFail($submission->configuration_id) : $this->configuration($record);
        $events = $submission ? $this->events($submission) : collect();
        $approved = $events->where('decision', 'approved')->count();
        $status = ! $configuration?->enabled ? 'disabled' : ($submission === null ? 'not_submitted' : ($events->contains('decision', 'rejected') ? 'rejected' : ($approved === count($submission->snapshot['levels']) ? 'approved' : 'pending')));
        $hash = $this->fingerprint($record, $revision);
        if ($submission && $submission->content_hash !== $hash) {
            $status = 'content_changed';
        }
        $levels = $submission?->snapshot['levels'] ?? $configuration?->levels ?? [];
        if ($configuration?->enabled && (count($levels) < 1 || count($levels) > 3 || $configuration->sequence !== 'sequential')) {
            $status = 'invalid_configuration';
        }
        $writable = $revision ? $revision->released_at === null && in_array($record->status, ['draft', 'current'], true) : ($record->operation?->status !== 'closed');

        return ['status' => $status, 'submission_id' => $submission?->id, 'configuration_id' => $configuration?->id, 'configuration_version' => $configuration?->version,
            'levels' => $levels, 'current_level' => $status === 'pending' ? $approved + 1 : null,
            'content_hash' => $hash, 'expected_event_id' => $events->last()['id'] ?? 0, 'lock_version' => $record->lock_version,
            'can_submit' => $writable && $status !== 'disabled' && in_array($status, ['not_submitted', 'rejected', 'content_changed'], true) && $this->permitted($actor, $record, $this->submissionPermission($record)),
            'can_decide' => $writable && $status === 'pending' && $this->eligible($actor, $record, $submission, $approved + 1)];
    }

    /** @return array<string, mixed> */
    public function details(User $actor, Model $record, ?ControlledRevision $revision = null): array
    {
        $history = [];
        foreach (DocumentApprovalSubmission::query()->where('profile_id', $record->profile_id)->where('revision_id', $revision?->id)->orderBy('id')->get() as $submission) {
            $version = ApprovalWorkflowConfiguration::query()->findOrFail($submission->configuration_id)->version;
            foreach ($this->events($submission) as $event) {
                $history[] = $event + ['submission_id' => $submission->id, 'configuration_version' => $version];
            }
        }

        return ['data' => $this->state($actor, $record, $revision), 'history' => $history];
    }

    /** @return array{url: string, revision_id: ?int}|null */
    public function panel(User $actor, Model $record, ?ControlledRevision $revision = null): ?array
    {
        if (($revision?->released_at !== null && $this->latest($record, $revision) === null) || $this->state($actor, $record, $revision)['status'] === 'disabled') {
            return null;
        }

        return ['url' => route('api.v1.document-approvals.show', ['type' => $this->kind($record), 'record' => $record->id], false), 'revision_id' => $revision?->id];
    }

    /** Caller holds the parent document lock inside its existing lifecycle transaction. */
    public function requireApproved(User $actor, Model $record, ?ControlledRevision $revision = null): void
    {
        $state = $this->state($actor, $record, $revision);
        if (! in_array($state['status'], ['disabled', 'approved'], true)) {
            throw ValidationException::withMessages(['approval' => 'approvalRequired']);
        }
    }

    /**
     * Used by C1 so controlled decisions remain in their original canonical history.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, int>|null
     */
    public function prepareControlled(User $actor, ControlledDocument $record, ControlledRevision $revision, array $data): ?array
    {
        if ($this->state($actor, $record, $revision)['status'] === 'disabled') {
            return null;
        }

        return $this->prepare($actor, $record, $revision, $data);
    }

    /** @param array<string, mixed> $data @return array<string, int> */
    private function prepare(User $actor, Model $record, ?ControlledRevision $revision, array $data): array
    {
        $values = Validator::make($data, ['decision' => ['required', Rule::in(['submitted', 'approved', 'rejected'])],
            'content_hash' => ['required', 'string', 'size:64'], 'expected_event_id' => ['required', 'integer', 'min:0'],
            'comments' => ['nullable', 'string', 'max:5000']], array_fill_keys(['required', 'in', 'string', 'size', 'integer', 'min', 'max'], 'approvalValidation'))->validate();
        $state = $this->state($actor, $record, $revision);
        if ($values['content_hash'] !== $state['content_hash'] || (int) $values['expected_event_id'] !== $state['expected_event_id']) {
            throw ValidationException::withMessages(['approval' => 'approvalStale']);
        }
        if ($values['decision'] === 'submitted') {
            abort_unless($state['can_submit'], 403, 'approvalForbidden');
            $configuration = $this->configuration($record);
            if (! $configuration?->enabled) {
                $configuration = ApprovalWorkflowConfiguration::query()->findOrFail($state['configuration_id']);
            }
            $submission = new DocumentApprovalSubmission(['profile_id' => $record->profile_id, 'revision_id' => $revision?->id,
                'configuration_id' => $configuration->id, 'submitted_by' => $actor->id, 'content_hash' => $state['content_hash'],
                'snapshot' => ['levels' => $configuration->levels, 'content' => $this->content($record, $revision),
                    'authors' => array_values(array_unique(array_filter([$record->created_by, $revision?->created_by, $revision?->updated_by ?? $record->updated_by])))]]);
            $this->persist($submission);
            $level = 0;
        } else {
            if ($state['status'] !== 'pending') {
                throw ValidationException::withMessages(['approval' => 'approvalNotPending']);
            }
            abort_unless($state['can_decide'], 403, 'approvalForbidden');
            $submission = DocumentApprovalSubmission::query()->findOrFail($state['submission_id']);
            $level = $state['current_level'];
        }

        return ['submission_id' => $submission->id, 'configuration_id' => $submission->configuration_id,
            'configuration_version' => ApprovalWorkflowConfiguration::query()->findOrFail($submission->configuration_id)->version, 'level' => $level];
    }

    /** @param array<string, mixed> $data */
    public function act(User $actor, DocumentScope $scope, string $register, string $id, ?int $revisionId, array $data): void
    {
        abort_if($register !== 'controlled' && $revisionId !== null, 404);
        if ($register === 'controlled') {
            $this->manager($register)->recordApproval($actor, $scope, $id, (string) $revisionId, $data);

            return;
        }
        DB::transaction(function () use ($actor, $scope, $register, $id, $data): void {
            $manager = $this->manager($register);
            if (DB::getDriverName() === 'sqlite') {
                DB::table('register_configurations')->where('scope_id', $scope->authorizationScope()->id)->where('register_key', $register)->update(['id' => DB::raw('id')]);
            }
            $record = $manager->find($actor, $scope, $id, true);
            $manager->authorizeContext($actor, $scope, ($data['decision'] ?? '') === 'submitted' ? $this->submissionPermission($record) : $this->decisionPermission($record));
            if (array_diff(array_keys($data), ['decision', 'comments', 'content_hash', 'expected_event_id']) !== []) {
                throw ValidationException::withMessages(['approval' => 'approvalValidation']);
            }
            if ($record->operation?->status === 'closed') {
                throw ValidationException::withMessages(['approval' => 'approvalNotPending']);
            }
            $workflow = $this->prepare($actor, $record, null, $data);
            $event = new CorrespondenceApproval(['submission_id' => $workflow['submission_id'], 'level' => $workflow['level'], 'decision' => $data['decision'],
                'actor_id' => $actor->id, 'comments' => $data['comments'] ?? null, 'recorded_at' => now()]);
            $this->persist($event);
            $auditClass = $register === 'incoming' ? IncomingCorrespondenceAudit::class : OutgoingCorrespondenceAudit::class;
            $this->persist(new $auditClass(['correspondence_id' => $record->id, 'actor_id' => $actor->id, 'action' => 'updated', 'changed_fields' => ['approval', 'approval:'.$event->id]]));
        }, 1);
    }

    private function persist(Model $model): void
    {
        if (! $model->save()) {
            throw new LogicException('Approval evidence persistence cancelled.');
        }
    }
}
