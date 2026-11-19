<?php

namespace App\Authorization;

use App\Models\Role;

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
            'templates.publish' => ['area' => 'master-data', 'name_en' => 'Publish and retire approved templates', 'name_lo' => 'ເຜີຍແຜ່ ແລະ ຖອນແບບເອກະສານທີ່ອະນຸມັດ', 'available' => true],
            'templates.view' => ['area' => 'master-data', 'name_en' => 'View template catalogue', 'name_lo' => 'ເບິ່ງບັນຊີແບບເອກະສານ', 'available' => true],
            'templates.manage' => ['area' => 'master-data', 'name_en' => 'Manage template catalogue', 'name_lo' => 'ຈັດການບັນຊີແບບເອກະສານ', 'available' => true],
            'reports.view' => ['area' => 'reports', 'name_en' => 'View and export operational reports', 'name_lo' => 'ເບິ່ງ ແລະ ສົ່ງອອກລາຍງານການດຳເນີນງານ', 'available' => true],
            'documents.audit.view' => ['area' => 'reports', 'name_en' => 'View document operational audit reports', 'name_lo' => 'ເບິ່ງລາຍງານປະຫວັດການດຳເນີນງານເອກະສານ', 'available' => true],
            'security.audit.view' => ['area' => 'document-security', 'name_en' => 'View protected security-event reports', 'name_lo' => 'ເບິ່ງລາຍງານເຫດການຄວາມປອດໄພ', 'available' => true],
            'documents.attachments.manage' => ['area' => 'documents', 'name_en' => 'Upload and replace document attachments', 'name_lo' => 'ອັບໂຫຼດ ແລະ ທົດແທນໄຟລ໌ແນບ', 'available' => true],
            'documents.custody.manage' => ['area' => 'documents', 'name_en' => 'Manage physical document custody', 'name_lo' => 'ຈັດການການຄອບຄອງເອກະສານ', 'available' => true],
            'storage-locations.manage' => ['area' => 'master-data', 'name_en' => 'Manage physical storage locations', 'name_lo' => 'ຈັດການສະຖານທີ່ເກັບເອກະສານ', 'available' => true],
            'controlled.drafts.manage' => ['area' => 'documents', 'name_en' => 'Manage controlled document drafts', 'name_lo' => 'ຈັດການຮ່າງເອກະສານຄວບຄຸມ', 'available' => true],
            'controlled.register' => ['area' => 'documents', 'name_en' => 'Register controlled documents', 'name_lo' => 'ລົງທະບຽນເອກະສານຄວບຄຸມ', 'available' => true],
            'controlled.release' => ['area' => 'documents', 'name_en' => 'Release controlled revisions', 'name_lo' => 'ປະກາດໃຊ້ສະບັບເອກະສານຄວບຄຸມ', 'available' => true],
            'controlled.approvals.record' => ['area' => 'documents', 'name_en' => 'Record controlled revision approval decisions', 'name_lo' => 'ບັນທຶກຜົນການອະນຸມັດສະບັບເອກະສານຄວບຄຸມ', 'available' => true],
            'controlled.reviews.record' => ['area' => 'documents', 'name_en' => 'Record controlled document reviews', 'name_lo' => 'ບັນທຶກການທົບທວນເອກະສານຄວບຄຸມ', 'available' => true],
            'controlled.status.manage' => ['area' => 'documents', 'name_en' => 'Manage controlled document status', 'name_lo' => 'ຈັດການສະຖານະເອກະສານຄວບຄຸມ', 'available' => true],
            'outgoing.operations.manage' => ['area' => 'documents', 'name_en' => 'Manage outgoing dispatch and delivery', 'name_lo' => 'ຈັດການສົ່ງ ແລະ ຈັດສົ່ງເອກະສານຂາອອກ', 'available' => true],
            'outgoing.operations.reopen' => ['area' => 'documents', 'name_en' => 'Reopen closed outgoing correspondence', 'name_lo' => 'ເປີດຄືນເອກະສານຂາອອກທີ່ປິດແລ້ວ', 'available' => true],
            'outgoing.drafts.manage' => ['area' => 'documents', 'name_en' => 'Manage outgoing drafts', 'name_lo' => 'ຈັດການຮ່າງເອກະສານຂາອອກ', 'available' => true],
            'outgoing.register' => ['area' => 'documents', 'name_en' => 'Register and number outgoing correspondence', 'name_lo' => 'ລົງທະບຽນ ແລະ ອອກເລກເອກະສານຂາອອກ', 'available' => true],
            'incoming.operations.manage' => ['area' => 'documents', 'name_en' => 'Manage incoming correspondence operations', 'name_lo' => 'ຈັດການດຳເນີນງານເອກະສານຂາເຂົ້າ', 'available' => true],
            'incoming.operations.reopen' => ['area' => 'documents', 'name_en' => 'Reopen closed incoming correspondence', 'name_lo' => 'ເປີດຄືນເອກະສານຂາເຂົ້າທີ່ປິດແລ້ວ', 'available' => true],
            'incoming.register' => ['area' => 'documents', 'name_en' => 'Register incoming correspondence', 'name_lo' => 'ລົງທະບຽນເອກະສານຂາເຂົ້າ', 'available' => true],
            'incoming.drafts.manage' => ['area' => 'documents', 'name_en' => 'Manage incoming drafts', 'name_lo' => 'ຈັດການຮ່າງເອກະສານຂາເຂົ້າ', 'available' => true],
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
            'users.manage' => ['area' => 'users', 'name_en' => 'Manage user accounts', 'name_lo' => 'ຈັດການບັນຊີຜູ້ໃຊ້', 'available' => true],
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

    /** @return list<string> */
    public static function standardRoleDefaults(string $code): array
    {
        // The global System Administrator's authority is resolved by AccessControl.
        // Storing its full effective profile would also change the meaning of scoped assignments.
        return $code === Role::SYSTEM_ADMINISTRATOR_CODE
            ? self::ADMINISTRATOR_KEYS
            : self::recommendations()[$code]['permission_keys'] ?? [];
    }

    /** @return array<string, array{permission_keys: list<string>, scope_types: list<string>}> */
    public static function recommendations(): array
    {
        $viewer = ['organization.access', 'documents.metadata.view', 'documents.content.preview', 'documents.attachments.download', 'reports.view'];
        $operationalScopes = ['global', 'company', 'site'];

        return [
            'SECURITY_ADMINISTRATOR' => [
                'permission_keys' => array_keys(array_filter(self::definitions(), fn (array $definition): bool => $definition['available'])),
                'scope_types' => ['global'],
            ],
            'DOCUMENT_CONTROLLER' => [
                'permission_keys' => [...$viewer, 'incoming.drafts.manage', 'incoming.register', 'incoming.operations.manage',
                    'outgoing.drafts.manage', 'outgoing.register', 'outgoing.operations.manage',
                    'controlled.drafts.manage', 'controlled.register', 'controlled.release', 'controlled.status.manage',
                    'numbering.issue', 'documents.attachments.manage', 'documents.custody.manage', 'storage-locations.manage', 'documents.audit.view'],
                'scope_types' => $operationalScopes,
            ],
            'DEPARTMENT_USER' => [
                'permission_keys' => [...$viewer, 'incoming.drafts.manage', 'outgoing.drafts.manage', 'controlled.drafts.manage', 'documents.attachments.manage'],
                'scope_types' => $operationalScopes,
            ],
            'VIEWER' => ['permission_keys' => $viewer, 'scope_types' => $operationalScopes],
        ];
    }
}
