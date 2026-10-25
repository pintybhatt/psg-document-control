<?php

namespace App\Models;

use Database\Factories\DocumentCategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['scope_id', 'code', 'name_en', 'name_lo', 'description_en', 'description_lo', 'active'])]
class DocumentCategory extends ScopedDocumentMaster
{
    /** @use HasFactory<DocumentCategoryFactory> */
    use HasFactory;

    public function types(): HasMany
    {
        return $this->hasMany(DocumentType::class, 'category_id');
    }
}
