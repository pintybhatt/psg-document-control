<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

#[Fillable(['revision_id', 'actor_id', 'decision', 'document_version', 'comments', 'snapshot', 'recorded_at'])]
class ControlledRevisionApproval extends Model
{
    protected static function booted(): void
    {
        $deny = function (): void {
            throw ValidationException::withMessages(['controlled' => 'controlledImmutable']);
        };
        static::updating($deny);
        static::deleting($deny);
    }

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'document_version' => 'integer', 'recorded_at' => 'immutable_datetime'];
    }
}
