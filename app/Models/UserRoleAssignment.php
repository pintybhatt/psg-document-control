<?php

namespace App\Models;

use App\Authorization\RbacMutation;
use Database\Factories\UserRoleAssignmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

#[Fillable(['user_id', 'role_id', 'scope_id', 'active'])]
class UserRoleAssignment extends Model
{
    /** @use HasFactory<UserRoleAssignmentFactory> */
    use HasFactory;

    protected $attributes = ['active' => true];

    protected static function booted(): void
    {
        static::saving(function (UserRoleAssignment $assignment): void {
            if ($assignment->exists && $assignment->isDirty(['user_id', 'role_id', 'scope_id'])) {
                throw ValidationException::withMessages(['scope_id' => 'rbacScopeImmutable']);
            }
            $role = Role::query()->with('scope')->find($assignment->role_id);
            $scope = AuthorizationScope::query()->find($assignment->scope_id);
            if (! $role || ! $scope || ! $role->scope->covers($scope)) {
                throw ValidationException::withMessages(['scope_id' => 'rbacInvalidScope']);
            }
            if ($assignment->active && (! $role->active || ! User::query()->whereKey($assignment->user_id)->where('active', true)->exists())) {
                throw ValidationException::withMessages(['user_id' => 'rbacInactiveTarget']);
            }
        });
    }

    public function save(array $options = []): bool
    {
        return app(RbacMutation::class)->run(fn (): bool => parent::save($options));
    }

    public function delete(): ?bool
    {
        return app(RbacMutation::class)->run(fn (): ?bool => parent::delete());
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Role, $this> */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /** @return BelongsTo<AuthorizationScope, $this> */
    public function scope(): BelongsTo
    {
        return $this->belongsTo(AuthorizationScope::class, 'scope_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
