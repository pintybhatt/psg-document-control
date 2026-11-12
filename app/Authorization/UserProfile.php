<?php

namespace App\Authorization;

use App\Models\AuthorizationScope;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Models\UserRoleAssignment;

/** Read-only summary of the signed-in user's own roles, memberships, and granted permissions for the account menu. */
class UserProfile
{
    public function __construct(private AccessControl $access, private MembershipAccess $memberships) {}

    /**
     * @return array{
     *     system_administrator: bool,
     *     roles: list<array{code: string, name_en: string, name_lo: string, scope: array<string, mixed>}>,
     *     memberships: list<array<string, mixed>>,
     *     permissions: list<array{key: string, area: string, name_en: string, name_lo: string}>
     * }
     */
    public function for(User $user): array
    {
        $isSystemAdministrator = $this->access->isSystemAdministrator($user);
        $definitions = PermissionCatalog::definitions();
        $roles = [];
        $keys = [];

        $assignments = UserRoleAssignment::query()->where('user_id', $user->id)->where('active', true)
            ->with(['role.permissions', 'role.scope', 'scope.company', 'scope.site'])->get();
        foreach ($assignments as $assignment) {
            $role = $assignment->role;
            if (! $role->active || ! $assignment->scope->isActive() || ! $role->scope->covers($assignment->scope)) {
                continue;
            }
            $roles[] = ['code' => $role->code, 'name_en' => $role->displayName('en'), 'name_lo' => $role->displayName('lo'), 'scope' => $this->scope($assignment->scope)];
            foreach ($role->permissions as $permission) {
                if ($permission->active && PermissionCatalog::available($permission->key)) {
                    $keys[] = $permission->key;
                }
            }
        }
        if ($isSystemAdministrator) {
            $keys = array_keys(array_filter($definitions, fn (array $definition): bool => $definition['available']));
        }

        $memberships = $this->memberships->effectiveQuery()->where('user_id', $user->id)->with(['company', 'site', 'department'])->get()
            ->map(fn (OrganizationMembership $membership): array => [
                'type' => $membership->membership_type,
                'company' => $membership->company->only(['name_en', 'name_lo']),
                'site' => $membership->site?->only(['name_en', 'name_lo']),
                'department' => $membership->department?->only(['name_en', 'name_lo']),
            ])->values()->all();

        $permissions = collect(array_unique($keys))->map(fn (string $key): array => ['key' => $key] + array_intersect_key($definitions[$key], array_flip(['area', 'name_en', 'name_lo'])))
            ->sortBy(['area', 'key'])->values()->all();

        return ['system_administrator' => $isSystemAdministrator, 'roles' => $roles, 'memberships' => $memberships, 'permissions' => $permissions];
    }

    /** @return array{type: string, company: array<string, string>|null, site: array<string, string>|null} */
    private function scope(AuthorizationScope $scope): array
    {
        return ['type' => $scope->scope_type, 'company' => $scope->company?->only(['name_en', 'name_lo']), 'site' => $scope->site?->only(['name_en', 'name_lo'])];
    }
}
