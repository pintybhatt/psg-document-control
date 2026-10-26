<?php

namespace App\Authorization;

use App\Models\IncomingCorrespondence;
use App\Models\OutgoingCorrespondence;
use App\Models\OutgoingDelivery;
use App\Models\OutgoingOperation;
use App\Models\OutgoingOperationAudit;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use LogicException;

class ManageOutgoingOperations
{
    public function __construct(private ManageOutgoingCorrespondence $outgoing, private ManageIncomingCorrespondence $incoming, private CorrectCorrespondenceDetails $corrections) {}

    /** @return array<string, mixed> */
    public function state(OutgoingCorrespondence $record): array
    {
        $operation = $record->operation;

        return ['status' => $operation?->status ?? 'registered', 'revision' => $operation?->revision ?? 0,
            'dispatch_date' => $operation?->dispatch_date?->format('Y-m-d'), 'dispatch_channel' => $operation?->dispatch_channel,
            'dispatch_reference' => $operation?->dispatch_reference, 'dispatch_remarks' => $operation?->dispatch_remarks,
            'closed_at' => $operation?->closed_at?->toISOString(), 'closed_by' => $operation?->closed_by, 'closure_remarks' => $operation?->closure_remarks];
    }

    /** @return array<string, mixed> */
    public function details(User $actor, OutgoingCorrespondence $record): array
    {
        $related = [];
        foreach (DB::table('outgoing_incoming_links')->where('outgoing_id', $record->id)->orderBy('incoming_id')->pluck('incoming_id') as $id) {
            $incoming = IncomingCorrespondence::query()->find($id);
            if ($incoming && $this->incoming->canRead($actor, $incoming)) {
                $related[] = $incoming->only(['id', 'subject', 'internal_number']);
            }
        }
        $history = OutgoingOperationAudit::query()->where('correspondence_id', $record->id)->orderBy('revision')->get();
        $actors = User::query()->whereIn('id', $history->pluck('actor_id'))->pluck('name', 'id');
        $history = $history->map(function (OutgoingOperationAudit $audit) use ($actor, $actors): array {
            $data = $audit->only(['id', 'actor_id', 'revision', 'action', 'note', 'before', 'after', 'created_at']);
            $data['actor_name'] = $actors->get($audit->actor_id);
            foreach (['before', 'after'] as $side) {
                if (isset($data[$side]['incoming_id'])) {
                    $incoming = IncomingCorrespondence::query()->find($data[$side]['incoming_id']);
                    unset($data[$side]['incoming_id']);
                    if ($incoming && $this->incoming->canRead($actor, $incoming)) {
                        $data[$side]['incoming_number'] = $incoming->internal_number;
                    }
                }
                if (isset($data[$side]['closed_by'])) {
                    $data[$side]['closed_by'] = User::query()->whereKey($data[$side]['closed_by'])->value('name');
                }
            }

            return $data;
        });

        return ['history' => $history, 'related_incoming' => $related];
    }

    /** @return list<array<string, mixed>> */
    public function options(User $actor, DocumentScope $scope, string $id, ?string $search): array
    {
        $this->outgoing->authorizeContext($actor, $scope, 'outgoing.operations.manage');
        $record = $this->outgoing->find($actor, $scope, $id);
        if (! $record->isFormallyRegistered()) {
            throw ValidationException::withMessages(['operation' => 'outgoingRegisteredOnly']);
        }
        $options = [];
        if ($search !== null && trim($search) !== '') {
            foreach ($this->incoming->query($scope)->where('status', 'registered')->with('profile')
                ->where(fn (Builder $query): Builder => $query->where('internal_number', 'like', '%'.$search.'%')->orWhere('subject', 'like', '%'.$search.'%'))->orderByDesc('id')->lazy() as $incoming) {
                if ($this->incoming->canRead($actor, $incoming)) {
                    $options[] = $incoming->only(['id', 'subject', 'internal_number']);
                    if (count($options) === 20) {
                        break;
                    }
                }
            }
        }

        return $options;
    }

