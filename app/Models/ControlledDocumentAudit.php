<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

#[Fillable(['document_id', 'revision_id', 'actor_id', 'action', 'reason', 'changed_fields'])]
class ControlledDocumentAudit extends Model
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
        return ['changed_fields' => 'array'];
    }
}
