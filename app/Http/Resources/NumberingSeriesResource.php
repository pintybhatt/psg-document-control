<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NumberingSeriesResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'scope_key' => $this->scope->scope_key, 'register_id' => $this->register_id,
            'register_key' => $this->register->register_key, 'register_active' => $this->register->active, 'purpose' => $this->purpose, 'reset_policy' => $this->reset_policy,
            'effective' => $this->currentConfiguration?->active && $this->register->active && $this->scope->isActive(),
            'configuration' => $this->currentConfiguration?->only(['id', 'version', 'prefix', 'padding', 'format', 'active', 'change_reason', 'effective_at'])];
    }
}
