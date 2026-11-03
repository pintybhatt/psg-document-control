<?php

namespace App\Authorization;

use App\Models\Department;
use App\Models\IncomingCorrespondence;
use App\Models\IncomingCorrespondenceOperation;
use App\Models\IncomingOperationAudit;
use App\Models\OutgoingCorrespondence;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use LogicException;

class ManageIncomingOperations
{
    public function __construct(private ManageIncomingCorrespondence $incoming, private MembershipAccess $memberships, private AccessControl $roles, private CorrectCorrespondenceDetails $corrections) {}

    /** @return array<string, mixed> */
    public function state(IncomingCorrespondence $record): array
    {
        $operation = $record->operation;

        return [
            'status' => $operation?->status ?? 'received', 'revision' => $operation?->revision ?? 0,
            'responsible_user_id' => $operation?->responsible_user_id,
            'responsible_department_id' => $operation?->responsible_department_id,
            'due_date' => $operation?->due_date?->format('Y-m-d'),
            'response_required' => $operation?->response_required ?? false,
            'response_completed' => $operation?->response_completed_at !== null,
            'response_completed_at' => $operation?->response_completed_at?->toISOString(),
            'response_completed_by' => $operation?->response_completed_by,
            'closed_at' => $operation?->closed_at?->toISOString(), 'closed_by' => $operation?->closed_by,
            'closure_remarks' => $operation?->closure_remarks,
        ];
    }

    /** @return array<string, mixed> */
    public function details(User $actor, IncomingCorrespondence $record): array
    {
        $history = IncomingOperationAudit::query()->where('correspondence_id', $record->id)->orderBy('revision')->get()->map(function (IncomingOperationAudit $audit) use ($actor): array {
            $data = $audit->only(['id', 'actor_id', 'action', 'revision', 'note', 'before', 'after', 'created_at']);
            $data['actor_name'] = User::query()->whereKey($audit->actor_id)->value('name');
            foreach (['before', 'after'] as $side) {
                foreach (['responsible_user_id', 'response_completed_by', 'closed_by'] as $field) {
                    if (isset($data[$side][$field])) {
                        $data[$side][$field] = User::query()->whereKey($data[$side][$field])->value('name');
                    }
                }
                if (isset($data[$side]['responsible_department_id'])) {
                    $data[$side]['responsible_department_id'] = Department::query()->find($data[$side]['responsible_department_id'])?->only(['name_en', 'name_lo']);
                }
            }
            foreach (['before', 'after'] as $side) {
                if (isset($data[$side]['related_id'])) {
                    $other = IncomingCorrespondence::query()->find($data[$side]['related_id']);
                    unset($data[$side]['related_id']);
                    if ($other && $this->incoming->canRead($actor, $other)) {
                        $data[$side]['related_number'] = $other->internal_number;
                    }
                }
            }

            return $data;
        });
        $state = $this->state($record);

        return ['history' => $history, 'related' => $this->related($actor, $record), 'related_outgoing' => $this->relatedOutgoing($actor, $record),
            'responsible_user' => User::query()->find($state['responsible_user_id'])?->only(['id', 'name']),
            'responsible_department' => Department::query()->find($state['responsible_department_id'])?->only(['id', 'name_en', 'name_lo'])];
    }

    /** @return list<array<string, mixed>> */
    public function related(User $actor, IncomingCorrespondence $record): array
    {
        $related = [];
        foreach ($this->relations($record->id)->get() as $link) {
            $other = IncomingCorrespondence::query()->find($link->correspondence_id === $record->id ? $link->related_id : $link->correspondence_id);
            if ($other && $this->incoming->canRead($actor, $other)) {
                $related[] = $other->only(['id', 'subject', 'internal_number']);
            }
        }

        return $related;
    }

    /** @return list<array<string, mixed>> */
    public function relatedOutgoing(User $actor, IncomingCorrespondence $record): array
    {
        $manager = app(ManageOutgoingCorrespondence::class);

        return OutgoingCorrespondence::query()->whereIn('id', DB::table('outgoing_incoming_links')->where('incoming_id', $record->id)->select('outgoing_id'))
            ->with('profile')->orderBy('id')->get()
            ->filter(fn (OutgoingCorrespondence $outgoing): bool => $manager->canRead($actor, $outgoing))
            ->map(fn (OutgoingCorrespondence $outgoing): array => $outgoing->only(['id', 'internal_number', 'official_number', 'subject']))->values()->all();
    }

