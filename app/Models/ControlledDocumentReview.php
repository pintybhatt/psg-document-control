<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

#[Fillable(['revision_id', 'actor_id', 'outcome', 'document_version', 'comments', 'recorded_at', 'interval_months', 'baseline', 'next_review_date'])]
class ControlledDocumentReview extends Model
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
        return ['interval_months' => 'integer', 'next_review_date' => 'immutable_date', 'document_version' => 'integer', 'recorded_at' => 'immutable_datetime'];
    }
}
