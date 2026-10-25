<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

#[Fillable(['series_id', 'configuration_id', 'period', 'year', 'sequence', 'number', 'issued_by', 'record_type', 'record_key'])]
class IssuedDocumentNumber extends Model
{
    protected static function booted(): void
    {
        $deny = function (): void {
            throw ValidationException::withMessages(['number' => 'numberingHistoryImmutable']);
        };
        static::updating($deny);
        static::deleting($deny);
    }
}
