<?php

namespace App\Authorization;

use App\Models\AuthorizationScope;
use App\Models\Permission;
use App\Models\Role;

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
                'SECURITY_ADMINISTRATOR' => ['Security Administrator', 'ຜູ້ບໍລິຫານຄວາມປອດໄພ'],
                'DOCUMENT_CONTROLLER' => ['Document Controller', 'ຜູ້ຄວບຄຸມເອກະສານ'],
                'DEPARTMENT_USER' => ['Department User', 'ຜູ້ໃຊ້ພະແນກ'],
                'VIEWER' => ['Viewer', 'ຜູ້ເບິ່ງຂໍ້ມູນ'],
            ];
            foreach ($templates as $code => [$english, $lao]) {
                $role = Role::query()->firstOrCreate(['code' => $code], [
                    'scope_id' => $scope->id, 'name_en' => $english, 'name_lo' => $lao,
                    'description' => $code === 'SECURITY_ADMINISTRATOR' ? 'Identity visibility and authorization configuration only; no document access.' : 'Document capabilities remain unavailable until their operations are implemented.',
                ]);
                if ($role->wasRecentlyCreated && $code === 'SECURITY_ADMINISTRATOR') {
                    $role->permissions()->sync(Permission::query()->whereIn('key', PermissionCatalog::ADMINISTRATOR_KEYS)->pluck('id')->all());
                }
            }
        });
    }
}
