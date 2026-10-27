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
            'audit', 'security' => ['number', 'title', 'event', 'actor', 'timestamp', 'reason'],
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

            return $row;
        }, $rows);

        return $this->exportTable(['language' => $language, 'title' => $this->label($view, $language), 'workspace' => implode(' · ', $workspace), 'generated' => now()->timezone('Asia/Vientiane')->format('d/m/Y, H:i'),
            'filters' => implode(' · ', $parameters) ?: $this->label('none', $language), 'columns' => $columns, 'rows' => $formatted, 'sections' => [], 'detail' => false, 'sheet' => 'DCMS', 'widths' => array_fill(0, count($columns), 100 / count($columns))], $request->input('format', 'xlsx'), 'dcms-'.$view);
    }
}
