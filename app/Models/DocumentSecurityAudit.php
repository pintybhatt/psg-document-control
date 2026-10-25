<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

#[Fillable(['actor_id', 'profile_id', 'target_user_id', 'clearance_id', 'grant_id', 'event', 'operation', 'reason', 'before', 'after'])]
class DocumentSecurityAudit extends Model
{
    protected static function booted(): void
    {
        $deny = function (DocumentSecurityAudit $audit): void {
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