    /** @return array<string, mixed> */
    public function options(User $actor, DocumentScope $scope, string $id, ?string $search = null): array
    {
        $this->incoming->authorizeContext($actor, $scope, 'incoming.operations.manage');
        $record = $this->incoming->find($actor, $scope, $id);
        abort_unless($record->isFormallyRegistered(), 422, 'incomingRegisteredOnly');
        $members = $this->memberships->effectiveQuery()->where('company_id', $scope->companyId)->where('site_id', $scope->siteId)
            ->where('department_id', $scope->departmentId)->select('user_id');
        $users = User::query()->where('active', true)->where(fn (Builder $query): Builder => $query->whereIn('id', $members)
            ->orWhereHas('roleAssignments', fn (Builder $assignment): Builder => $assignment->where('active', true)
                ->whereHas('scope', fn (Builder $scope): Builder => $scope->where('scope_key', 'global'))
                ->whereHas('role', fn (Builder $role): Builder => $role->where('code', 'SECURITY_ADMINISTRATOR')->where('active', true))))
            ->orderBy('name')->get()->filter(fn (User $user): bool => $this->incoming->canRead($user, $record))->map(fn (User $user): array => $user->only(['id', 'name']))->values();
        $departments = Department::query()->where('company_id', $scope->companyId)->where('site_id', $scope->siteId)->where('active', true)->orderBy('name_en')->get(['id', 'name_en', 'name_lo']);

        $related = [];
        if ($search !== null && trim($search) !== '') {
            foreach ($this->incoming->query($scope)->where('status', 'registered')->whereKeyNot($record->id)
                ->where(fn (Builder $query): Builder => $query->where('internal_number', 'like', '%'.$search.'%')->orWhere('subject', 'like', '%'.$search.'%'))
                ->with('profile')->orderByDesc('id')->lazy() as $candidate) {
                if ($this->incoming->canRead($actor, $candidate)) {
                    $related[] = $candidate->only(['id', 'subject', 'internal_number']);
                    if (count($related) === 20) {
                        break;
                    }
                }
            }
        }

        return ['users' => $users, 'departments' => $departments, 'related' => $related];
    }

