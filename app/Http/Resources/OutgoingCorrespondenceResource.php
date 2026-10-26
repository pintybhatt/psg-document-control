<?php

namespace App\Http\Resources;

use App\Authorization\ManageOutgoingOperations;
use App\Models\OutgoingRecipient;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OutgoingCorrespondenceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing(['operation', 'recipients.delivery']);

        return [
            'id' => $this->id, 'storage_url' => route('api.v1.storage.index', ['type' => 'outgoing', 'record' => $this->id], false), 'company_id' => $this->profile->company_id, 'site_id' => $this->profile->site_id,
            'department_id' => $this->profile->department_id, 'classification' => $this->profile->classification->value,
            'category' => $this->type->category->only(['id', 'name_en', 'name_lo']),
            'type' => $this->type->only(['id', 'name_en', 'name_lo']), 'type_id' => $this->type_id,
            'signatory_name' => $this->signatory_name, 'signatory_title' => $this->signatory_title,
            'subject' => $this->subject, 'description_en' => $this->description_en, 'description_lo' => $this->description_lo,
            'document_date' => $this->document_date->format('Y-m-d'), 'priority' => $this->priority, 'status' => $this->status,
            'internal_number' => $this->internal_number, 'official_number' => $this->official_number,
            'registered_at' => $this->registered_at?->toISOString(), 'registered_by' => $this->registered_by,
            'operations' => $this->status === 'registered' ? app(ManageOutgoingOperations::class)->state($this->resource) : null,
            'recipients' => $this->recipients->map(fn (OutgoingRecipient $recipient): array => $recipient->only(['id', 'kind', 'organization', 'contact_name', 'email', 'phone', 'address']) + ['delivery' => $recipient->delivery ? [
                'delivery_date' => $recipient->delivery->delivery_date?->format('Y-m-d'), 'acknowledged_by' => $recipient->delivery->acknowledged_by,
                'acknowledgment_date' => $recipient->delivery->acknowledgment_date?->format('Y-m-d'), 'acknowledgment_reference' => $recipient->delivery->acknowledgment_reference,
            ] : null])->values(),
        ];
    }
}
