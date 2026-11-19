<?php

namespace App\Authorization;

use App\Models\AuthorizationScope;
use App\Models\Permission;
use App\Models\RbacAudit;
use App\Models\Role;
use Illuminate\Validation\ValidationException;

class InitializeRoleDefinitions
{
    public function __construct(private RbacMutation $mutation) {}

    public function run(): void
    {
        $this->mutation->run(function (): void {
            $scope = AuthorizationScope::context('global')->persist();
            foreach (PermissionCatalog::definitions() as $key => $definition) {
                Permission::query()->firstOrCreate(['key' => $key], [
                    'area' => $definition['area'], 'name_en' => $definition['name_en'], 'name_lo' => $definition['name_lo'], 'active' => $definition['available'],
                ]);
            }
            $templates = [
                Role::SYSTEM_ADMINISTRATOR_CODE => ['System Administrator', 'ຜູ້ບໍລິຫານລະບົບ'],
                'DOCUMENT_CONTROLLER' => ['Document Controller', 'ຜູ້ຄວບຄຸມເອກະສານ'],
                'DEPARTMENT_USER' => ['Department User', 'ຜູ້ໃຊ້ພະແນກ'],
                'VIEWER' => ['Viewer', 'ຜູ້ເບິ່ງຂໍ້ມູນ'],
            ];
            foreach ($templates as $code => [$english, $lao]) {
                $role = Role::query()->firstOrCreate(['code' => $code], [
                    'scope_id' => $scope->id, 'name_en' => $english, 'name_lo' => $lao,
                    'description' => Role::STANDARD_DESCRIPTIONS[$code],
                ]);
                if ($role->wasRecentlyCreated) {
                    $role->permissions()->sync(Permission::query()->whereIn('key', PermissionCatalog::standardRoleDefaults($code))->where('active', true)->pluck('id')->all());
                }
            }
        });
    }

    public function enableMembershipAdministration(string $operator): void
    {
        $this->mutation->run(function () use ($operator): void {
            $this->run();
            $role = Role::query()->where('code', 'SECURITY_ADMINISTRATOR')->with(['scope', 'permissions'])->firstOrFail();
            $oldKeys = $role->permissions->pluck('key')->all();
            if (! $role->active || $role->scope->scope_type !== 'global'
                || array_diff(PermissionCatalog::ADMINISTRATOR_KEYS, $role->permissions->where('active', true)->pluck('key')->all()) !== []) {
                throw ValidationException::withMessages(['role' => 'Verify the active Security Administrator definition and its existing administrative permissions first.']);
            }
            $ids = Permission::query()->whereIn('key', PermissionCatalog::MEMBERSHIP_ADMINISTRATOR_KEYS)->where('active', true)->pluck('id')->all();
            if (count($ids) !== count(PermissionCatalog::MEMBERSHIP_ADMINISTRATOR_KEYS)) {
                throw ValidationException::withMessages(['role' => 'Membership administration permissions must be active.']);
            }
            if (array_diff(PermissionCatalog::MEMBERSHIP_ADMINISTRATOR_KEYS, $oldKeys) === []) {
                return;
            }
            $role->permissions()->syncWithoutDetaching($ids);
            RbacAudit::query()->create(['actor_id' => null, 'source' => 'cli', 'action' => 'role.membership-administration-enabled',
                'subject_type' => 'role', 'subject_id' => $role->id, 'scope_id' => $role->scope_id,
                'before' => ['permission_keys' => $oldKeys],
                'after' => ['permission_keys' => $role->permissions()->pluck('key')->all(), 'operator' => $operator]]);
        });
    }

    public function enableOrganizationContext(string $roleCode, string $operator): void
    {
        $this->mutation->run(function () use ($roleCode, $operator): void {
            $this->run();
            $role = Role::query()->where('code', $roleCode)->where('active', true)->with('scope')->first();
            $permission = Permission::query()->where('key', 'organization.access')->where('active', true)->first();
            if (! $role || ! $role->scope->isActive() || ! $permission) {
                throw ValidationException::withMessages(['role' => 'Verify an existing active role, its scope and the organization.access catalog permission first.']);
            }
            $oldKeys = $role->permissions()->pluck('key')->all();
            if (in_array('organization.access', $oldKeys, true)) {
                return;
            }
            $role->permissions()->syncWithoutDetaching([$permission->id]);
            RbacAudit::query()->create(['actor_id' => null, 'source' => 'cli', 'action' => 'role.organization-context-enabled',
                'subject_type' => 'role', 'subject_id' => $role->id, 'scope_id' => $role->scope_id,
                'before' => ['permission_keys' => $oldKeys],
                'after' => ['permission_keys' => $role->permissions()->pluck('key')->all(), 'operator' => $operator]]);
        });
    }
}
