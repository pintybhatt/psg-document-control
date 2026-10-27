<?php

namespace App;

use App\Authorization\DocumentScope;
use App\Models\DocumentCategory;
use App\Models\DocumentType;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

class ManagementReport extends CorrespondenceReport
{
    private const LABELS = [
        'changes_text' => ['Permitted changes', 'ຂໍ້ມູນປ່ຽນແປງທີ່ອະນຸຍາດ'], 'event' => ['Event', 'ເຫດການ'], 'actor' => ['Actor', 'ຜູ້ດຳເນີນການ'], 'timestamp' => ['Time (Laos)', 'ເວລາ (ລາວ)'], 'reason' => ['Reason / note', 'ເຫດຜົນ / ບັນທຶກ'], 'unattributed' => ['Unattributed event', 'ເຫດການບໍ່ລະບຸເອກະສານ'], 'administration' => ['Administrative activity', 'ກິດຈະກຳບໍລິຫານ'], 'issued_number' => ['Number issuance', 'ການອອກເລກທີ'], 'operation' => ['Access operation', 'ການເຂົ້າເຖິງ'], 'outcome' => ['Outcome', 'ຜົນການດຳເນີນງານ'], 'allowed' => ['Allowed', 'ອະນຸຍາດ'], 'denied' => ['Denied', 'ປະຕິເສດ'], 'activity' => ['Other activity', 'ກິດຈະກຳອື່ນ'],
        'view' => ['Report', 'ລາຍງານ'],
        'type_id' => ['Document type', 'ປະເພດເອກະສານ'],
        'category_id' => ['Category', 'ໝວດໝູ່'],
        'owner' => ['Owner / custodian', 'ເຈົ້າຂອງ / ຜູ້ດູແລ'],
        'copy_kind' => ['Original / copy', 'ຕົ້ນສະບັບ / ສຳເນົາ'],
        'revision' => ['Revision', 'ສະບັບປັບປຸງ'],
        'revision_state' => ['Revision status', 'ສະຖານະສະບັບປັບປຸງ'],
        'sort' => ['Sort order', 'ການຈັດລຽງ'],
        'recent' => ['Most recent', 'ໃໝ່ສຸດ'],
        'oldest' => ['Oldest', 'ເກົ່າສຸດ'],
        'date' => ['Document date', 'ວັນທີເອກະສານ'],
        'open' => ['Open registered correspondence', 'ຈົດໝາຍລົງທະບຽນທີ່ຍັງເປີດ'],
        'pending_response' => ['Pending responses', 'ລໍຖ້າການຕອບ'],
        'due_soon' => ['Due within 7 days', 'ຄົບກຳນົດພາຍໃນ 7 ມື້'],
        'review_soon' => ['Review within 30 days', 'ທົບທວນພາຍໃນ 30 ມື້'],
        'review_overdue' => ['Overdue reviews', 'ທົບທວນເກີນກຳນົດ'],
        'checkout_overdue' => ['Overdue checkouts', 'ຢືມເກີນກຳນົດ'],
        'inventory_discrepancy' => ['Outstanding inventory discrepancies', 'ຄວາມບໍ່ກົງກັນໃນການກວດນັບ'],
        'acknowledged' => ['Recipient acknowledgment recorded', 'ບັນທຶກການຢືນຢັນຜູ້ຮັບ'],
        'documents' => ['Document management report', 'ລາຍງານການຈັດການເອກະສານ'], 'physical' => ['Physical custody report', 'ລາຍງານການຄອບຄອງເອກະສານ'],
        'revisions' => ['Controlled revision report', 'ລາຍງານສະບັບປັບປຸງເອກະສານຄວບຄຸມ'], 'audit' => ['Operational audit report', 'ລາຍງານປະຫວັດການດຳເນີນງານ'], 'security' => ['Security-event report', 'ລາຍງານເຫດການຄວາມປອດໄພ'],
        'controlled' => ['Controlled Documents', 'ເອກະສານຄວບຄຸມ'], 'title' => ['Subject / title', 'ເລື່ອງ / ຫົວຂໍ້'], 'register' => ['Register', 'ທະບຽນ'], 'number' => ['Internal number / document code', 'ເລກທະບຽນ / ລະຫັດເອກະສານ'],
        'category' => ['Category / type', 'ໝວດໝູ່ / ປະເພດ'], 'department' => ['Department', 'ພະແນກ'], 'owner_name' => ['Owner', 'ເຈົ້າຂອງ'], 'review_date' => ['Review date', 'ວັນທົບທວນ'],
        'revision_identifier' => ['Revision', 'ສະບັບປັບປຸງ'], 'revision_status' => ['Revision status', 'ສະຖານະສະບັບປັບປຸງ'], 'revision_owner' => ['Revision owner', 'ເຈົ້າຂອງສະບັບປັບປຸງ'], 'effective_date' => ['Effective date', 'ວັນມີຜົນ'], 'revision_review_date' => ['Revision review date', 'ວັນທົບທວນສະບັບປັບປຸງ'],
        'identifier' => ['Physical identifier', 'ລະຫັດເອກະສານ'], 'kind' => ['Original / copy', 'ຕົ້ນສະບັບ / ສຳເນົາ'], 'custody_status' => ['Custody status', 'ສະຖານະການຄອບຄອງ'], 'location' => ['Storage location', 'ບ່ອນເກັບ'], 'custodian' => ['Current custodian', 'ຜູ້ຄອບຄອງປັດຈຸບັນ'], 'checkout_date' => ['Checkout date', 'ວັນຢືມ'], 'expected_return_date' => ['Expected return', 'ກຳນົດສົ່ງຄືນ'],
        'original' => ['Original', 'ຕົ້ນສະບັບ'], 'copy' => ['Copy', 'ສຳເນົາ'], 'stored' => ['In storage', 'ຢູ່ບ່ອນເກັບ'], 'checked_out' => ['Checked out', 'ຖືກຢືມ'], 'missing' => ['Missing', 'ສູນຫາຍ'],
        'current' => ['Current', 'ປັດຈຸບັນ'], 'superseded' => ['Superseded', 'ຖືກທົດແທນ'], 'obsolete' => ['Obsolete', 'ຍົກເລີກ'], 'archived' => ['Archived', 'ຈັດເກັບແລ້ວ'],
        'metric' => ['Measure', 'ຕົວຊີ້ວັດ'], 'date_from' => ['From date', 'ຈາກວັນທີ'], 'date_to' => ['To date', 'ເຖິງວັນທີ'], 'classification' => ['Classification', 'ຊັ້ນຄວາມລັບ'],
        'normal' => ['Normal', 'ປົກກະຕິ'], 'confidential' => ['Confidential', 'ລັບ'], 'highly_confidential' => ['Highly Confidential', 'ລັບສູງ'],
        'empty' => ['No authorized records match these filters.', 'ບໍ່ມີລາຍການທີ່ມີສິດກົງກັບຕົວກອງ.'], 'scope' => ['Authorized workspaces', 'ຂອບເຂດທີ່ມີສິດ'],
    ];