    /** @param array<string, mixed> $input */
    public function act(User $actor, DocumentScope $scope, string $id, array $input): IncomingCorrespondence
    {
        return DB::transaction(function () use ($actor, $scope, $id, $input): IncomingCorrespondence {
            if (DB::getDriverName() === 'sqlite') {
                DB::table('incoming_correspondence_operations')->whereRaw('0 = 1')->update(['revision' => DB::raw('revision')]);
            }
            $this->incoming->authorizeContext($actor, $scope, 'incoming.operations.manage');
            $record = $this->incoming->find($actor, $scope, $id, true);
            $data = $this->validate($input);
            if (! $record->isFormallyRegistered()) {
                throw ValidationException::withMessages(['operation' => 'incomingRegisteredOnly']);
            }
            $operation = IncomingCorrespondenceOperation::query()->whereKey($record->id)->lockForUpdate()->first()
                ?? new IncomingCorrespondenceOperation(['correspondence_id' => $record->id, 'status' => 'received', 'response_required' => false, 'revision' => 0]);
            if ($data['revision'] !== $operation->revision) {
                abort(409, 'incomingOperationStale');
            }
            $action = $data['action'];
            $record->setRelation('operation', $operation);
            if ($data['action'] === 'correct_details') {
                return $this->corrections->correct($actor, $record, $operation, $data);
            }
            $before = $this->state($record);
            if ($action === 'reopen') {
                $this->incoming->authorizeContext($actor, $scope, 'incoming.operations.reopen');
                if ($operation->status !== 'closed') {
                    throw ValidationException::withMessages(['operation' => 'incomingInvalidTransition']);
                }
                $operation->fill(['status' => $operation->response_completed_at ? 'responded' : ($operation->responsible_user_id || $operation->responsible_department_id ? 'assigned' : 'received'), 'closed_at' => null, 'closed_by' => null, 'closure_remarks' => null]);
            } else {
                if ($operation->status === 'closed') {
                    throw ValidationException::withMessages(['operation' => 'incomingClosed']);
                }
                if ($action === 'update') {
                    $this->update($actor, $scope, $record, $operation, $data);
                } elseif ($action === 'close') {
                    if ($operation->response_required && ! $operation->response_completed_at) {
                        throw ValidationException::withMessages(['operation' => 'incomingResponsePending']);
                    }
                    $operation->fill(['status' => 'closed', 'closed_at' => now(), 'closed_by' => $actor->id, 'closure_remarks' => $data['note'] ?? null]);
                } elseif (in_array($action, ['link', 'unlink'], true)) {
                    $this->link($actor, $scope, $record, $data['related_id'], $action === 'link');
                }
            }
            $operation->revision++;
            if (! $operation->save()) {
                throw new LogicException('Operation persistence cancelled.');
            }
            $after = $this->state($record);
            $changed = array_keys(array_filter($after, fn (mixed $value, string $key): bool => $key !== 'revision' && $value !== $before[$key], ARRAY_FILTER_USE_BOTH));
            $before = array_intersect_key($before, array_flip($changed));
            $after = array_intersect_key($after, array_flip($changed));
            if (in_array($action, ['link', 'unlink'], true)) {
                if ($action === 'link') {
                    $after['related_id'] = $data['related_id'];
                } else {
                    $before['related_id'] = $data['related_id'];
                }
            }
            $audit = IncomingOperationAudit::query()->create(['correspondence_id' => $record->id, 'actor_id' => $actor->id,
                'revision' => $operation->revision, 'action' => match ($action) {
                    'update' => 'updated', 'note' => 'noted', 'link' => 'linked', 'unlink' => 'unlinked', 'close' => 'closed', 'reopen' => 'reopened'
                },
                'note' => $data['note'] ?? null, 'before' => $before, 'after' => $after]);
            if (! $audit->exists) {
                throw new LogicException('Operational audit persistence cancelled.');
            }

            return $record->refresh();
        }, 1);
    }

    /** @param array<string, mixed> $data */
    private function update(User $actor, DocumentScope $scope, IncomingCorrespondence $record, IncomingCorrespondenceOperation $operation, array $data): void
    {
        $operation->fill(array_intersect_key($data, array_flip(['responsible_user_id', 'responsible_department_id', 'due_date', 'response_required', 'status'])));
        if ($operation->responsible_department_id !== null && ! Department::query()->whereKey($operation->responsible_department_id)->where('company_id', $scope->companyId)->where('site_id', $scope->siteId)->where('active', true)->exists()) {
            throw ValidationException::withMessages(['responsible_department_id' => 'incomingInvalidResponsibility']);
        }
        if ($operation->responsible_user_id !== null) {
            $user = User::query()->where('active', true)->find($operation->responsible_user_id);
            if (! $user || ! $this->incoming->canRead($user, $record) || ($operation->responsible_department_id !== null
                && ! $this->roles->isSystemAdministrator($user) && ! $this->memberships->matches($user, $scope->authorizationScope(), $operation->responsible_department_id))) {
                throw ValidationException::withMessages(['responsible_user_id' => 'incomingInvalidResponsibility']);
            }
        }
        $complete = $data['response_completed'] ?? ($operation->response_completed_at !== null);
        if (! $operation->response_required) {
            $complete = false;
        }
        if ($operation->response_completed_at && ! $complete && empty($data['note'])) {
            throw ValidationException::withMessages(['note' => 'incomingCorrectionReason']);
        }
        if ($complete && ! $operation->response_completed_at) {
            $operation->response_completed_at = now();
            $operation->response_completed_by = $actor->id;
            $operation->status = 'responded';
        } elseif (! $complete) {
            $operation->response_completed_at = null;
            $operation->response_completed_by = null;
            if ($operation->status === 'responded' && ! isset($data['status'])) {
                $operation->status = 'in_progress';
            }
        }
        if ($operation->status === 'received' && ($operation->responsible_user_id || $operation->responsible_department_id) && ! isset($data['status'])) {
            $operation->status = 'assigned';
        }
        if (($operation->status === 'assigned' && ! $operation->responsible_user_id && ! $operation->responsible_department_id)
            || ($operation->status === 'responded' && ! $operation->response_completed_at)
            || (($data['response_completed'] ?? false) && ! $operation->response_required)) {
            throw ValidationException::withMessages(['operation' => 'incomingInvalidTransition']);
        }
        if (isset($data['status']) && in_array($data['status'], ['received', 'assigned'], true) && in_array($operation->getRawOriginal('status'), ['in_progress', 'responded'], true) && empty($data['note'])) {
            throw ValidationException::withMessages(['note' => 'incomingCorrectionReason']);
        }
    }

