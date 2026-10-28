<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Validation\ValidationException;

#[Fillable(['scope_id', 'name_en', 'name_lo', 'description_en', 'description_lo', 'purpose', 'attachment_version_id', 'status', 'created_by', 'retired_by', 'retired_at', 'retirement_reason'])]
class DocumentTemplate extends Model
{
    /** @return HasOne<DocumentTemplatePublication, $this> */
    public function publication(): HasOne
    {
        return $this->hasOne(DocumentTemplatePublication::class, 'template_id');
    }

    /** Publication is additive; existing catalogue state and audit rows remain unchanged. */
    public function catalogueStatus(): string
    {
        return $this->status === 'retired' ? 'retired' : ($this->publication !== null ? 'published' : 'inactive');
    }

    protected static function booted(): void
    {
        static::updating(function (self $template): void {
            if ($template->getRawOriginal('status') !== 'inactive' || $template->status !== 'retired'
                || $template->isDirty(['id', 'scope_id', 'name_en', 'name_lo', 'description_en', 'description_lo', 'purpose', 'attachment_version_id', 'created_by', 'created_at'])) {
                throw ValidationException::withMessages(['template' => 'templateImmutable']);
            }
        });
        static::deleting(function (): void {
            throw ValidationException::withMessages(['template' => 'templateImmutable']);
        });
    }

    protected function casts(): array
    {
        return ['retired_at' => 'immutable_datetime'];
    }
}
