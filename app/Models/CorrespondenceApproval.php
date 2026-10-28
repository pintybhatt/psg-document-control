<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

#[Fillable(['submission_id', 'level', 'decision', 'actor_id', 'comments', 'recorded_at'])]
class CorrespondenceApproval extends Model
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
        return ['level' => 'integer', 'recorded_at' => 'immutable_datetime'];
    }
}
