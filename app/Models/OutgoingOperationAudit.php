<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

#[Fillable(['correspondence_id', 'actor_id', 'revision', 'action', 'note', 'before', 'after'])]
class OutgoingOperationAudit extends Model
{
    protected static function booted(): void
    {
        $deny = function (): void {
            throw ValidationException::withMessages(['audit' => 'rbacAuditImmutable']);
        };
        static::updating($deny);
        static::deleting($deny);
    }

    protected function casts(): array
    {
        return ['before' => 'array', 'after' => 'array'];
    }
}
