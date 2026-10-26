<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

#[Fillable(['profile_id', 'actor_id', 'action', 'subject_id', 'reason', 'before', 'after'])]
class StorageEvent extends Model
{
    protected function casts(): array
    {
        return ['before' => 'array', 'after' => 'array'];
    }

    protected static function booted(): void
    {
        $deny = function (): void {
            throw ValidationException::withMessages(['storage' => 'storageImmutable']);
        };
        static::updating($deny);
        static::deleting($deny);
    }
}
