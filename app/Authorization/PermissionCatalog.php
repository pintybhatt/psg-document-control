<?php

namespace App\Authorization;

class PermissionCatalog
{
    public const ADMINISTRATOR_KEYS = ['users.view', 'roles.view', 'roles.manage', 'assignments.view', 'assignments.manage'];

    public const MEMBERSHIP_ADMINISTRATOR_KEYS = ['memberships.view', 'memberships.manage'];

    public static function administrative(string $key): bool
    {
        return in_array($key, [...self::ADMINISTRATOR_KEYS, ...self::MEMBERSHIP_ADMINISTRATOR_KEYS], true);
    }

    public static function requiresDocument(string $key): bool
    {
        return in_array($key, array_map(fn (DocumentOperation $operation): string => $operation->permission(), DocumentOperation::cases()), true);
    }

    /** @return array<string, array{area: string, name_en: string, name_lo: string, available: bool}> */
    public static function definitions(): array
    {
        return [
            'numbering.view' => ['area' => 'numbering', 'name_en' => 'View numbering configuration', 'name_lo' => 'ເບິ່ງການຕັ້ງຄ່າເລກເອກະສານ', 'available' => true],
            'numbering.manage' => ['area' => 'numbering', 'name_en' => 'Manage numbering configuration', 'name_lo' => 'ຈັດການການຕັ້ງຄ່າເລກເອກະສານ', 'available' => true],
            'numbering.issue' => ['area' => 'numbering', 'name_en' => 'Issue numbers during formal registration', 'name_lo' => 'ອອກເລກໃນການລົງທະບຽນຢ່າງເປັນທາງການ', 'available' => true],
            'master-data.view' => ['area' => 'master-data', 'name_en' => 'View document master data', 'name_lo' => 'ເບິ່ງຂໍ້ມູນຫຼັກເອກະສານ', 'available' => true],
            'master-data.manage' => ['area' => 'master-data', 'name_en' => 'Manage document master data', 'name_lo' => 'ຈັດການຂໍ້ມູນຫຼັກເອກະສານ', 'available' => true],
            'documents.metadata.view' => ['area' => 'document-security', 'name_en' => 'Discover and view document metadata', 'name_lo' => 'ຄົ້ນຫາ ແລະ ເບິ່ງຂໍ້ມູນເອກະສານ', 'available' => true],
            'documents.content.preview' => ['area' => 'document-security', 'name_en' => 'Preview document content', 'name_lo' => 'ເບິ່ງເນື້ອຫາເອກະສານລ່ວງໜ້າ', 'available' => true],
            'documents.attachments.download' => ['area' => 'document-security', 'name_en' => 'Download document attachments', 'name_lo' => 'ດາວໂຫຼດໄຟລ໌ແນບເອກະສານ', 'available' => true],
            'document-access.manage' => ['area' => 'document-security', 'name_en' => 'Manage document security profiles and allowlists', 'name_lo' => 'ຈັດການຂໍ້ກຳນົດຄວາມປອດໄພ ແລະ ລາຍຊື່ສິດເອກະສານ', 'available' => true],
            'clearances.confidential.manage' => ['area' => 'clearances', 'name_en' => 'Manage Confidential clearances', 'name_lo' => 'ຈັດການອະນຸຍາດເຂົ້າເຖິງຂໍ້ມູນລັບ', 'available' => true],
            'clearances.highly-confidential.manage' => ['area' => 'clearances', 'name_en' => 'Manage Highly Confidential clearances', 'name_lo' => 'ຈັດການອະນຸຍາດເຂົ້າເຖິງຂໍ້ມູນລັບສູງ', 'available' => true],
            'memberships.view' => ['area' => 'memberships', 'name_en' => 'View organization memberships', 'name_lo' => 'ເບິ່ງສະມາຊິກອົງກອນ', 'available' => true],
            'memberships.manage' => ['area' => 'memberships', 'name_en' => 'Manage organization memberships', 'name_lo' => 'ຈັດການສະມາຊິກອົງກອນ', 'available' => true],
            'organization.access' => ['area' => 'organization', 'name_en' => 'Use an assigned organization context', 'name_lo' => 'ໃຊ້ຂອບເຂດອົງກອນທີ່ໄດ້ຮັບມອບໝາຍ', 'available' => true],
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
