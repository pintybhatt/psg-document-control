<?php

namespace App\Authorization;

use App\Models\AuthorizationScope;
use App\Models\Company;
use App\Models\Department;
use App\Models\OrganizationMembership;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class MembershipAccess
{
    public function validateUnit(OrganizationMembership $membership): void
    {
        $valid = match ($membership->membership_type) {
            'company' => $membership->site_id === null && $membership->department_id === null,
            'site' => $membership->site_id !== null && $membership->department_id === null,
            'department' => $membership->site_id !== null && $membership->department_id !== null,
            default => false,
        };
        if (! $valid || ! Company::query()->whereKey($membership->company_id)->exists()
            || ($membership->site_id !== null && ! Site::query()->whereKey($membership->site_id)->where('company_id', $membership->company_id)->exists())
            || ($membership->department_id !== null && ! Department::query()->whereKey($membership->department_id)->where('site_id', $membership->site_id)->where('company_id', $membership->company_id)->exists())) {
            throw ValidationException::withMessages(['membership' => 'membershipInvalidUnit']);
        }
    }

    /** @return Builder<OrganizationMembership> */
    public function effectiveQuery(): Builder
    {
        return OrganizationMembership::query()->where('active', true)->whereNull('revoked_at')
            ->whereHas('user', fn (Builder $query): Builder => $query->where('active', true))
            ->whereHas('company', fn (Builder $query): Builder => $query->where('active', true))
            ->where(fn (Builder $query): Builder => $query->whereNull('site_id')->orWhereHas('site', fn (Builder $site): Builder => $site->where('active', true)))
            ->where(fn (Builder $query): Builder => $query->whereNull('department_id')->orWhereHas('department', fn (Builder $department): Builder => $department->where('active', true)));
    }

    public function effective(OrganizationMembership $membership): bool
    {
        return $this->effectiveQuery()->whereKey($membership->id)->exists();
    }

    public function matches(User $user, AuthorizationScope $scope, ?int $departmentId = null): bool
    {
        if ($scope->scope_type === 'global' || ! $scope->isActive()) {
            return false;
        }
        if ($departmentId !== null && ($scope->scope_type !== 'site' || ! Department::query()->whereKey($departmentId)
            ->where('company_id', $scope->company_id)->where('site_id', $scope->site_id)->where('active', true)->exists())) {
            return false;
        }

        return $this->effectiveUnitQuery($scope->company_id, $scope->site_id, $departmentId)->where('user_id', $user->id)->exists();
    }

    public function matchesWorkspace(User $user, AuthorizationScope $scope, ?int $departmentId = null): bool
    {
        if ($scope->scope_type !== 'site' || $departmentId !== null) {
            return $this->matches($user, $scope, $departmentId);
        }

        return $scope->isActive() && $this->effectiveWorkspaceQuery($scope->company_id, $scope->site_id)->where('user_id', $user->id)->exists();
    }

    /** @return Builder<OrganizationMembership> */
    public function effectiveWorkspaceQuery(int $companyId, int $siteId, ?int $departmentId = null): Builder
    {
        if ($departmentId !== null) {
            return $this->effectiveUnitQuery($companyId, $siteId, $departmentId);
        }

        return $this->effectiveQuery()->where('company_id', $companyId)->whereNull('department_id')
            ->where(fn (Builder $query): Builder => $query
                ->where(fn (Builder $site): Builder => $site->where('membership_type', 'site')->where('site_id', $siteId))
                ->orWhere(fn (Builder $company): Builder => $company->where('membership_type', 'company')->whereNull('site_id')));
    }

    /** @return Builder<OrganizationMembership> */
    private function effectiveUnitQuery(int $companyId, ?int $siteId, ?int $departmentId): Builder
    {
        $type = $departmentId !== null ? 'department' : ($siteId !== null ? 'site' : 'company');

        return $this->effectiveQuery()->where('membership_type', $type)
            ->where('company_id', $companyId)->where('site_id', $siteId)->where('department_id', $departmentId);
    }

    /** @return Builder<OrganizationMembership> */
    public function visible(AuthorizationScope $scope): Builder
    {
        return OrganizationMembership::query()
            ->when($scope->company_id !== null, fn (Builder $query): Builder => $query->where('company_id', $scope->company_id))
            ->when($scope->scope_type === 'site', fn (Builder $query): Builder => $query->where('site_id', $scope->site_id));
    }

    /** @return Builder<User> */
    public function eligibleUsers(User $actor, AuthorizationScope $scope): Builder
    {
        return app(AccessControl::class)->eligibleUsers($actor, $scope);
    }
}
