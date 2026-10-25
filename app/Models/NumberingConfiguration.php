<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

#[Fillable(['series_id', 'version', 'prefix', 'padding', 'active', 'change_reason'])]
class NumberingConfiguration extends Model
{
    protected static function booted(): void
    {
        $deny = function (): void {
            throw ValidationException::withMessages(['history' => 'numberingHistoryImmutable']);
        };
        static::updating($deny);
        static::deleting($deny);
    }

    protected function casts(): array
    {
        return ['active' => 'boolean', 'padding' => 'integer', 'version' => 'integer', 'effective_at' => 'immutable_datetime'];
    }
}
