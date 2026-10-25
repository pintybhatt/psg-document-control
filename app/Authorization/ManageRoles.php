<?php

namespace App\Authorization;

use App\Models\AuthorizationScope;
use App\Models\Permission;
use App\Models\RbacAudit;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRoleAssignment;
use Illuminate\Validation\ValidationException;

class ManageRoles
{
    public function __construct(private AccessControl $access, private RbacMutation $mutation) {}

    /** @param array{code?: string, name_en: string, name_lo: string, description?: ?string, active: bool, permission_keys: list<string>} $data */
    public function saveRole(User $actor, AuthorizationScope $scope, array $data, ?Role $role = null): Role
    {
        return $this->mutation->run(function () use ($actor, $scope, $data, $role): Role {
            $this->access->authorize($actor, 'roles.manage', $scope);
            if ($role) {
                $role->refresh();
                abort_unless($role->scope_id === $scope->id, 403, 'rbacForbidden');
            }
            $this->validateDelegation($actor, $scope, $data['permission_keys']);
            $before = $role ? $this->roleSnapshot($role) : null;
            $oldKeys = $role ? $role->permissions()->pluck('key')->sort()->values()->all() : [];
            if ($role) {
                $this->validateDelegation($actor, $scope, $oldKeys);
            }
            $newKeys = collect($data['permission_keys'])->sort()->values()->all();
            if ($role && ($oldKeys !== $newKeys || $role->active !== (bool) $data['active'])) {
                abort_if($role->assignments()->where('user_id', $actor->id)->exists(), 403, 'rbacSelfChange');
            }
            $role ??= new Role(['scope_id' => $scope->persist()->id, 'code' => $data['code']]);
            $role->fill(collect($data)->only(['name_en', 'name_lo', 'description', 'active'])->all())->save();
            $role->permissions()->sync(Permission::query()->whereIn('key', $data['permission_keys'])->pluck('id')->all());
            $this->audit($actor, 'role.saved', $role, $scope, $before, $this->roleSnapshot($role));

            return $role->refresh()->load(['permissions', 'scope']);
        });
    }

    /** @param list<string> $keys */
    private function validateDelegation(User $actor, AuthorizationScope $scope, array $keys): void
    {
        $held = $this->access->grantedPermissions($actor, $scope);
        foreach ($keys as $key) {
            if (! PermissionCatalog::available($key) || ! Permission::query()->where('key', $key)->where('active', true)->exists()) {
                throw ValidationException::withMessages(['permission_keys' => 'rbacInvalidPermission']);
            }
            abort_unless(in_array($key, $held, true), 403, 'rbacDelegationDenied');
        }
    }

    public function assign(User $actor, AuthorizationScope $scope, User $target, Role $role, bool $confirmGlobal = false): UserRoleAssignment
    {
        return $this->mutation->run(function () use ($actor, $scope, $target, $role, $confirmGlobal): UserRoleAssignment {
            $this->access->authorize($actor, 'assignments.manage', $scope);
            if ($scope->scope_type === 'global' && ! $confirmGlobal) {
                throw ValidationException::withMessages(['confirm_global' => 'rbacConfirmGlobal']);
            }
            $this->access->authorize($actor, 'users.view', $scope);
            abort_if($actor->id === $target->id, 403, 'rbacSelfChange');
            abort_unless($this->access->eligibleUsers($actor, $scope)->whereKey($target->id)->exists(), 403, 'rbacForbidden');
            $role->refresh()->load('scope');
            abort_unless($role->active && $role->scope->covers($scope), 403, 'rbacForbidden');
            $this->validateDelegation($actor, $scope, $role->permissions()->pluck('key')->all());
            $assignment = UserRoleAssignment::query()->firstOrNew(['user_id' => $target->id, 'role_id' => $role->id, 'scope_id' => $scope->persist()->id]);
            if (! $assignment->exists || ! $assignment->active) {
                $before = $assignment->exists ? $assignment->getAttributes() : null;
                $assignment->active = true;
                $assignment->save();
                $this->audit($actor, 'assignment.granted', $assignment, $scope, $before, $assignment->getAttributes());
            }

            return $assignment->load(['role', 'user', 'scope']);
        });
    }

    public function revoke(User $actor, AuthorizationScope $scope, UserRoleAssignment $assignment): void
    {
        $this->mutation->run(function () use ($actor, $scope, $assignment): void {
            $this->access->authorize($actor, 'assignments.manage', $scope);
            $assignment->refresh();
            abort_unless($assignment->scope_id === $scope->id, 403, 'rbacForbidden');
            abort_if($actor->id === $assignment->user_id, 403, 'rbacSelfChange');
            $this->validateDelegation($actor, $scope, $assignment->role->permissions()->pluck('key')->all());
            if ($assignment->active) {
                $before = $assignment->getAttributes();
                $assignment->active = false;
                $assignment->save();
                $this->audit($actor, 'assignment.revoked', $assignment, $scope, $before, $assignment->getAttributes());
            }
        });
    }

    /** @return array<string, mixed> */
    private function roleSnapshot(Role $role): array
    {
        return $role->getAttributes() + ['permission_keys' => $role->permissions()->orderBy('key')->pluck('key')->all()];
    }

    /** @param array<string, mixed>|null $before @param array<string, mixed> $after */
    public function audit(?User $actor, string $action, Role|UserRoleAssignment $subject, AuthorizationScope $scope, ?array $before, array $after): void
    {
        RbacAudit::query()->create([
            'actor_id' => $actor?->id, 'source' => $actor ? 'web' : 'cli', 'action' => $action,
            'subject_type' => $subject instanceof Role ? 'role' : 'assignment', 'subject_id' => $subject->id,
            'scope_id' => $scope->id, 'before' => $before, 'after' => $after,
        ]);
    }
}
