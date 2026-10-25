<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

#[Fillable(['actor_id', 'source', 'action', 'subject_type', 'subject_id', 'scope_id', 'before', 'after'])]
class RbacAudit extends Model
{
    protected static function booted(): void
    {
        $deny = function (RbacAudit $audit): void {
            throw ValidationException::withMessages(['audit' => 'rbacAuditImmutable']);
        };
        static::updating($deny);
        static::deleting($deny);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['before' => 'array', 'after' => 'array'];
    }
}
