<?php

namespace App\Models;

use App\Authorization\Confidentiality;
use App\Authorization\DocumentResourceType;
use App\Authorization\DocumentScope;
use Database\Factories\DocumentSecurityProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

#[Fillable(['resource_type', 'resource_key', 'company_id', 'site_id', 'department_id', 'classification', 'restricted', 'active'])]
class DocumentSecurityProfile extends Model
{
    /** @use HasFactory<DocumentSecurityProfileFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (DocumentSecurityProfile $profile): void {
            $profile->documentScope();
            if (! preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $profile->resource_key)
                || ($profile->classification === Confidentiality::HighlyConfidential && ! $profile->restricted)
                || ($profile->exists && ($profile->isDirty(['resource_type', 'resource_key', 'company_id', 'site_id', 'department_id', 'classification', 'created_by'])
                    || ($profile->isDirty('restricted') && ! $profile->restricted)))) {
                throw ValidationException::withMessages(['resource' => 'documentSecurityImmutable']);
            }
        });
        static::deleting(function (DocumentSecurityProfile $profile): void {
            throw ValidationException::withMessages(['resource' => 'documentSecurityImmutable']);
        });
    }

    public function documentScope(): DocumentScope
    {
        return new DocumentScope((int) $this->company_id, (int) $this->site_id, $this->department_id);
    }

    /** @return HasMany<DocumentAccessGrant, $this> */
    public function accessGrants(): HasMany
    {
        return $this->hasMany(DocumentAccessGrant::class, 'profile_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['classification' => Confidentiality::class, 'resource_type' => DocumentResourceType::class, 'restricted' => 'boolean', 'active' => 'boolean',
            'company_id' => 'integer', 'site_id' => 'integer', 'department_id' => 'integer'];
    }
}
