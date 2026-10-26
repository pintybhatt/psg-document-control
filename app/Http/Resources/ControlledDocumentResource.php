<?php

namespace App\Http\Resources;

use App\Models\ControlledRevision;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ControlledDocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing(['profile', 'type.category', 'currentRevision', 'revisions.owner', 'revisions.custodian']);

        return $this->resource->only(['id', 'document_code', 'internal_number', 'status', 'type_id', 'lock_version', 'registered_by']) + [
            'storage_url' => route('api.v1.storage.index', ['type' => 'controlled', 'record' => $this->id], false),
            'company_id' => $this->profile->company_id, 'site_id' => $this->profile->site_id, 'department_id' => $this->profile->department_id,
            'classification' => $this->profile->classification->value, 'type' => $this->type->only(['id', 'name_en', 'name_lo']),
            'category' => $this->type->category->only(['id', 'name_en', 'name_lo']), 'registered_at' => $this->registered_at?->toISOString(),
            'current_revision_id' => $this->current_revision_id,
            'authoritative_revision_id' => $this->status === 'current' ? $this->current_revision_id : null,
            'revisions' => $this->revisions->map(fn (ControlledRevision $revision): array => $revision->only([
                'id', 'revision_number', 'title_en', 'title_lo', 'description_en', 'description_lo', 'owner_id', 'custodian_id', 'revision_reason', 'change_description', 'supersedes_id', 'released_by', 'release_reason',
            ]) + [
                'identifier' => $revision->identifier(), 'status' => $revision->released_at === null ? 'draft' : ($revision->id === $this->current_revision_id ? $this->status : 'superseded'),
                'owner_name' => $revision->owner_name ?? $revision->owner->name, 'custodian_name' => $revision->custodian_name ?? $revision->custodian->name,
                'issue_date' => $revision->issue_date->format('Y-m-d'), 'effective_date' => $revision->effective_date->format('Y-m-d'), 'review_date' => $revision->review_date?->format('Y-m-d'),
                'released_at' => $revision->released_at?->toISOString(),
            ])->values(),
        ];
    }
}
