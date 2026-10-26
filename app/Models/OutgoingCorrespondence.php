<?php

namespace App\Models;

use App\Authorization\FormalNumberedRegistration;
use App\Authorization\NumberingPurpose;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

#[Fillable(['scope_id', 'profile_id', 'register_id', 'type_id', 'signatory_name', 'signatory_title', 'subject', 'description_en', 'description_lo', 'document_date', 'priority', 'status', 'internal_number', 'official_number', 'registered_at', 'registered_by', 'created_by', 'updated_by'])]
class OutgoingCorrespondence extends Model implements FormalNumberedRegistration
{
    protected static function booted(): void
    {
        static::updating(function (self $record): void {
            if ($record->getRawOriginal('status') === 'registered' || $record->isDirty(['id', 'scope_id', 'profile_id', 'register_id', 'created_by'])) {
                throw ValidationException::withMessages(['outgoing' => 'outgoingImmutable']);
            }
        });
        static::deleting(function (): void {
            throw ValidationException::withMessages(['outgoing' => 'outgoingImmutable']);
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

    public function recipients(): HasMany
    {
        return $this->hasMany(OutgoingRecipient::class, 'correspondence_id')->orderBy('id');
    }

    public function audits(): HasMany
    {
        return $this->hasMany(OutgoingCorrespondenceAudit::class, 'correspondence_id')->orderBy('id');
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
        return $this->status === 'registered';
    }

    public function registeredNumber(NumberingPurpose $purpose): ?string
    {
        return match ($purpose) {
            NumberingPurpose::InternalRegister => $this->internal_number,
            NumberingPurpose::OfficialOutgoing => $this->official_number,
        };
    }

    protected function casts(): array
    {
        return ['document_date' => 'date', 'registered_at' => 'datetime'];
    }
}