    public function event(string $event, string $language): string
    {
        $events = [
            'created' => ['Draft created', 'ສ້າງຮ່າງ'], 'updated' => ['Metadata updated', 'ປັບປຸງຂໍ້ມູນ'], 'registered' => ['Formally registered', 'ລົງທະບຽນທາງການ'], 'number.issued' => ['Number issued', 'ອອກເລກທີ'],
            'correct_details' => ['Metadata corrected', 'ແກ້ໄຂຂໍ້ມູນ'], 'update' => ['Assignment / handling updated', 'ປັບປຸງການມອບໝາຍ / ດຳເນີນງານ'], 'close' => ['Closed', 'ປິດແລ້ວ'], 'reopen' => ['Reopened', 'ເປີດຄືນ'], 'note' => ['Follow-up added', 'ເພີ່ມການຕິດຕາມ'],
            'dispatch' => ['Dispatched', 'ສົ່ງອອກ'], 'correct_dispatch' => ['Dispatch corrected', 'ແກ້ໄຂການສົ່ງ'], 'delivery' => ['Delivery / acknowledgment updated', 'ປັບປຸງການສົ່ງ / ຢືນຢັນ'], 'link' => ['Document relationship added', 'ເພີ່ມການເຊື່ອມໂຍງ'], 'unlink' => ['Document relationship removed', 'ຍົກເລີກການເຊື່ອມໂຍງ'],
            'revision_prepared' => ['Revision prepared', 'ກະກຽມສະບັບປັບປຸງ'], 'obsolete' => ['Document marked obsolete', 'ຍົກເລີກເອກະສານ'], 'superseded' => ['Document superseded', 'ທົດແທນເອກະສານ'], 'archived' => ['Document archived', 'ຈັດເກັບເອກະສານ'], 'released' => ['Revision released', 'ປະກາດໃຊ້ສະບັບປັບປຸງ'], 'status_changed' => ['Document status changed', 'ປ່ຽນສະຖານະເອກະສານ'],
            'attachment.uploaded' => ['Attachment uploaded', 'ອັບໂຫຼດໄຟລ໌ແນບ'], 'attachment.replaced' => ['Attachment version added', 'ເພີ່ມສະບັບໄຟລ໌ແນບ'], 'attachment.preview' => ['Protected-file preview served', 'ໃຫ້ເບິ່ງໄຟລ໌ທີ່ປ້ອງກັນ'], 'attachment.download' => ['Protected-file download served', 'ໃຫ້ດາວໂຫຼດໄຟລ໌ທີ່ປ້ອງກັນ'], 'attachment.unavailable' => ['File retrieval unavailable', 'ບໍ່ສາມາດຮັບໄຟລ໌'],
            'copy.created' => ['Physical copy registered', 'ລົງທະບຽນສຳເນົາເອກະສານ'], 'location.created' => ['Storage location created', 'ສ້າງບ່ອນເກັບ'], 'custody.checkout' => ['Physical copy checked out', 'ຢືມເອກະສານ'], 'custody.return' => ['Physical copy returned', 'ສົ່ງຄືນເອກະສານ'], 'custody.transfer' => ['Physical custody transferred', 'ໂອນການຄອບຄອງ'], 'custody.correct' => ['Custody corrected', 'ແກ້ໄຂການຄອບຄອງ'], 'custody.inventory' => ['Inventory check recorded', 'ບັນທຶກການກວດນັບ'],
            'access.allowed' => ['Document access allowed', 'ອະນຸຍາດເຂົ້າເຖິງເອກະສານ'], 'access.denied' => ['Document access denied', 'ປະຕິເສດເຂົ້າເຖິງເອກະສານ'], 'request.denied.metadata' => ['Protected request denied', 'ປະຕິເສດຄຳຮ້ອງທີ່ປ້ອງກັນ'], 'request.denied.preview' => ['File preview denied', 'ປະຕິເສດເບິ່ງໄຟລ໌'], 'request.denied.download' => ['File download denied', 'ປະຕິເສດດາວໂຫຼດໄຟລ໌'],
            'profile.registered' => ['Security profile registered', 'ລົງທະບຽນຂໍ້ກຳນົດຄວາມປອດໄພ'], 'profile.restricted' => ['Document access restricted', 'ຈຳກັດສິດເອກະສານ'], 'role.created' => ['Role created', 'ສ້າງບົດບາດ'], 'role.updated' => ['Role updated', 'ປັບປຸງບົດບາດ'], 'assignment.granted' => ['Role assigned', 'ມອບໝາຍບົດບາດ'], 'assignment.revoked' => ['Role assignment revoked', 'ຖອນການມອບໝາຍບົດບາດ'],
            'clearance.granted' => ['Clearance granted', 'ອະນຸຍາດຊັ້ນຄວາມລັບ'], 'clearance.updated' => ['Clearance updated', 'ປັບປຸງຊັ້ນຄວາມລັບ'], 'clearance.revoked' => ['Clearance revoked', 'ຖອນການອະນຸຍາດຊັ້ນຄວາມລັບ'], 'grant.granted' => ['Document access granted', 'ອະນຸຍາດສິດເອກະສານ'], 'grant.updated' => ['Document access grant updated', 'ປັບປຸງສິດເອກະສານ'], 'grant.revoked' => ['Document access grant revoked', 'ຖອນສິດເອກະສານ'],
        ];

        return $events[$event][$language === 'lo' ? 1 : 0] ?? ($language === 'lo' ? 'ບັນທຶກກິດຈະກຳ' : 'Activity recorded');
    }

