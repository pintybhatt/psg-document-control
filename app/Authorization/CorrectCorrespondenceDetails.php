<?php

namespace App\Authorization;

use App\Models\IncomingCorrespondence;
use App\Models\IncomingCorrespondenceOperation;
use App\Models\IncomingOperationAudit;
use App\Models\OutgoingCorrespondence;
use App\Models\OutgoingOperation;
use App\Models\OutgoingOperationAudit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use LogicException;

class CorrectCorrespondenceDetails
{
    /** @return array<string, array<mixed>> */
    public function rules(bool $outgoing): array
    {
        $fields = [
            'document_revision' => ['sometimes', 'nullable', 'string', 'max:30'],
            'subject' => ['sometimes', 'required', 'string', 'max:500'],
            'description_en' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'description_lo' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'priority' => ['sometimes', 'required', Rule::in(['normal', 'urgent'])],
        ];
        if ($outgoing) {
            $fields += ['document_date' => ['sometimes', 'required', 'date_format:Y-m-d'],
                'signatory_name' => ['sometimes', 'required', 'string', 'max:255'],
                'signatory_title' => ['sometimes', 'nullable', 'string', 'max:255'],
                'recipients' => ['sometimes', 'required', 'array', 'list', 'min:1', 'max:50']];
        } else {
            $fields += ['sender_organization' => ['sometimes', 'required', 'string', 'max:255'],
                'sender_contact' => ['sometimes', 'nullable', 'string', 'max:255'],
                'sender_email' => ['sometimes', 'nullable', 'email', 'max:255'],
                'sender_phone' => ['sometimes', 'nullable', 'string', 'max:64'],
                'external_reference' => ['sometimes', 'nullable', 'string', 'max:255'],
                'correspondence_date' => ['sometimes', 'required', 'date_format:Y-m-d'],
                'received_date' => ['sometimes', 'required', 'date_format:Y-m-d'],
                'channel' => ['sometimes', 'required', Rule::in(['email', 'courier', 'hand_delivery', 'post', 'other'])]];
        }
        $rules = ['details' => ['required', 'array:'.implode(',', array_keys($fields)), 'min:1']];
        foreach ($fields as $field => $fieldRules) {
            $rules['details.'.$field] = $fieldRules;
        }
        if ($outgoing) {
            $rules += ['details.recipients.*' => ['required', 'array:id,organization,contact_name,email,phone,address'],
                'details.recipients.*.id' => ['required', 'integer', 'min:1', 'distinct'],
                'details.recipients.*.organization' => ['required', 'string', 'max:255'],
                'details.recipients.*.contact_name' => ['nullable', 'string', 'max:255'],
                'details.recipients.*.email' => ['nullable', 'email', 'max:255'],
                'details.recipients.*.phone' => ['nullable', 'string', 'max:64'],
                'details.recipients.*.address' => ['nullable', 'string', 'max:500']];
        }

        return $rules;
    }

    /** Called only inside the existing authorized, locked operational transaction.
     * @param  array<string, mixed>  $data
     */
    public function correct(User $actor, IncomingCorrespondence|OutgoingCorrespondence $record, IncomingCorrespondenceOperation|OutgoingOperation $operation, array $data): IncomingCorrespondence|OutgoingCorrespondence
    {
        $outgoing = $record instanceof OutgoingCorrespondence;
        $prefix = $outgoing ? 'outgoing' : 'incoming';
        if ($operation->status === 'closed') {
            throw ValidationException::withMessages(['operation' => $prefix.'Closed']);
        }
        if ($outgoing && ($operation->status !== 'registered' || $operation->dispatch_date !== null
            || $record->deliveries()->exists()
            || OutgoingOperationAudit::query()->where('correspondence_id', $record->id)->whereIn('action', ['dispatch', 'correct_dispatch', 'delivery'])->exists())) {
            throw ValidationException::withMessages(['operation' => 'outgoingDetailsAfterDispatch']);
        }
        $before = [];
        $after = [];
        $changes = $data['details'];
        unset($changes['recipients']);
        $record->fill($changes);
        foreach (array_keys($changes) as $field) {
            if ($record->isDirty($field)) {
                $before[$field] = $record->getRawOriginal($field);
                $after[$field] = str_ends_with($field, '_date') ? $record->getAttribute($field)->format('Y-m-d') : $record->getAttributes()[$field];
            }
        }
        $recipientChanges = [];
        if ($outgoing && isset($data['details']['recipients'])) {
            foreach ($data['details']['recipients'] as $values) {
                $recipient = $record->recipients()->whereKey($values['id'])->lockForUpdate()->firstOrFail();
                unset($values['id']);
                $recipient->fill($values);
                foreach (array_keys($values) as $field) {
                    if ($recipient->isDirty($field)) {
                        $before['recipients'][$recipient->id][$field] = $recipient->getRawOriginal($field);
                        $after['recipients'][$recipient->id][$field] = $recipient->getAttributes()[$field];
                        $recipientChanges[$recipient->id][$field] = $after['recipients'][$recipient->id][$field];
                    }
                }
            }
        }
        if ($after === []) {
            throw ValidationException::withMessages(['details' => 'correspondenceNoChanges']);
        }
        $auditClass = $outgoing ? OutgoingOperationAudit::class : IncomingOperationAudit::class;
        $audit = $auditClass::query()->create(['correspondence_id' => $record->id, 'actor_id' => $actor->id,
            'revision' => $operation->revision + 1, 'action' => $outgoing ? 'note' : 'updated', 'note' => $data['note'],
            'before' => ['details' => $before], 'after' => ['details' => $after]]);
        if (! $audit->exists) {
            throw new LogicException('Correction audit persistence cancelled.');
        }
        $metadata = $after;
        unset($metadata['recipients']);
        if ($metadata !== []) {
            $affected = DB::table($record->getTable())->where('id', $record->id)->update($metadata + ['updated_by' => $actor->id, 'updated_at' => now()]);
            if ($affected !== 1) {
                throw new LogicException('Correction persistence failed.');
            }
        }
        foreach ($recipientChanges as $id => $values) {
            if (DB::table('outgoing_recipients')->where('correspondence_id', $record->id)->where('id', $id)->update($values + ['updated_at' => now()]) !== 1) {
                throw new LogicException('Recipient correction persistence failed.');
            }
        }
        $operation->revision++;
        if (! $operation->save()) {
            throw new LogicException('Correction revision persistence cancelled.');
        }

        return $record->refresh();
    }
}
