<?php

namespace App\Models;

use App\Authorization\DocumentResourceType;
use Database\Factories\RegisterConfigurationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['scope_id', 'register_key', 'description_en', 'description_lo', 'active'])]
class RegisterConfiguration extends ScopedDocumentMaster
{
    /** @use HasFactory<RegisterConfigurationFactory> */
    use HasFactory;

    public function types(): BelongsToMany
    {
        return $this->belongsToMany(DocumentType::class, 'document_type_register', 'register_id', 'type_id')->withPivot('scope_id');
    }

    protected function casts(): array
    {
        return ['active' => 'boolean', 'register_key' => DocumentResourceType::class];
    }
}
