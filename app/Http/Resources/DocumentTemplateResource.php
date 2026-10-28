<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DocumentTemplateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $values = $this->resource->only(['id', 'scope_id', 'name_en', 'name_lo', 'description_en', 'description_lo', 'purpose',
            'attachment_version_id', 'created_by', 'retired_by', 'retired_at', 'retirement_reason']);
        $values['status'] = $this->resource->catalogueStatus();
        $values['published_by'] = $this->publication?->published_by;
        $values['published_at'] = $this->publication?->published_at?->toISOString();
        if ($values['status'] === 'published') {
            $values['download_url'] = route('api.v1.templates.download', ['template' => $this->id] + $request->only(['scope_type', 'company_id', 'site_id']), false);
        }

        return $values;
    }
}
