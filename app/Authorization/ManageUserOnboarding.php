<?php

namespace App\Authorization;

use App\Models\OrganizationMembership;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class ManageUserOnboarding
{
    public function __construct(private AccessControl $access, private MembershipAccess $membershipAccess, private ManageMemberships $memberships, private ManageRoles $roles) {}

    /** @param array{company_id: int, site_id: int, department_id?: ?int, role_id: int, confirm_regrant?: bool} $data */
    public function assign(User $actor, User $target, array $data): void
    {
        $membership = new OrganizationMembership([
            'user_id' => $target->id, 'membership_type' => empty($data['department_id']) ? 'site' : 'department',
            'company_id' => $data['company_id'], 'site_id' => $data['site_id'], 'department_id' => $data['department_id'] ?? null,
        ]);
        $this->membershipAccess->validateUnit($membership);
        $scope = $membership->authorizationScope();
        foreach (['memberships.manage', 'assignments.manage', 'users.view', 'roles.view'] as $permission) {
            $this->access->authorize($actor, $permission, $scope);
        }
        if (! $target->active || ! $scope->isActive() || ($membership->department_id !== null && ! $membership->department->active)) {
            throw ValidationException::withMessages(['access' => 'userAccessInactive']);
        }
        $role = $this->access->visibleRoles($scope)->whereKey($data['role_id'])->with('permissions')->first();
        $workspacePermissions = $role?->permissions->filter(fn (Permission $permission): bool => $permission->active)->pluck('key')->all() ?? [];
        if (! $role || ! $role->active || $role->code === Role::SYSTEM_ADMINISTRATOR_CODE
            || ! $role->scope->covers($scope) || array_diff(['organization.access', 'documents.metadata.view'], $workspacePermissions) !== []) {
            throw ValidationException::withMessages(['access.role_id' => 'userAccessInvalidRole']);
        }
        if ($membership->department_id !== null && ($role->code === 'DOCUMENT_CONTROLLER'
            || array_intersect(['numbering.issue', 'numbering.view', 'numbering.manage', 'master-data.view', 'master-data.manage'], $workspacePermissions) !== [])) {
            throw ValidationException::withMessages(['access.department_id' => 'userAccessSiteRole']);
        }
        $existing = OrganizationMembership::query()->where($membership->only(['user_id', 'membership_type', 'company_id', 'site_id', 'department_id']))->whereNull('revoked_at')->first();
        if ($existing) {
            if (! $this->membershipAccess->effective($existing)) {
                throw ValidationException::withMessages(['access' => 'userAccessInactiveMembership']);
            }
        } else {
            $this->memberships->assign($actor, $scope, [
                'user_id' => $target->id, 'unit_type' => $membership->membership_type, 'unit_company_id' => $membership->company_id,
                'unit_site_id' => $membership->site_id, 'unit_department_id' => $membership->department_id,
                'confirm_regrant' => $data['confirm_regrant'] ?? false,
            ]);
        }
        $this->roles->assign($actor, $scope, $target, $role);
    }
}
