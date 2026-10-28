<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

#[Fillable(['profile_id', 'revision_id', 'configuration_id', 'submitted_by', 'content_hash', 'snapshot'])]
class DocumentApprovalSubmission extends Model
{
    protected static function booted(): void
    {
        $deny = function (): void {
            throw ValidationException::withMessages(['approval' => 'approvalImmutable']);
        };
        static::updating($deny);
        static::deleting($deny);
    }

    protected function casts(): array
    {
        return ['snapshot' => 'array'];
    }
}
