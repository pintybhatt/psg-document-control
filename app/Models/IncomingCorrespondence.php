<?php

namespace App\Models;

use App\Authorization\FormalNumberedRegistration;
use App\Authorization\NumberingPurpose;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Validation\ValidationException;

#[Fillable(['scope_id', 'profile_id', 'register_id', 'type_id', 'channel', 'sender_organization', 'sender_contact', 'sender_email', 'sender_phone', 'external_reference', 'correspondence_date', 'received_date', 'subject', 'document_revision', 'description_en', 'description_lo', 'priority', 'status', 'internal_number', 'registered_at', 'registered_by', 'created_by', 'updated_by'])]
class IncomingCorrespondence extends Model implements FormalNumberedRegistration
{
    protected static function booted(): void
    {
        static::updating(function (self $record): void {
            if ($record->getRawOriginal('status') === 'registered' || $record->isDirty(['scope_id', 'profile_id', 'register_id', 'created_by'])) {
                throw ValidationException::withMessages(['incoming' => 'incomingImmutable']);
            }
        });
        static::deleting(function (): void {
            throw ValidationException::withMessages(['incoming' => 'incomingImmutable']);
        });
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(DocumentSecurityProfile::class, 'profile_id');
    }

    public function operation(): HasOne
    {
        return $this->hasOne(IncomingCorrespondenceOperation::class, 'correspondence_id');
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class, 'type_id');
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
        return $purpose === NumberingPurpose::InternalRegister ? $this->internal_number : null;
    }

    protected function casts(): array
    {
        return ['correspondence_date' => 'date', 'received_date' => 'date', 'registered_at' => 'datetime'];
    }
}
