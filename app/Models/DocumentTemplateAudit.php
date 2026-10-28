<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

#[Fillable(['template_id', 'actor_id', 'action', 'snapshot', 'reason'])]
class DocumentTemplateAudit extends Model
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
        return ['snapshot' => 'array'];
    }
}
