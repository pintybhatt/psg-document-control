<?php

namespace App\Models;

use App\Authorization\DocumentOperation;
use Database\Factories\DocumentAccessGrantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

#[Fillable(['profile_id', 'user_id', 'operation', 'active', 'expires_at'])]
class DocumentAccessGrant extends Model
{
    /** @use HasFactory<DocumentAccessGrantFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::updating(function (DocumentAccessGrant $grant): void {
            if ($grant->getRawOriginal('revoked_at') !== null || $grant->isDirty(['profile_id', 'user_id', 'operation', 'created_by'])) {
                throw ValidationException::withMessages(['grant' => 'documentSecurityImmutable']);
            }
        });
        static::deleting(function (DocumentAccessGrant $grant): void {
            throw ValidationException::withMessages(['grant' => 'documentSecurityImmutable']);
        });
    }

    /** @return BelongsTo<DocumentSecurityProfile, $this> */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(DocumentSecurityProfile::class, 'profile_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['operation' => DocumentOperation::class, 'active' => 'boolean', 'expires_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime'];
    }
}
