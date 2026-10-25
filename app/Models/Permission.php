<?php

namespace App\Models;

use App\Authorization\PermissionCatalog;
use App\Authorization\RbacMutation;
use Database\Factories\PermissionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Validation\ValidationException;

#[Fillable(['key', 'area', 'name_en', 'name_lo', 'active'])]
class Permission extends Model
{
    /** @use HasFactory<PermissionFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (Permission $permission): void {
            if (! array_key_exists($permission->key, PermissionCatalog::definitions()) || ($permission->exists && $permission->isDirty('key'))) {
                throw ValidationException::withMessages(['key' => 'rbacInvalidPermission']);
            }
        });
    }

    public function save(array $options = []): bool
    {
        return app(RbacMutation::class)->run(fn (): bool => parent::save($options));
    }

    /** @return BelongsToMany<Role, $this> */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
