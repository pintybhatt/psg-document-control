<?php

namespace App\Http\Resources;

use App\Models\DocumentType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DocumentMasterDataResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = $this->resource->only(['id', 'code', 'name_en', 'name_lo', 'register_key', 'category_id', 'description_en', 'description_lo', 'active']);
        $data['scope_key'] = $this->scope->scope_key;
        $data['effective'] = $this->active && $this->scope->isActive();
        if ($this->resource instanceof DocumentType) {
            $data += $this->resource->only(['review_enabled', 'review_interval_months', 'review_baseline']);
            $data['category'] = $this->category->only(['id', 'name_en', 'name_lo', 'active']);
            $data['register_ids'] = $this->registers->pluck('id')->all();
            $data['effective_registers'] = $data['effective'] && $this->category->active
                ? $this->registers->where('active', true)->pluck('register_key')->all() : [];
            $data['effective'] = $data['effective_registers'] !== [];
        }

        return $data;
    }
}
