<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

#[Fillable(['template_id', 'attachment_version_id', 'approval_submission_id', 'published_by', 'published_at', 'reason', 'snapshot'])]
class DocumentTemplatePublication extends Model
{
    protected static function booted(): void
    {
        $deny = function (): void {
            throw ValidationException::withMessages(['template' => 'templateImmutable']);
        };
        static::updating($deny);
        static::deleting($deny);
    }

    protected function casts(): array
    {
        return ['published_at' => 'immutable_datetime', 'snapshot' => 'array'];
    }
}
