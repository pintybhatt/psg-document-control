<?php

namespace App\Models;

use App\Authorization\Confidentiality;
use App\Authorization\DocumentScope;
use Database\Factories\ConfidentialityClearanceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

#[Fillable(['user_id', 'company_id', 'site_id', 'department_id', 'classification', 'active', 'expires_at'])]
class ConfidentialityClearance extends Model
{
    /** @use HasFactory<ConfidentialityClearanceFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (ConfidentialityClearance $clearance): void {
            $clearance->documentScope();
            if ($clearance->classification === Confidentiality::Normal
                || ($clearance->exists && ($clearance->getRawOriginal('revoked_at') !== null
                    || $clearance->isDirty(['user_id', 'company_id', 'site_id', 'department_id', 'classification', 'created_by'])))) {
                throw ValidationException::withMessages(['clearance' => 'documentSecurityImmutable']);
            }
        });
        static::deleting(function (ConfidentialityClearance $clearance): void {
            throw ValidationException::withMessages(['clearance' => 'documentSecurityImmutable']);
        });
    }

    public function documentScope(): DocumentScope
    {
        return new DocumentScope((int) $this->company_id, (int) $this->site_id, $this->department_id);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['classification' => Confidentiality::class, 'active' => 'boolean', 'expires_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime',
            'company_id' => 'integer', 'site_id' => 'integer', 'department_id' => 'integer'];
    }
}
