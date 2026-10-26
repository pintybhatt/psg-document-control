<?php

namespace App\Models;

use App\Authorization\RbacMutation;
use Database\Factories\RoleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

#[Fillable(['scope_id', 'code', 'name_en', 'name_lo', 'description', 'active'])]
class Role extends Model
{
    /** @use HasFactory<RoleFactory> */
    use HasFactory;

    public const SYSTEM_ADMINISTRATOR_CODE = 'SECURITY_ADMINISTRATOR';

    public const SYSTEM_ADMINISTRATOR_DESCRIPTION = 'An active global assignment grants full system and document access. Scoped assignments use configured permissions.';

    public function displayName(string $language): string
    {
        if ($this->code === self::SYSTEM_ADMINISTRATOR_CODE) {
            return $language === 'lo' ? 'ຜູ້ບໍລິຫານລະບົບ' : 'System Administrator';
        }

        return $this->getAttribute('name_'.$language);
    }

    public function displayDescription(): ?string
    {
        return $this->code === self::SYSTEM_ADMINISTRATOR_CODE ? self::SYSTEM_ADMINISTRATOR_DESCRIPTION : $this->description;
    }

    protected static function booted(): void
    {
        static::updating(function (Role $role): void {
            if ($role->isDirty(['code', 'scope_id'])) {
                throw ValidationException::withMessages(['code' => 'rbacRoleIdentityImmutable']);
            }
        });
    }

    public function save(array $options = []): bool
    {
        return app(RbacMutation::class)->run(fn (): bool => parent::save($options));
    }

    /** @return BelongsTo<AuthorizationScope, $this> */
    public function scope(): BelongsTo
    {
        return $this->belongsTo(AuthorizationScope::class, 'scope_id');
    }

    /** @return BelongsToMany<Permission, $this> */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class);
    }

    /** @return HasMany<UserRoleAssignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(UserRoleAssignment::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
