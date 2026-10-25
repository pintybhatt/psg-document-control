<?php

namespace App\Authorization;

use App\Models\AuthorizationScope;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRoleAssignment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class AccessControl
{
    /** @return list<string> */
    public function permissions(User $user, AuthorizationScope $scope): array
    {
        if (! User::query()->whereKey($user->id)->where('active', true)->exists() || ! $scope->isActive()) {
            return [];
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

    public function allows(User $user, string $permission, AuthorizationScope $scope): bool
    {
        return PermissionCatalog::available($permission) && in_array($permission, $this->permissions($user, $scope), true);
    }

    public function authorize(User $user, string $permission, AuthorizationScope $scope): void
    {
        abort_unless($this->allows($user, $permission, $scope), 403, 'rbacForbidden');
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

        return $query->whereHas('roleAssignments', function (Builder $query) use ($companyAccess, $scope): void {
            $query->where('active', true)->whereHas('role', fn (Builder $role): Builder => $role->where('active', true))
                ->whereHas('scope', function (Builder $assignmentScope) use ($companyAccess, $scope): void {
                    if ($companyAccess) {
                        $assignmentScope->where('company_id', $scope->company_id);
                    } else {
                        $assignmentScope->where('scope_key', $scope->scope_key);
                    }
                });
        });
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
