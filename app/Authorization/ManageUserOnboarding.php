<?php

namespace App\Authorization;

use App\Models\AuthorizationScope;
use App\Models\Department;
use App\Models\OrganizationMembership;
use App\Models\Role;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class ManageUserOnboarding
{
    public function __construct(private AccessControl $access, private MembershipAccess $membershipAccess, private ManageMemberships $memberships, private ManageRoles $roles) {}

    /** @param array{company_id: int, site_id: ?int, department_id?: ?int, department_access?: string, department_ids?: list<int>, role_id: int, confirm_regrant?: bool} $data */
    public function assign(User $actor, User $target, array $data): void
    {
        $membership = new OrganizationMembership([
            'user_id' => $target->id,
            'membership_type' => $data['site_id'] === null ? 'company' : (empty($data['department_id']) ? 'site' : 'department'),
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
        if (! $role || ! $role->active || $role->code === Role::SYSTEM_ADMINISTRATOR_CODE
            || ! $role->scope->covers($scope)) {
            throw ValidationException::withMessages(['access.role_id' => 'userAccessInvalidRole']);
        }
        if ($role->isStandard() && ! $role->permissions->where('active', true)->contains('key', 'documents.metadata.view')) {
            throw ValidationException::withMessages(['access.role_id' => 'userAccessStandardWorkspaceMissing']);
        }
        if ($membership->department_id !== null && ($role->code === Role::DOCUMENT_CONTROLLER_CODE
            || array_intersect(['numbering.issue', 'numbering.view', 'numbering.manage', 'master-data.view', 'master-data.manage'], $role->permissions->pluck('key')->all()) !== [])) {
            throw ValidationException::withMessages(['access.department_id' => 'userAccessSiteRole']);
        }
        $mode = $data['department_access'] ?? null;
        if ($mode === 'selected' || $mode === 'site_wide') {
            $this->ensureCompatibleMemberships($target, $scope, $mode, $data['department_ids'] ?? []);
        }
        if ($mode === 'selected') {
            $departments = Department::query()->whereKey($data['department_ids'])->where('company_id', $scope->company_id)
                ->when($scope->site_id !== null, fn ($query) => $query->where('site_id', $scope->site_id))
                ->where('active', true)->whereHas('site', fn ($query) => $query->where('active', true))->get();
            if ($departments->count() !== count($data['department_ids'])) {
                throw ValidationException::withMessages(['access.department_ids' => 'userAccessInvalidDepartments']);
            }
            foreach ($departments as $department) {
                $this->saveMembership($actor, new OrganizationMembership([
                    'user_id' => $target->id, 'membership_type' => 'department', 'company_id' => $department->company_id,
                    'site_id' => $department->site_id, 'department_id' => $department->id,
                ]), $data['confirm_regrant'] ?? false);
            }
        } else {
            $this->saveMembership($actor, $membership, $data['confirm_regrant'] ?? false, match ($mode) {
                'all' => true,
                'site_wide' => false,
                default => null,
            });
        }
        $this->roles->assign($actor, $scope, $target, $role);
    }

    /** @param list<int> $departmentIds */
    private function ensureCompatibleMemberships(User $target, AuthorizationScope $scope, string $mode, array $departmentIds): void
    {
        $existing = $this->membershipAccess->effectiveQuery()->where('user_id', $target->id)->where('company_id', $scope->company_id);
        if ($scope->site_id !== null) {
            $existing->where(fn ($query) => $query->whereNull('site_id')->orWhere('site_id', $scope->site_id));
        }

        $conflict = $mode === 'selected'
            ? $existing->where(fn ($query) => $query->whereNull('department_id')->orWhereNotIn('department_id', $departmentIds))
            : $existing->where(fn ($query) => $query->whereNotNull('department_id')
                ->orWhere(fn ($query) => $query->where('all_departments', true)
                    ->when($scope->site_id === null, fn ($query) => $query->whereNotNull('site_id'), fn ($query) => $query->whereNull('site_id'))));

        if ($conflict->exists()) {
            throw ValidationException::withMessages(['access.department_access' => 'userAccessConflictingCoverage']);
        }
    }

    private function saveMembership(User $actor, OrganizationMembership $membership, bool $confirmRegrant, ?bool $allDepartments = null): void
    {
        $scope = $membership->authorizationScope();
        $existing = OrganizationMembership::query()->where($membership->only(['user_id', 'membership_type', 'company_id', 'site_id', 'department_id']))->whereNull('revoked_at')->first();
        if ($existing) {
            if (! $this->membershipAccess->effective($existing)) {
                throw ValidationException::withMessages(['access' => 'userAccessInactiveMembership']);
            }
            if ($allDepartments !== null && $existing->all_departments !== $allDepartments) {
                $this->memberships->update($actor, $scope, $existing, true, $allDepartments);
            }

            return;
        }

        $this->memberships->assign($actor, $scope, [
            'user_id' => $membership->user_id, 'unit_type' => $membership->membership_type, 'unit_company_id' => $membership->company_id,
            'unit_site_id' => $membership->site_id, 'unit_department_id' => $membership->department_id,
            'confirm_regrant' => $confirmRegrant, 'all_departments' => $allDepartments ?? false,
        ]);
    }
}
