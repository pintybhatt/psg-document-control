<?php

namespace App\Authorization;

use App\Models\Department;
use App\Models\Site;
use App\Models\User;

class CorrespondenceContexts
{
    public function __construct(private AccessControl $roles, private MembershipAccess $memberships) {}

    /** @return list<array<string, mixed>> */
    public function forUser(User $actor): array
    {
        $roles = $this->roles;
        $contexts = [];
        foreach (Site::query()->where('active', true)->with('company')->get() as $site) {
            $departments = Department::query()->where('site_id', $site->id)->where('active', true)->get();
            foreach ([null, ...$departments->all()] as $department) {
                $scope = new DocumentScope($site->company_id, $site->id, $department?->id);
                $permissions = $roles->permissions($actor, $scope->authorizationScope(), $department?->id);
                if (! in_array('documents.metadata.view', $roles->grantedPermissions($actor, $scope->authorizationScope()), true)
                    || (! $roles->isSystemAdministrator($actor) && ! $this->memberships->matches($actor, $scope->authorizationScope(), $department?->id))) {
                    continue;
                }
                if (! $roles->allows($actor, 'numbering.issue', $scope->authorizationScope())) {
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
