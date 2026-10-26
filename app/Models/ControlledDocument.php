<?php

namespace App\Models;

use App\Authorization\FormalNumberedRegistration;
use App\Authorization\NumberingPurpose;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

#[Fillable(['scope_id', 'profile_id', 'register_id', 'type_id', 'document_code', 'internal_number', 'status', 'current_revision_id', 'replaced_by_id', 'registered_at', 'registered_by', 'created_by', 'updated_by', 'lock_version'])]
class ControlledDocument extends Model implements FormalNumberedRegistration
{
    use HasFactory;

    protected static function booted(): void
    {
        static::updating(function (self $record): void {
            if ($record->isDirty(['id', 'scope_id', 'profile_id', 'register_id', 'created_by'])
                || ($record->getRawOriginal('registered_at') !== null && $record->isDirty(['document_code', 'type_id', 'internal_number', 'registered_at', 'registered_by']))) {
                throw ValidationException::withMessages(['controlled' => 'controlledImmutable']);
            }
        });
        static::deleting(function (): void {
            throw ValidationException::withMessages(['controlled' => 'controlledImmutable']);
        });
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(DocumentSecurityProfile::class, 'profile_id');
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class, 'type_id');
    }

    public function currentRevision(): BelongsTo
    {
        return $this->belongsTo(ControlledRevision::class, 'current_revision_id');
    }

    public function replacement(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaced_by_id');
    }

    public function replaces(): HasMany
    {
        return $this->hasMany(self::class, 'replaced_by_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(ControlledRevision::class, 'document_id')->orderBy('revision_number');
    }

    public function audits(): HasMany
    {
        return $this->hasMany(ControlledDocumentAudit::class, 'document_id')->orderBy('id');
    }

    public function numberingScopeId(): int
    {
        return (int) $this->scope_id;
    }

    public function numberingRegisterId(): int
    {
        return (int) $this->register_id;
    }

    public function isFormallyRegistered(): bool
    {
        return $this->registered_at !== null;
    }

    public function registeredNumber(NumberingPurpose $purpose): ?string
    {
        return $purpose === NumberingPurpose::InternalRegister ? $this->internal_number : null;
    }

    protected function casts(): array
    {
        return ['registered_at' => 'datetime', 'lock_version' => 'integer'];
    }
}