    public function securityReason(?string $reason, string $language): ?string
    {
        if ($reason === null) {
            return null;
        }
        $reasons = ['permission_denied' => ['Permission denied', 'ບໍ່ມີສິດ'], 'membership_denied' => ['Organizational access denied', 'ບໍ່ມີສິດອົງກອນ'], 'clearance_denied' => ['Clearance required', 'ຕ້ອງມີການອະນຸຍາດຊັ້ນຄວາມລັບ'], 'restriction_denied' => ['Document grant required', 'ຕ້ອງມີສິດເອກະສານ'], 'system_administrator' => ['Privileged administrator access', 'ການເຂົ້າເຖິງຂອງຜູ້ດູແລພິເສດ']];
        if (in_array($reason, ['401', '403', '404', '409', '419', '422', '429', '500', '503'], true)) {
            return ($language === 'lo' ? 'ປະຕິເສດຄຳຮ້ອງ' : 'Request denied').' ('.$reason.')';
        }

        return $reasons[$reason][$language === 'lo' ? 1 : 0] ?? ($language === 'lo' ? 'ບັນທຶກເຫດການຄວາມປອດໄພ' : 'Security activity recorded');
    }

    public function source(string $source, string $language): string
    {
        $key = match ($source) {
            'incoming', 'incoming-operations' => 'incoming', 'outgoing', 'outgoing-operations' => 'outgoing', 'controlled' => 'controlled', 'storage' => 'physical', 'numbering' => 'issued_number', 'security', 'security-storage', 'unattributed-security' => 'security', 'administration', 'global-administration' => 'administration', default => 'unattributed'
        };

        return $this->label($key, $language);
    }

