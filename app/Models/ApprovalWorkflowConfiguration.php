<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

#[Fillable(['scope_id', 'register_key', 'version', 'enabled', 'sequence', 'levels', 'change_reason', 'created_by'])]
class ApprovalWorkflowConfiguration extends Model
{
    protected static function booted(): void
    {
        $deny = function (): void {
            throw ValidationException::withMessages(['workflow' => 'workflowImmutable']);
        };
        static::updating($deny);
        static::deleting($deny);
    }

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'version' => 'integer', 'levels' => 'array'];
    }
}
