<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

#[Fillable(['document_id', 'revision_number', 'draft_slot', 'title_en', 'title_lo', 'description_en', 'description_lo', 'owner_id', 'custodian_id', 'owner_name', 'custodian_name', 'issue_date', 'effective_date', 'review_date', 'revision_reason', 'change_description', 'released_at', 'released_by', 'release_reason', 'supersedes_id', 'created_by', 'updated_by'])]
class ControlledRevision extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::updating(function (self $revision): void {
            if ($revision->getRawOriginal('released_at') !== null || $revision->isDirty(['id', 'document_id', 'revision_number', 'supersedes_id', 'created_by'])) {
                throw ValidationException::withMessages(['controlled' => 'controlledImmutable']);
            }
        });
        static::deleting(function (): void {
            throw ValidationException::withMessages(['controlled' => 'controlledImmutable']);
        });
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(ControlledDocument::class, 'document_id');
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function custodian(): BelongsTo
    {
        return $this->belongsTo(User::class, 'custodian_id');
    }

    public function identifier(): string
    {
        return self::formatIdentifier($this->revision_number);
    }

    public static function formatIdentifier(int $revisionNumber): string
    {
        return 'R'.str_pad((string) $revisionNumber, 2, '0', STR_PAD_LEFT);
    }

    /**
     * Display only. Existing revision-like components are never removed or replaced:
     * their meaning may be a static suffix or part of a legacy document identity.
     */
    public function displayIdentifier(string $documentCode): ?string
    {
        $components = preg_match_all('/R[0-9]+/i', $documentCode);
        if ($components > 0) {
            return $components === 1 && str_ends_with($documentCode, '-'.$this->identifier()) ? $documentCode : null;
        }

        return $documentCode.'-'.$this->identifier();
    }

    protected function casts(): array
    {
        return ['issue_date' => 'date', 'effective_date' => 'date', 'review_date' => 'date', 'released_at' => 'datetime', 'revision_number' => 'integer'];
    }
}
