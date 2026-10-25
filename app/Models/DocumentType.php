<?php

namespace App\Models;

use Database\Factories\DocumentTypeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['scope_id', 'code', 'category_id', 'name_en', 'name_lo', 'description_en', 'description_lo', 'active'])]
class DocumentType extends ScopedDocumentMaster
{
    /** @use HasFactory<DocumentTypeFactory> */
    use HasFactory;

    public function category(): BelongsTo
    {
        return $this->belongsTo(DocumentCategory::class, 'category_id');
    }

    public function registers(): BelongsToMany
    {
        return $this->belongsToMany(RegisterConfiguration::class, 'document_type_register', 'type_id', 'register_id')->withPivot('scope_id');
    }

    public function effectiveFor(RegisterConfiguration $register): bool
    {
        $stored = self::query()->with(['scope', 'category', 'registers'])->find($this->id);

        return $stored !== null && $stored->active && $stored->category->active && $stored->scope->isActive()
            && $stored->registers->contains(fn (RegisterConfiguration $candidate): bool => $candidate->id === $register->id && $candidate->active);
    }
}
