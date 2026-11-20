<?php

namespace App\Authorization;

use App\Models\AuthorizationScope;
use App\Models\Company;
use App\Models\OrganizationMembership;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRoleAssignment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class AccessControl
{
    /** Reload authority for every decision; email addresses and loaded role relations confer no privilege. */
    public function isSystemAdministrator(User $user): bool
    {
        return User::query()->whereKey($user->id)->where('active', true)
            ->whereHas('roleAssignments', fn (Builder $query): Builder => $query->where('active', true)
                ->whereHas('scope', fn (Builder $scope): Builder => $scope->where('scope_key', 'global'))
                ->whereHas('role', fn (Builder $role): Builder => $role->where('code', Role::SYSTEM_ADMINISTRATOR_CODE)->where('active', true)
                    ->whereHas('scope', fn (Builder $scope): Builder => $scope->where('scope_key', 'global'))))->exists();
    }

    public function canManageRole(User $actor, string $roleCode, AuthorizationScope $scope): bool
    {
        return $roleCode !== Role::SYSTEM_ADMINISTRATOR_CODE || $scope->scope_type !== 'global' || $this->isSystemAdministrator($actor);
    }

    /** @return list<string> */
    public function grantedPermissions(User $user, AuthorizationScope $scope): array
    {
        if (! User::query()->whereKey($user->id)->where('active', true)->exists() || ! $scope->isActive()) {
            return [];
        }
        if ($this->isSystemAdministrator($user)) {
            return array_keys(array_filter(PermissionCatalog::definitions(), fn (array $definition): bool => $definition['available']));
        }
        $keys = [];
        $assignments = UserRoleAssignment::query()->where('user_id', $user->id)->where('active', true)
            ->with(['role.permissions', 'role.scope', 'scope'])->get();
        foreach ($assignments as $assignment) {
            if (! $assignment->role->active || ! $assignment->role->scope->covers($assignment->scope) || ! $assignment->scope->covers($scope)) {
                continue;
            }
            foreach ($assignment->role->permissions as $permission) {
                if ($permission->active && PermissionCatalog::available($permission->key)) {
                    $keys[] = $permission->key;
                }
            }
        }

        return array_values(array_unique($keys));
    }

    /** @return list<string> */
    public function permissions(User $user, AuthorizationScope $scope, ?int $departmentId = null): array
    {
        $membership = app(MembershipAccess::class);
        $isSystemAdministrator = $this->isSystemAdministrator($user);
        $granted = $this->grantedPermissions($user, $scope);
        $hasMembership = $isSystemAdministrator || ($granted !== [] && $membership->matches($user, $scope, $departmentId));
        $hasWorkspaceMembership = ! $hasMembership && $scope->scope_type === 'site'
            && array_any($granted, PermissionCatalog::acceptsCompanyWorkspaceMembership(...)) && $membership->matchesWorkspace($user, $scope, $departmentId);

        return array_values(array_filter($granted,
            fn (string $key): bool => ! PermissionCatalog::requiresDocument($key) && ($isSystemAdministrator || (PermissionCatalog::administrative($key)
                ? $departmentId === null : $hasMembership || ($hasWorkspaceMembership && PermissionCatalog::acceptsCompanyWorkspaceMembership($key))))));
    }

    public function allows(User $user, string $permission, AuthorizationScope $scope, ?int $departmentId = null): bool
    {
        return PermissionCatalog::available($permission) && in_array($permission, $this->permissions($user, $scope, $departmentId), true);
    }

    public function allowsOperational(User $user, string $permission, AuthorizationScope $scope, ?int $departmentId = null): bool
    {
        return ! PermissionCatalog::administrative($permission) && $this->allows($user, $permission, $scope, $departmentId);
    }

    public function authorize(User $user, string $permission, AuthorizationScope $scope): void
    {
        abort_unless($this->allows($user, $permission, $scope), 403, 'rbacForbidden');
    }

    /** @return Collection<int, AuthorizationScope> */
    public function administrationContexts(User $user): Collection
    {
        $securitySites = app(ConfidentialityAdministration::class)->contexts($user)->map(fn (DocumentScope $scope): AuthorizationScope => $scope->authorizationScope())->keyBy('scope_key');

        return $this->contexts($user)->keyBy('scope_key')->union($securitySites)->filter(function (AuthorizationScope $scope) use ($user, $securitySites): bool {
            $permissions = $this->permissions($user, $scope);

            return $securitySites->has($scope->scope_key) || array_intersect(['roles.view', 'assignments.view', 'memberships.view'], $permissions) !== []
                || ($scope->scope_type !== 'global' && array_intersect(['master-data.view', 'numbering.view'], $permissions) !== []);
        })->values();
    }

    public function canAccessAdministration(User $user): bool
    {
        return $this->administrationContexts($user)->isNotEmpty();
    }

    /** @return Builder<Role> */
    public function visibleRoles(AuthorizationScope $scope): Builder
    {
        $keys = ['global'];
        if ($scope->company_id !== null) {
            $keys[] = 'company:'.$scope->company_id;
        }
        $keys[] = $scope->scope_key;

        return Role::query()->whereHas('scope', fn (Builder $query): Builder => $query->whereIn('scope_key', $keys));
    }

    /** @return Builder<User> */
    public function eligibleUsers(User $actor, AuthorizationScope $scope): Builder
    {
        $query = User::query()->where('active', true);
        if ($this->allows($actor, 'users.view', AuthorizationScope::context('global'))) {
            return $query;
        }
        $companyAccess = $scope->company_id !== null && $this->allows($actor, 'users.view', AuthorizationScope::context('company', $scope->company_id));

        $legacyIdentities = User::query()->whereHas('roleAssignments', function (Builder $query) use ($companyAccess, $scope): void {
            $query->where('active', true)->whereHas('role', fn (Builder $role): Builder => $role->where('active', true))
                ->whereHas('scope', function (Builder $assignmentScope) use ($companyAccess, $scope): void {
                    if ($companyAccess) {
                        $assignmentScope->where('company_id', $scope->company_id);
                    } else {
                        $assignmentScope->where('scope_key', $scope->scope_key);
                    }
                });
        })->select('users.id');
        $members = app(MembershipAccess::class)->effectiveQuery()->where('company_id', $scope->company_id)
            ->when(! $companyAccess, fn (Builder $query): Builder => $query->where('site_id', $scope->site_id))->select('user_id');

        return $query->where(fn (Builder $query): Builder => $query->whereIn('id', $legacyIdentities)->orWhereIn('id', $members));
    }

    /** @return Collection<int, AuthorizationScope> */
    public function contexts(User $user): Collection
    {
        $candidates = collect();
        $assignments = UserRoleAssignment::query()->where('user_id', $user->id)->where('active', true)->with('scope')->get();
        foreach ($assignments as $assignment) {
            $scope = $assignment->scope;
            if (! $scope->isActive()) {
                continue;
            }
            $candidates->put($scope->scope_key, $scope);
            if ($scope->scope_type === 'global' || $scope->scope_type === 'company') {
                $companies = Company::query()->where('active', true)->when($scope->company_id, fn (Builder $query, int $id): Builder => $query->whereKey($id))->get();
                foreach ($companies as $company) {
                    $companyScope = AuthorizationScope::context('company', $company->id);
                    $candidates->put($companyScope->scope_key, $companyScope);
                    foreach ($company->sites()->where('active', true)->get() as $site) {
                        $siteScope = AuthorizationScope::context('site', $company->id, $site->id);
                        $candidates->put($siteScope->scope_key, $siteScope);
                    }
                }
            }
        }

        return $candidates->filter(fn (AuthorizationScope $scope): bool => $this->permissions($user, $scope) !== [])->values();
    }

    /**
     * @return Collection<int, array{
     *     membership_type: string, unit_key: string,
     *     company: array{id: int, name_en: string, name_lo: string},
     *     site: array{id: int, name_en: string, name_lo: string}|null,
     *     department: array{id: int, name_en: string, name_lo: string}|null
     * }>
     */
    public function operationalContexts(User $user): Collection
    {
        if ($this->isSystemAdministrator($user)) {
            return $this->contexts($user)->filter(fn (AuthorizationScope $scope): bool => $scope->scope_type !== 'global')
                ->map(fn (AuthorizationScope $scope): array => [
                    'membership_type' => $scope->scope_type, 'unit_key' => $scope->scope_key,
                    'company' => $scope->company->only(['id', 'name_en', 'name_lo']),
                    'site' => $scope->site?->only(['id', 'name_en', 'name_lo']), 'department' => null,
                ])->values();
        }

        return app(MembershipAccess::class)->effectiveQuery()->where('user_id', $user->id)->with(['company', 'site', 'department'])->get()
            ->filter(fn (OrganizationMembership $membership): bool => $this->allowsOperational($user, 'organization.access', $membership->authorizationScope(), $membership->department_id))
            ->map(fn (OrganizationMembership $membership): array => [
                'membership_type' => $membership->membership_type, 'unit_key' => $membership->unit_key,
                'company' => $membership->company->only(['id', 'name_en', 'name_lo']), 'site' => $membership->site?->only(['id', 'name_en', 'name_lo']),
                'department' => $membership->department?->only(['id', 'name_en', 'name_lo']),
            ])->values();
    }

    /** @return list<int> */
    public function administrators(AuthorizationScope $scope): array
    {
        $ids = [];
        foreach (User::query()->where('active', true)->whereHas('roleAssignments', fn (Builder $query): Builder => $query->where('active', true))->get() as $user) {
            if (array_diff(PermissionCatalog::ADMINISTRATOR_KEYS, $this->permissions($user, $scope)) === []) {
                $ids[] = $user->id;
            }
        }

        return $ids;
    }
}