    public function changeValue(string $field, mixed $value, string $language): string
    {
        if (is_bool($value)) {
            return $this->label($value ? 'yes' : 'no', $language);
        }
        if ($value && str_ends_with($field, '_date')) {
            return Carbon::parse($value)->format('d/m/Y');
        }
        if (is_string($value) && in_array($field, ['status', 'classification', 'observation'], true)) {
            return $this->label($value, $language);
        }

        return $this->display($value);
    }

    public function field(string $field, string $language): string
    {
        $value = $this->label($field, $language);

        return $value !== $field ? $value : ($language === 'lo' ? 'ຂໍ້ມູນທີ່ປັບປຸງ' : 'Updated value');
    }

    public function label(string $key, string $language): string
    {
        return self::LABELS[$key][$language === 'lo' ? 1 : 0] ?? parent::label($key, $language);
    }

    /** @param list<array<string, mixed>> $rows
     * @param  array<string, mixed>  $filters
     * @param  list<array<string, mixed>>  $contexts
     */
    public function render(Request $request, array $rows, array $filters, array $contexts): Response
    {
        $language = $request->input('language', $request->user()->preferred_language) === 'lo' ? 'lo' : 'en';
        $view = $filters['view'] ?? 'documents';
        $columns = match ($view) {
            'physical' => ['number', 'title', 'identifier', 'kind', 'custody_status', 'location', 'custodian', 'checkout_date', 'expected_return_date'],
            'revisions' => ['number', 'title', 'revision_identifier', 'revision_status', 'revision_owner', 'effective_date', 'revision_review_date'],
            'audit', 'security' => ['number', 'title', 'event', 'actor', 'timestamp', 'reason', 'changes_text'],
            default => ($filters['register'] ?? '') ? (($filters['register'] === 'controlled') ? ['number', 'title', 'status', 'category', 'owner_name', 'review_date', 'department'] : ['number', 'official_number', 'title', 'document_date', 'status', 'category', 'department']) : ['register', 'number', 'official_number', 'title', 'document_date', 'status', 'category', 'department'],
        };
        $workspace = array_unique(array_map(fn (array $context): string => implode(' / ', array_filter([$context['company']['name_'.$language] ?: $context['company']['name_en'], $context['site']['name_'.$language] ?: $context['site']['name_en'], $context['department']['name_'.$language] ?? null])), $contexts));
        $parameters = [];
        foreach ($filters as $key => $value) {
            if ($value !== null && $value !== '' && ! in_array($key, ['page', 'per_page', 'format', 'language', 'company_id', 'site_id', 'department_id', 'all_departments', 'all_sites'], true)) {
                $caption = $this->label((string) $value, $language);
                if (in_array($key, ['date_from', 'date_to'], true)) {
                    $caption = Carbon::parse($value)->format('d/m/Y');
                }
                if (in_array($key, ['type_id', 'category_id'], true)) {
                    $scopeIds = array_unique(array_map(fn (array $context): ?int => (new DocumentScope($context['company_id'], $context['site_id'], $context['department_id']))->authorizationScope()->id, $contexts));
                    $model = $key === 'type_id' ? DocumentType::class : DocumentCategory::class;
                    $item = $model::query()->whereIn('scope_id', $scopeIds)->find((int) $value);
                    $caption = $item ? ($item->{'name_'.$language} ?: $item->name_en) : (string) $value;
                }
                $parameters[] = $this->label($key, $language).': '.$caption;
            }
        }
        $formatted = array_map(function (array $row) use ($language): array {
            $row['title'] = $language === 'lo' ? ($row['title_lo'] ?: $row['title_en']) : $row['title_en'];
            $row['category'] = ($row['category_'.$language] ?? '').' / '.($row['type_'.$language] ?? '');
            $row['department'] = $row['department_'.$language] ?? '';
            $row['location'] = implode(' → ', array_filter([$row['room'] ?? null, $row['cabinet'] ?? null, $row['shelf'] ?? null, $row['box'] ?? null]));
            foreach (['register', 'status', 'kind', 'custody_status', 'revision_status'] as $key) {
                if (isset($row[$key])) {
                    $row[$key] = $this->label($row[$key], $language);
                }
            }
            foreach (['document_date', 'due_date', 'review_date', 'checkout_date', 'expected_return_date', 'effective_date', 'revision_review_date'] as $key) {
                if (! empty($row[$key])) {
                    $row[$key] = Carbon::parse($row[$key]);
                }
            }

            $row['changes_text'] = implode("\n", array_map(fn (array $change): string => $change['label_'.$language].': '.$this->changeValue($change['field'], $change['before'], $language).' → '.$this->changeValue($change['field'], $change['after'], $language), $row['changes'] ?? []));
            if (isset($row['timestamp'])) {
                $row['timestamp'] = Carbon::parse($row['timestamp'])->timezone('Asia/Vientiane')->format('d/m/Y, H:i');
            }

            return $row;
        }, $rows);

        return $this->exportTable(['language' => $language, 'title' => $this->label($view, $language), 'workspace' => implode(' · ', $workspace), 'generated' => now()->timezone('Asia/Vientiane')->format('d/m/Y, H:i'),
            'filters' => implode(' · ', $parameters) ?: $this->label('none', $language), 'columns' => $columns, 'rows' => $formatted, 'sections' => [], 'detail' => false, 'sheet' => 'DCMS', 'widths' => array_fill(0, count($columns), 100 / count($columns))], $request->input('format', 'xlsx'), 'dcms-'.$view);
    }
}
