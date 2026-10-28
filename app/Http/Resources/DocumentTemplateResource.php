<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DocumentTemplateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return $this->resource->only(['id', 'scope_id', 'name_en', 'name_lo', 'description_en', 'description_lo', 'purpose',
            'attachment_version_id', 'status', 'created_by', 'retired_by', 'retired_at', 'retirement_reason']);
    }
}
