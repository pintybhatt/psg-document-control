<?php

namespace App\Http\Resources;

use App\Authorization\ManageIncomingOperations;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class IncomingCorrespondenceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'storage_url' => route('api.v1.storage.index', ['type' => 'incoming', 'record' => $this->id], false), 'company_id' => $this->profile->company_id, 'site_id' => $this->profile->site_id,
            'department_id' => $this->profile->department_id, 'classification' => $this->profile->classification->value,
            'category' => $this->type->category->only(['id', 'name_en', 'name_lo']),
            'type' => $this->type->only(['id', 'name_en', 'name_lo']), 'type_id' => $this->type_id,
            'sender_organization' => $this->sender_organization, 'sender_contact' => $this->sender_contact,
            'sender_email' => $this->sender_email, 'sender_phone' => $this->sender_phone, 'external_reference' => $this->external_reference,
            'correspondence_date' => $this->correspondence_date->format('Y-m-d'), 'received_date' => $this->received_date->format('Y-m-d'),
            'subject' => $this->subject, 'description_en' => $this->description_en, 'description_lo' => $this->description_lo,
            'priority' => $this->priority, 'status' => $this->status, 'internal_number' => $this->internal_number,
            'channel' => $this->channel,
            'operations' => $this->status === 'registered' ? app(ManageIncomingOperations::class)->state($this->resource) : null,
            'due_indicator' => $this->operation?->due_date && $this->operation->status !== 'closed'
                ? ($this->operation->due_date->isToday() ? 'today' : ($this->operation->due_date->lt(today()) ? 'overdue' : 'upcoming')) : null,
            'registered_at' => $this->registered_at?->toISOString(), 'registered_by' => $this->registered_by,
        ];
    }
}
