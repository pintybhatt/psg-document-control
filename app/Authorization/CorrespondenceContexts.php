<?php

namespace App\Authorization;

use App\Models\AuthorizationScope;
use App\Models\OrganizationMembership;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CorrespondenceContexts
{
    public function __construct(private AccessControl $roles, private MembershipAccess $memberships) {}

    /** @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    public function forUser(User $actor, array $filters = []): array
    {
        $roles = $this->roles;
        $isSystemAdministrator = $roles->isSystemAdministrator($actor);
        $memberships = $this->memberships->effectiveQuery()->where('user_id', $actor->id)->get();
        $sites = Site::query()->where('active', true)->whereHas('company', fn (Builder $query): Builder => $query->where('active', true))
            ->when(empty($filters['all_sites']) && isset($filters['company_id']), fn (Builder $query): Builder => $query->where('company_id', $filters['company_id']))
            ->when(empty($filters['all_sites']) && isset($filters['site_id']), fn (Builder $query): Builder => $query->whereKey($filters['site_id']))
            ->with(['company', 'departments' => fn (HasMany $query): HasMany => $query->where('active', true)])->get();
        $contexts = [];
        foreach ($sites as $site) {
            $scope = AuthorizationScope::context('site', $site->company_id, $site->id);
            $granted = $roles->grantedPermissions($actor, $scope);
            if (! in_array('documents.metadata.view', $granted, true)) {
                continue;
            }
            $siteMemberships = $memberships->where('company_id', $site->company_id)->where('site_id', $site->id);
            $hasSiteMembership = $siteMemberships->contains(fn (OrganizationMembership $membership): bool => $membership->membership_type === 'site' && $membership->department_id === null);
            foreach ([null, ...$site->departments->all()] as $department) {
                if (array_key_exists('all_departments', $filters) && empty($filters['all_departments'])
                    && $department?->id !== (isset($filters['department_id']) ? (int) $filters['department_id'] : null)) {
                    continue;
                }
                $hasMembership = $department === null ? $hasSiteMembership : $siteMemberships->contains(fn (OrganizationMembership $membership): bool => $membership->membership_type === 'department' && $membership->department_id === $department->id);
                if (! $isSystemAdministrator && ! $hasMembership) {
                    continue;
                }
                $permissions = array_values(array_filter($granted, fn (string $key): bool => ! PermissionCatalog::requiresDocument($key)
                    && ($isSystemAdministrator || ! PermissionCatalog::administrative($key) || $department === null)));
                if (! $isSystemAdministrator && ! $hasSiteMembership) {
                    $permissions = array_values(array_diff($permissions, ['numbering.issue']));
                }
                $contexts[] = ['key' => $site->id.':'.($department?->id ?? 'site'), 'company_id' => $site->company_id, 'site_id' => $site->id,
                    'department_id' => $department?->id, 'company' => $site->company->only(['id', 'name_en', 'name_lo']),
                    'site' => $site->only(['id', 'name_en', 'name_lo']), 'department' => $department?->only(['id', 'name_en', 'name_lo']),
                    'permissions' => $permissions];
            }
        }

        return $contexts;
    }
}