    /** @param array<string, mixed> $input */
    public function act(User $actor, DocumentScope $scope, string $id, array $input): OutgoingCorrespondence
    {
        return DB::transaction(function () use ($actor, $scope, $id, $input): OutgoingCorrespondence {
            if (DB::getDriverName() === 'sqlite') {
                DB::table('outgoing_operations')->whereRaw('0 = 1')->update(['revision' => DB::raw('revision')]);
            }
            $this->outgoing->authorizeContext($actor, $scope, 'outgoing.operations.manage');
            $record = $this->outgoing->find($actor, $scope, $id, true);
            if (! $record->isFormallyRegistered()) {
                throw ValidationException::withMessages(['operation' => 'outgoingRegisteredOnly']);
            }
            $data = $this->validate($input);
            $operation = OutgoingOperation::query()->whereKey($record->id)->lockForUpdate()->first()
                ?? new OutgoingOperation(['correspondence_id' => $record->id, 'status' => 'registered', 'revision' => 0]);
            if ($operation->revision !== (int) $data['revision']) {
                abort(409, 'outgoingOperationStale');
            }
            $record->setRelation('operation', $operation);
            if ($data['action'] === 'correct_details') {
                return $this->corrections->correct($actor, $record, $operation, $data);
            }
            $before = $this->state($record);
            $extraBefore = [];
            $extraAfter = [];
            $action = $data['action'];
            if ($action === 'reopen') {
                $this->outgoing->authorizeContext($actor, $scope, 'outgoing.operations.reopen');
                if ($operation->status !== 'closed') {
                    throw ValidationException::withMessages(['operation' => 'outgoingInvalidTransition']);
                }
                $operation->fill(['status' => $operation->dispatch_date ? $this->deliveryStatus($record) : 'registered', 'closed_at' => null, 'closed_by' => null, 'closure_remarks' => null]);
            } else {
                if ($operation->status === 'closed') {
                    throw ValidationException::withMessages(['operation' => 'outgoingClosed']);
                }
                if (in_array($action, ['dispatch', 'correct_dispatch'], true)) {
                    $this->dispatch($record, $operation, $data);
                } elseif ($action === 'delivery') {
                    [$extraBefore, $extraAfter] = $this->delivery($record, $operation, $data);
                } elseif ($action === 'close') {
                    if ($operation->status === 'registered' && empty($data['note'])) {
                        throw ValidationException::withMessages(['note' => 'outgoingCloseReason']);
                    }
                    $operation->fill(['status' => 'closed', 'closed_at' => now(), 'closed_by' => $actor->id, 'closure_remarks' => $data['note'] ?? null]);
                } elseif (in_array($action, ['link', 'unlink'], true)) {
                    $incoming = $this->incoming->find($actor, $scope, (string) $data['incoming_id']);
                    if (! $incoming->isFormallyRegistered()) {
                        throw ValidationException::withMessages(['incoming_id' => 'outgoingInvalidIncoming']);
                    }
                    $pair = ['outgoing_id' => $record->id, 'incoming_id' => $incoming->id];
                    $exists = DB::table('outgoing_incoming_links')->where($pair)->exists();
                    if ($exists !== ($action === 'unlink')) {
                        throw ValidationException::withMessages(['incoming_id' => 'outgoingInvalidIncoming']);
                    }
                    if ($action === 'link') {
                        DB::table('outgoing_incoming_links')->insert($pair);
                        $extraAfter = ['incoming_id' => $incoming->id];
                    } else {
                        DB::table('outgoing_incoming_links')->where($pair)->delete();
                        $extraBefore = ['incoming_id' => $incoming->id];
                    }
                }
            }
            $operation->revision++;
            if (! $operation->save()) {
                throw new LogicException('Outgoing operation persistence cancelled.');
            }
            $after = $this->state($record);
            $changed = array_keys(array_filter($after, fn (mixed $value, string $key): bool => $key !== 'revision' && $value !== $before[$key], ARRAY_FILTER_USE_BOTH));
            $audit = OutgoingOperationAudit::query()->create(['correspondence_id' => $record->id, 'actor_id' => $actor->id, 'revision' => $operation->revision,
                'action' => $action, 'note' => $data['note'] ?? null, 'before' => array_intersect_key($before, array_flip($changed)) + $extraBefore,
                'after' => array_intersect_key($after, array_flip($changed)) + $extraAfter]);
            if (! $audit->exists) {
                throw new LogicException('Outgoing operational audit persistence cancelled.');
            }

            return $record->refresh();
        }, 1);
    }

    /** @param array<string, mixed> $data */
    private function dispatch(OutgoingCorrespondence $record, OutgoingOperation $operation, array $data): void
    {
        if (($data['action'] === 'dispatch') !== ($operation->status === 'registered')) {
            throw ValidationException::withMessages(['operation' => 'outgoingInvalidTransition']);
        }
        if ($data['dispatch_date'] === null) {
            if ($record->deliveries()->whereNotNull('delivery_date')->exists()) {
                throw ValidationException::withMessages(['dispatch_date' => 'outgoingClearDeliveriesFirst']);
            }
            if (array_filter(array_intersect_key($data, array_flip(['dispatch_channel', 'dispatch_reference', 'dispatch_remarks']))) !== []) {
                throw ValidationException::withMessages(['dispatch_date' => 'outgoingDispatchDateRequired']);
            }
            $operation->fill(['status' => 'registered', 'dispatch_date' => null, 'dispatch_channel' => null, 'dispatch_reference' => null, 'dispatch_remarks' => null]);

            return;
        }
        $earliest = $record->deliveries()->whereNotNull('delivery_date')->min('delivery_date');
        if ($earliest !== null && $data['dispatch_date'] > $earliest) {
            throw ValidationException::withMessages(['dispatch_date' => 'outgoingDeliveryDateOrder']);
        }
        $operation->fill(array_intersect_key($data, array_flip(['dispatch_date', 'dispatch_channel', 'dispatch_reference', 'dispatch_remarks'])));
        $operation->status = $data['action'] === 'dispatch' ? 'dispatched' : $this->deliveryStatus($record);
    }

