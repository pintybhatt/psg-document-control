<?php

namespace App\Authorization;

class PermissionCatalog
{
    public const ADMINISTRATOR_KEYS = ['users.view', 'roles.view', 'roles.manage', 'assignments.view', 'assignments.manage'];

    /** @return array<string, array{area: string, name_en: string, name_lo: string, available: bool}> */
    public static function definitions(): array
    {
        return [
            'users.view' => ['area' => 'users', 'name_en' => 'View eligible account identities', 'name_lo' => 'ເບິ່ງບັນຊີທີ່ມີສິດ', 'available' => true],
            'users.manage' => ['area' => 'users', 'name_en' => 'Manage accounts (planned)', 'name_lo' => 'ຈັດການບັນຊີ (ຢູ່ໃນແຜນ)', 'available' => false],
            'roles.view' => ['area' => 'roles', 'name_en' => 'View roles and permissions', 'name_lo' => 'ເບິ່ງບົດບາດ ແລະ ສິດ', 'available' => true],
            'roles.manage' => ['area' => 'roles', 'name_en' => 'Configure roles and permissions', 'name_lo' => 'ຕັ້ງຄ່າບົດບາດ ແລະ ສິດ', 'available' => true],
            'assignments.view' => ['area' => 'assignments', 'name_en' => 'View role assignments', 'name_lo' => 'ເບິ່ງການມອບໝາຍບົດບາດ', 'available' => true],
            'assignments.manage' => ['area' => 'assignments', 'name_en' => 'Assign and revoke roles', 'name_lo' => 'ມອບໝາຍ ແລະ ຖອນບົດບາດ', 'available' => true],
            'documents.view' => ['area' => 'documents', 'name_en' => 'View documents (planned)', 'name_lo' => 'ເບິ່ງເອກະສານ (ຢູ່ໃນແຜນ)', 'available' => false],
            'documents.register' => ['area' => 'documents', 'name_en' => 'Register documents (planned)', 'name_lo' => 'ລົງທະບຽນເອກະສານ (ຢູ່ໃນແຜນ)', 'available' => false],
            'documents.control' => ['area' => 'documents', 'name_en' => 'Control documents (planned)', 'name_lo' => 'ຄວບຄຸມເອກະສານ (ຢູ່ໃນແຜນ)', 'available' => false],
        ];
    }

    public static function available(string $key): bool
    {
        return self::definitions()[$key]['available'] ?? false;
    }
}
