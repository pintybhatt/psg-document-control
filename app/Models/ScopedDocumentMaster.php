<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

abstract class ScopedDocumentMaster extends Model
{
    protected static function booted(): void
    {
        static::saving(function (ScopedDocumentMaster $record): void {
            $scope = AuthorizationScope::query()->find($record->scope_id);
            if (! $scope || ! in_array($scope->scope_type, ['company', 'site'], true)) {
                throw ValidationException::withMessages(['scope_type' => 'rbacInvalidScope']);
            }
            if ($record->exists && $record->isDirty(['scope_id', 'code', 'category_id', 'register_key'])) {
                throw ValidationException::withMessages(['code' => 'masterIdentityImmutable']);
            }
        });
        static::deleting(function (): void {
            throw ValidationException::withMessages(['master_data' => 'masterNoDelete']);
        });
    }

    public function scope(): BelongsTo
    {
        return $this->belongsTo(AuthorizationScope::class, 'scope_id');
    }

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