    private function link(User $actor, DocumentScope $scope, IncomingCorrespondence $record, int $relatedId, bool $adding): void
    {
        $other = $this->incoming->find($actor, $scope, (string) $relatedId);
        if ($other->id === $record->id || ! $other->isFormallyRegistered()) {
            throw ValidationException::withMessages(['related_id' => 'incomingInvalidRelation']);
        }
        $pair = ['correspondence_id' => min($record->id, $other->id), 'related_id' => max($record->id, $other->id)];
        if ($adding) {
            if (DB::table('incoming_correspondence_relations')->where($pair)->exists()) {
                throw ValidationException::withMessages(['related_id' => 'incomingRelationExists']);
            }
            DB::table('incoming_correspondence_relations')->insert($pair);
        } else {
            DB::table('incoming_correspondence_relations')->where($pair)->delete();
        }
    }

    private function relations(int $id): \Illuminate\Database\Query\Builder
    {
        return DB::table('incoming_correspondence_relations')->where('correspondence_id', $id)->orWhere('related_id', $id)->orderBy('correspondence_id')->orderBy('related_id');
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function validate(array $input): array
    {
        $action = $input['action'] ?? null;
        $rules = ['action' => ['required', Rule::in(['update', 'note', 'link', 'unlink', 'close', 'reopen', 'correct_details'])], 'revision' => ['required', 'integer', 'min:0'],
            'note' => [in_array($action, ['note', 'reopen'], true) ? 'required' : 'nullable', 'string', 'max:2000']];
        if ($action === 'update') {
            $rules += ['responsible_user_id' => ['sometimes', 'nullable', 'integer'], 'responsible_department_id' => ['sometimes', 'nullable', 'integer'],
                'due_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'], 'status' => ['sometimes', Rule::in(['received', 'assigned', 'in_progress', 'responded'])],
                'response_required' => ['sometimes', 'boolean'], 'response_completed' => ['sometimes', 'boolean']];
        }
        if (in_array($action, ['link', 'unlink'], true)) {
            $rules['related_id'] = ['required', 'integer', 'min:1'];
        }
        if ($action === 'correct_details') {
            $rules += $this->corrections->rules(false);
            $rules['note'] = ['required', 'string', 'min:10', 'max:2000', 'regex:/\p{L}/u'];
            $input['note'] = isset($input['note']) && is_string($input['note']) ? trim($input['note']) : ($input['note'] ?? null);
        }
        foreach (array_diff(array_keys($input), array_keys($rules)) as $field) {
            $rules[$field] = ['prohibited'];
        }
        $data = Validator::make($input, $rules, array_fill_keys(['required', 'integer', 'min', 'string', 'max', 'in', 'boolean', 'date_format', 'prohibited', 'array', 'list', 'email', 'distinct', 'regex'], 'incomingValidation'))->validate();
        $data['revision'] = (int) $data['revision'];
        foreach (['related_id', 'responsible_user_id', 'responsible_department_id'] as $field) {
            if (isset($data[$field])) {
                $data[$field] = (int) $data[$field];
            }
        }
        foreach (['response_required', 'response_completed'] as $field) {
            if (isset($data[$field])) {
                $data[$field] = (bool) $data[$field];
            }
        }

        return $data;
    }
}