    /** @param array<string, mixed> $data
     * @return array{array<string, mixed>, array<string, mixed>}
     */
    private function delivery(OutgoingCorrespondence $record, OutgoingOperation $operation, array $data): array
    {
        if (! in_array($operation->status, ['dispatched', 'delivered'], true)) {
            throw ValidationException::withMessages(['operation' => 'outgoingDispatchFirst']);
        }
        $recipient = $record->recipients()->findOrFail($data['recipient_id']);
        if ($data['delivery_date'] !== null && $data['delivery_date'] < $operation->dispatch_date->format('Y-m-d')) {
            throw ValidationException::withMessages(['delivery_date' => 'outgoingDeliveryDateOrder']);
        }
        $entry = OutgoingDelivery::query()->whereKey($recipient->id)->first()
            ?? new OutgoingDelivery(['recipient_id' => $recipient->id, 'correspondence_id' => $record->id]);
        $fields = ['delivery_date', 'acknowledged_by', 'acknowledgment_date', 'acknowledgment_reference'];
        $before = $entry->only($fields);
        $values = array_intersect_key($data, array_flip($fields)) + array_fill_keys($fields, null);
        if ($values['delivery_date'] === null && array_filter(array_diff_key($values, ['delivery_date' => true])) !== []) {
            throw ValidationException::withMessages(['delivery_date' => 'outgoingDeliveryRequired']);
        }
        $entry->fill($values);
        foreach ($fields as $field) {
            if ($entry->exists && $entry->getRawOriginal($field) !== null && $entry->isDirty($field) && empty($data['note'])) {
                throw ValidationException::withMessages(['note' => 'outgoingCorrectionReason']);
            }
        }
        if (! $entry->save()) {
            throw new LogicException('Delivery persistence cancelled.');
        }
        $operation->status = $this->deliveryStatus($record);

        return [['recipient' => $recipient->organization, 'delivery' => $before], ['recipient' => $recipient->organization, 'delivery' => $entry->only($fields)]];
    }

    private function deliveryStatus(OutgoingCorrespondence $record): string
    {
        return $record->recipients()->where('kind', 'primary')->whereNotIn('id', $record->deliveries()->whereNotNull('delivery_date')->select('recipient_id'))->exists() ? 'dispatched' : 'delivered';
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function validate(array $input): array
    {
        $action = $input['action'] ?? null;
        $rules = ['action' => ['required', Rule::in(['dispatch', 'correct_dispatch', 'delivery', 'close', 'reopen', 'note', 'link', 'unlink', 'correct_details'])],
            'revision' => ['required', 'integer', 'min:0'], 'note' => [in_array($action, ['correct_dispatch', 'reopen', 'note'], true) ? 'required' : 'nullable', 'string', 'max:2000']];
        if (in_array($action, ['dispatch', 'correct_dispatch'], true)) {
            $rules += ['dispatch_date' => $action === 'dispatch' ? ['required', 'date_format:Y-m-d', 'before_or_equal:today'] : ['present', 'nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
                'dispatch_channel' => [$action === 'dispatch' || ($input['dispatch_date'] ?? null) !== null ? 'required' : 'nullable', Rule::in(['email', 'courier', 'hand_delivery', 'post', 'other'])],
                'dispatch_reference' => ['nullable', 'string', 'max:255'], 'dispatch_remarks' => ['nullable', 'string', 'max:2000']];
        } elseif ($action === 'delivery') {
            $rules += ['recipient_id' => ['required', 'integer', 'min:1'], 'delivery_date' => ['present', 'nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
                'acknowledged_by' => ['nullable', 'string', 'max:255'], 'acknowledgment_reference' => ['nullable', 'string', 'max:255'],
                'acknowledgment_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:delivery_date', 'before_or_equal:today']];
        } elseif (in_array($action, ['link', 'unlink'], true)) {
            $rules['incoming_id'] = ['required', 'integer', 'min:1'];
        }
        if ($action === 'correct_details') {
            $rules += $this->corrections->rules(true);
            $rules['note'] = ['required', 'string', 'min:10', 'max:2000', 'regex:/\p{L}/u'];
            $input['note'] = isset($input['note']) && is_string($input['note']) ? trim($input['note']) : ($input['note'] ?? null);
        }
        foreach (array_diff(array_keys($input), array_keys($rules)) as $field) {
            $rules[$field] = ['prohibited'];
        }

        return Validator::make($input, $rules, array_fill_keys(['required', 'present', 'integer', 'min', 'string', 'max', 'date_format', 'before_or_equal', 'after_or_equal', 'in', 'prohibited', 'array', 'list', 'email', 'distinct', 'regex'], 'outgoingValidation'))->validate();
    }
}
