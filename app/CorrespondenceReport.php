<?php

namespace App;

use App\Authorization\ManageIncomingOperations;
use App\Authorization\ManageOutgoingOperations;
use App\Models\Company;
use App\Models\Department;
use App\Models\IncomingCorrespondence;
use App\Models\OutgoingCorrespondence;
use App\Models\PhysicalCopy;
use App\Models\Site;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;
use Mpdf\HTMLParserMode;
use Mpdf\Mpdf;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class CorrespondenceReport
{
    /** @var array<string, array{string, string}> */
    private const LABELS = [
        'category' => ['Document category', 'ໝວດເອກະສານ'],
        'document_reference' => ['Document reference', 'ເລກອ້າງອີງເອກະສານ'],
        'document_revision' => ['Revision', 'ສະບັບແກ້ໄຂ'],
        'handling' => ['Responsible department / person', 'ພະແນກ / ຜູ້ຮັບຜິດຊອບ'],
        'related_references' => ['Related / reply references', 'ເລກອ້າງອີງເອກະສານກ່ຽວຂ້ອງ / ຕອບກັບ'],
        'physical_references' => ['Physical copy / folder reference', 'ເລກອ້າງອີງສຳເນົາ / ແຟ້ມເອກະສານ'],
        'related_outgoing' => ['Linked outgoing correspondence', 'ເອກະສານຂາອອກທີ່ເຊື່ອມໂຍງ'],
        'incoming' => ['Incoming correspondence register', 'ທະບຽນເອກະສານຂາເຂົ້າ'],
        'outgoing' => ['Outgoing correspondence register', 'ທະບຽນເອກະສານຂາອອກ'],
        'detail' => ['Correspondence details', 'ລາຍລະອຽດເອກະສານ'],
        'generated' => ['Generated', 'ສ້າງເມື່ອ'], 'filters' => ['Applied filters', 'ຕົວກອງທີ່ນຳໃຊ້'],
        'none' => ['None', 'ບໍ່ມີ'], 'total' => ['Total records', 'ຈຳນວນລາຍການ'],
        'empty' => ['No correspondence matches these filters.', 'ບໍ່ມີເອກະສານທີ່ກົງກັບຕົວກອງ.'],
        'correct_details' => ['Details corrected', 'ແກ້ໄຂລາຍລະອຽດແລ້ວ'],
        'before' => ['Before', 'ກ່ອນ'], 'after' => ['After', 'ຫຼັງ'], 'page' => ['Page', 'ໜ້າ'], 'of' => ['of', 'ຈາກ'], 'yes' => ['Yes', 'ແມ່ນ'], 'no' => ['No', 'ບໍ່'],
        'internal_number' => ['Internal number', 'ເລກທະບຽນພາຍໃນ'], 'official_number' => ['Official number', 'ເລກເອກະສານທາງການ'],
        'subject' => ['Subject', 'ຫົວຂໍ້'], 'sender_organization' => ['Sender', 'ຜູ້ສົ່ງ'],
        'recipient' => ['Recipient', 'ຜູ້ຮັບ'],
        'recipients' => ['Primary recipient', 'ຜູ້ຮັບຫຼັກ'], 'received_date' => ['Received date', 'ວັນທີຮັບ'],
        'correspondence_date' => ['Document date', 'ວັນທີເອກະສານ'], 'document_date' => ['Document date', 'ວັນທີເອກະສານ'],
        'channel' => ['Channel', 'ຊ່ອງທາງ'], 'status' => ['Operational status', 'ສະຖານະ'], 'due_date' => ['Due date', 'ວັນຄົບກຳນົດ'],
        'response_required' => ['Response required', 'ຕ້ອງຕອບ'], 'response_completed' => ['Response completed', 'ຕອບແລ້ວ'],
        'dispatch_date' => ['Dispatch date', 'ວັນທີສົ່ງອອກ'], 'dispatch_channel' => ['Dispatch channel', 'ຊ່ອງທາງສົ່ງອອກ'],
        'dispatch_reference' => ['Dispatch reference', 'ເລກອ້າງອີງການສົ່ງ'], 'dispatch_remarks' => ['Dispatch remarks', 'ໝາຍເຫດການສົ່ງ'],
        'search' => ['Search', 'ຄົ້ນຫາ'], 'filter_due' => ['Due date', 'ວັນຄົບກຳນົດ'], 'filter_response' => ['Response', 'ການຕອບ'],
        'draft' => ['Draft', 'ຮ່າງ'], 'registered' => ['Registered', 'ລົງທະບຽນແລ້ວ'], 'received' => ['Received', 'ໄດ້ຮັບແລ້ວ'],
        'assigned' => ['Assigned', 'ມອບໝາຍແລ້ວ'], 'in_progress' => ['In progress', 'ກຳລັງດຳເນີນ'], 'responded' => ['Responded', 'ຕອບແລ້ວ'],
        'closed' => ['Closed', 'ປິດແລ້ວ'], 'dispatched' => ['Dispatched', 'ສົ່ງອອກແລ້ວ'], 'delivered' => ['Delivered', 'ສົ່ງເຖິງແລ້ວ'],
        'email' => ['Email', 'ອີເມວ'], 'courier' => ['Courier', 'ຂົນສົ່ງດ່ວນ'], 'hand_delivery' => ['Hand delivery', 'ສົ່ງດ້ວຍຕົນເອງ'],
        'post' => ['Post', 'ໄປສະນີ'], 'other' => ['Other', 'ອື່ນໆ'], 'overdue' => ['Overdue', 'ເກີນກຳນົດ'], 'today' => ['Due today', 'ຄົບກຳນົດມື້ນີ້'],
        'pending' => ['Awaiting response', 'ລໍຖ້າການຕອບ'], 'completed' => ['Completed', 'ສຳເລັດແລ້ວ'],
        'classification' => ['Classification', 'ຊັ້ນຄວາມລັບ'], 'normal' => ['Normal', 'ປົກກະຕິ'], 'urgent' => ['Urgent', 'ດ່ວນ'],
        'confidential' => ['Confidential', 'ລັບ'], 'highly_confidential' => ['Highly confidential', 'ລັບສູງສຸດ'],
        'type' => ['Document type', 'ປະເພດເອກະສານ'], 'priority' => ['Priority', 'ຄວາມສຳຄັນ'],
        'external_reference' => ['External reference', 'ເລກອ້າງອີງພາຍນອກ'], 'sender_contact' => ['Sender contact', 'ຜູ້ຕິດຕໍ່ຜູ້ສົ່ງ'],
        'sender_email' => ['Sender email', 'ອີເມວຜູ້ສົ່ງ'], 'sender_phone' => ['Sender phone', 'ເບີໂທຜູ້ສົ່ງ'],
        'signatory_name' => ['Signatory', 'ຜູ້ລົງນາມ'], 'signatory_title' => ['Signatory title', 'ຕຳແໜ່ງຜູ້ລົງນາມ'],
        'description_en' => ['Description (English)', 'ລາຍລະອຽດ (ອັງກິດ)'], 'description_lo' => ['Description (Lao)', 'ລາຍລະອຽດ (ລາວ)'],
        'registered_at' => ['Registration date', 'ວັນທີລົງທະບຽນ'], 'registered_by' => ['Registered by (user ID)', 'ຜູ້ລົງທະບຽນ (ລະຫັດຜູ້ໃຊ້)'],
        'operations' => ['Current handling', 'ການດຳເນີນງານປັດຈຸບັນ'], 'history' => ['Operational history', 'ປະຫວັດການດຳເນີນງານ'],
        'registration_history' => ['Registration history', 'ປະຫວັດການລົງທະບຽນ'], 'related' => ['Related correspondence', 'ເອກະສານທີ່ກ່ຽວຂ້ອງ'],
        'created' => ['Draft created', 'ສ້າງຮ່າງແລ້ວ'], 'updated' => ['Updated', 'ປັບປຸງແລ້ວ'], 'noted' => ['Follow-up added', 'ເພີ່ມການຕິດຕາມ'],
        'linked' => ['Linked', 'ເຊື່ອມໂຍງແລ້ວ'], 'unlinked' => ['Unlinked', 'ຍົກເລີກການເຊື່ອມໂຍງ'], 'reopened' => ['Reopened', 'ເປີດຄືນແລ້ວ'],
        'dispatch' => ['Dispatched', 'ສົ່ງອອກແລ້ວ'], 'correct_dispatch' => ['Dispatch corrected', 'ແກ້ໄຂການສົ່ງແລ້ວ'],
        'delivery' => ['Delivery updated', 'ປັບປຸງການສົ່ງເຖິງ'], 'close' => ['Closed', 'ປິດແລ້ວ'], 'reopen' => ['Reopened', 'ເປີດຄືນແລ້ວ'],
        'note' => ['Follow-up added', 'ເພີ່ມການຕິດຕາມ'], 'link' => ['Linked', 'ເຊື່ອມໂຍງແລ້ວ'], 'unlink' => ['Unlinked', 'ຍົກເລີກການເຊື່ອມໂຍງ'],
        'dispatch_corrected' => ['Dispatch corrected', 'ແກ້ໄຂການສົ່ງອອກແລ້ວ'], 'delivery_updated' => ['Delivery updated', 'ປັບປຸງການສົ່ງແລ້ວ'],
        'responsible_user_id' => ['Responsible person', 'ຜູ້ຮັບຜິດຊອບ'], 'responsible_department_id' => ['Responsible department', 'ພະແນກຮັບຜິດຊອບ'],
        'response_completed_at' => ['Response completed at', 'ເວລາຕອບສຳເລັດ'], 'response_completed_by' => ['Response completed by', 'ຜູ້ຕອບສຳເລັດ'],
        'closed_at' => ['Closed at', 'ເວລາປິດ'], 'closed_by' => ['Closed by', 'ຜູ້ປິດ'], 'closure_remarks' => ['Closure remarks', 'ໝາຍເຫດການປິດ'],
        'related_number' => ['Related number', 'ເລກເອກະສານກ່ຽວຂ້ອງ'], 'incoming_number' => ['Incoming number', 'ເລກເອກະສານຂາເຂົ້າ'],
        'primary' => ['Primary recipient', 'ຜູ້ຮັບຫຼັກ'], 'copy' => ['Copy recipient', 'ຜູ້ຮັບສຳເນົາ'],
        'organization' => ['Organization', 'ອົງກອນ'], 'contact_name' => ['Contact', 'ຜູ້ຕິດຕໍ່'], 'phone' => ['Phone', 'ເບີໂທ'], 'address' => ['Address', 'ທີ່ຢູ່'],
        'delivery_date' => ['Delivery date', 'ວັນທີສົ່ງເຖິງ'], 'acknowledged_by' => ['Acknowledged by', 'ຜູ້ຢືນຢັນຮັບ'],
        'acknowledgment_date' => ['Acknowledgment date', 'ວັນທີຢືນຢັນຮັບ'], 'acknowledgment_reference' => ['Acknowledgment reference', 'ເລກອ້າງອີງການຮັບ'],
        'recipient' => ['Recipient', 'ຜູ້ຮັບ'], 'recipient_id' => ['Recipient ID', 'ລະຫັດຜູ້ຮັບ'], 'actor' => ['Actor', 'ຜູ້ດຳເນີນການ'],
    ];

    public function label(string $key, string $language): string
    {
        return self::LABELS[$key][$language === 'lo' ? 1 : 0] ?? $key;
    }

    public function display(mixed $value): string
    {
        return $value instanceof DateTimeInterface ? $value->format('d/m/Y') : ($value === null || $value === '' ? '—' : (string) $value);
    }

    /** Shared XLSX/PDF renderer for already-authorized tabular reports.
     * @param  array<string, mixed>  $data
     */
    public function exportTable(array $data, string $format, string $filename): Response
    {
        $pdf = $format === 'pdf' ? $this->pdf($data) : null;

        return $this->download($pdf['content'] ?? $this->xlsx($data), $filename.'.'.$format, $format, $pdf['pages'] ?? null);
    }

    /** @param iterable<IncomingCorrespondence|OutgoingCorrespondence> $records */
    public function register(Request $request, string $kind, iterable $records): Response
    {
        $data = $this->metadata($request, $kind);
        $columns = $kind === 'incoming'
            ? ['internal_number', 'subject', 'sender_organization', 'received_date', 'channel', 'status', 'due_date', 'response_required', 'response_completed']
            : ['internal_number', 'official_number', 'subject', 'recipients', 'document_date', 'status', 'dispatch_date', 'dispatch_channel', 'dispatch_reference'];
        $columns = [...$columns, 'category', ...($kind === 'incoming' ? ['document_reference', 'document_date'] : []), 'document_revision', 'registered_at', 'handling', 'priority', 'related_references', 'physical_references'];
        $rows = [];
        foreach ($records as $record) {
            $state = $record->operation;
            $row = ['internal_number' => $record->internal_number, 'subject' => $record->subject,
                'status' => $this->label($state?->status ?? ($record->status === 'registered' && $kind === 'incoming' ? 'received' : $record->status), $data['language'])];
            $row += $kind === 'incoming'
                ? ['sender_organization' => $record->sender_organization, 'received_date' => $record->received_date, 'channel' => $record->channel ? $this->label($record->channel, $data['language']) : null,
                    'due_date' => $state?->due_date, 'response_required' => $this->label($state?->response_required ? 'yes' : 'no', $data['language']), 'response_completed' => $this->label($state?->response_completed_at ? 'yes' : 'no', $data['language'])]
                : ['official_number' => $record->official_number, 'document_date' => $record->document_date,
                    'recipients' => $record->recipients->where('kind', 'primary')->map(fn ($recipient): string => implode(' · ', array_filter([$recipient->organization, $recipient->contact_name])))->implode("\n"),
                    'dispatch_date' => $state?->dispatch_date, 'dispatch_channel' => $state?->dispatch_channel ? $this->label($state->dispatch_channel, $data['language']) : null, 'dispatch_reference' => $state?->dispatch_reference];
            $language = $data['language'];
            $related = $this->related($request->user(), $record);
            $row += ['category' => $record->type->category->{'name_'.$language},
                'document_reference' => $kind === 'incoming' ? $record->external_reference : $record->official_number,
                'document_revision' => $record->document_revision,
                'registered_at' => $record->registered_at?->setTimezone('Asia/Vientiane')->startOfDay(),
                'priority' => $this->label($record->priority, $language),
                'related_references' => implode("\n", array_map(fn (array $item): string => $item['official_number'] ?? $item['internal_number'], $related)),
                'physical_references' => $this->physicalReferences($record)];
            $row['handling'] = implode(' / ', array_filter([
                Department::query()->find($kind === 'incoming' ? ($state?->responsible_department_id ?? $record->profile->department_id) : $record->profile->department_id)?->{'name_'.$language},
                $kind === 'incoming' && $state?->responsible_user_id ? User::query()->find($state->responsible_user_id)?->name : null,
            ]));
            if ($kind === 'incoming') {
                $row['document_date'] = $record->correspondence_date;
            }
            $rows[] = $row;
        }
        $columns = array_values(array_filter($columns, fn (string $column): bool => ! in_array($column, ['handling', 'related_references', 'physical_references'], true)
            || array_filter(array_column($rows, $column)) !== []));
        $data += ['columns' => $columns, 'rows' => $rows, 'sections' => [], 'detail' => false, 'compact_register' => true];
        $format = $request->input('format', 'xlsx');
        $pdf = $format === 'pdf' ? $this->pdf($data) : null;

        return $this->download($pdf['content'] ?? $this->xlsx($data), $kind.'-correspondence.'.$format, $format, $pdf['pages'] ?? null);
    }

    /** @param array<string, mixed> $record
     * @param  array<string, mixed>  $details
     * @param  iterable<Model>  $audits
     */
    public function detail(Request $request, string $kind, array $record, array $details, iterable $audits): Response
    {
        $data = $this->metadata($request, $kind);
        $language = $data['language'];
        $fields = ['internal_number', ...($kind === 'outgoing' ? ['official_number', 'signatory_name', 'signatory_title', 'document_date'] : ['sender_organization', 'sender_contact', 'sender_email', 'sender_phone', 'external_reference', 'correspondence_date', 'received_date', 'channel']), 'subject', 'document_revision', 'status', 'type', 'classification', 'priority', 'description_en', 'description_lo', 'registered_at', 'registered_by'];
        $record['status'] = $record['operations']['status'] ?? $record['status'];
        $sections = [['title' => $record['subject'], 'fields' => $this->fields(array_intersect_key($record, array_flip($fields)), $language)]];
        if ($record['operations']) {
            $state = $record['operations'];
            unset($state['revision']);
            if ($kind === 'incoming') {
                $state['responsible_user_id'] = $details['responsible_user']['name'] ?? null;
                $state['responsible_department_id'] = $details['responsible_department'] ?? null;
            }
            $sections[] = ['title' => $this->label('operations', $language), 'fields' => $this->fields($state, $language)];
        }
        foreach ($record['recipients'] ?? [] as $recipient) {
            $sections[] = ['title' => $this->label($recipient['kind'], $language), 'fields' => $this->fields(array_diff_key($recipient, array_flip(['id', 'kind', 'delivery'])) + ($recipient['delivery'] ?? []), $language)];
        }
        foreach ($details['history'] ?? [] as $item) {
            if (isset($item['after']['details'])) {
                $item['action'] = 'correct_details';
                $item['before'] = $item['before']['details'];
                $item['after'] = $item['after']['details'];
            }
            $changes = [];
            foreach (array_unique([...array_keys($item['before']), ...array_keys($item['after'])]) as $field) {
                $changes[] = ['label' => $this->label($field, $language), 'value' => $this->label('before', $language).': '.$this->fieldValue($field, $item['before'][$field] ?? null, $language)."\n".$this->label('after', $language).': '.$this->fieldValue($field, $item['after'][$field] ?? null, $language)];
            }
            $sections[] = ['title' => $this->label('history', $language).' · '.$this->label($item['action'], $language).' · '.$item['actor_name'].' · '.$this->fieldValue('created_at', $item['created_at'], $language), 'text' => $item['note'], 'fields' => $changes];
        }
        $registration = [];
        foreach ($audits as $audit) {
            $registration[] = ['label' => $this->label($audit->action, $language), 'value' => $this->dateTime($audit->created_at).' · '.$this->label('actor', $language).' '.$audit->actor_id];
        }
        $sections[] = ['title' => $this->label('registration_history', $language), 'fields' => $registration];
        $related = $details['related'] ?? $details['related_incoming'] ?? [];
        if (count($related)) {
            $sections[] = ['title' => $this->label('related', $language), 'fields' => array_map(fn (array $item): array => ['label' => $item['internal_number'], 'value' => $item['subject']], $related)];
        }
        if (! empty($details['related_outgoing'])) {
            $sections[] = ['title' => $this->label('related_outgoing', $language), 'fields' => array_map(fn (array $item): array => ['label' => $item['official_number'] ?? $item['internal_number'], 'value' => $item['subject']], $details['related_outgoing'])];
        }
        $data += ['columns' => [], 'rows' => [], 'sections' => $sections, 'detail' => true];

        $pdf = $this->pdf($data);

        return $this->download($pdf['content'], $kind.'-correspondence-'.$record['id'].'.pdf', 'pdf', $pdf['pages']);
    }

    /** @return list<array<string, mixed>> */
    private function related(User $actor, IncomingCorrespondence|OutgoingCorrespondence $record): array
    {
        return $record instanceof IncomingCorrespondence
            ? [...app(ManageIncomingOperations::class)->related($actor, $record), ...app(ManageIncomingOperations::class)->relatedOutgoing($actor, $record)]
            : app(ManageOutgoingOperations::class)->relatedIncoming($actor, $record);
    }

    private function physicalReferences(IncomingCorrespondence|OutgoingCorrespondence $record): string
    {
        return PhysicalCopy::query()->where('profile_id', $record->profile_id)->with('location')->orderBy('identifier')->get()
            ->map(fn (PhysicalCopy $copy): string => $copy->identifier.' · '.$copy->location->label())->implode("\n");
    }

    /** @return array<string, mixed> */
    private function metadata(Request $request, string $kind): array
    {
        $language = $request->input('language', $request->user()->preferred_language) === 'lo' ? 'lo' : 'en';
        $name = 'name_'.$language;
        $workspace = [Company::query()->findOrFail($request->integer('company_id'))->$name, Site::query()->findOrFail($request->integer('site_id'))->$name];
        if ($request->filled('department_id')) {
            $workspace[] = Department::query()->findOrFail($request->integer('department_id'))->$name;
        }
        $filters = [];
        foreach ($kind === 'incoming' ? ['search', 'filter_status', 'filter_channel', 'filter_due', 'filter_response'] : ['search'] as $key) {
            if ($request->filled($key)) {
                $label = match ($key) {
                    'filter_status' => 'status', 'filter_channel' => 'channel', default => $key
                };
                $filters[] = $this->label($label, $language).': '.($key === 'search' ? $request->input($key) : $this->label($request->input($key), $language));
            }
        }

        return ['language' => $language, 'title' => $this->label($kind, $language), 'workspace' => implode(' / ', $workspace),
            'generated' => $this->dateTime(now()), 'filters' => implode(' · ', $filters) ?: $this->label('none', $language)];
    }

    /** @param array<string, mixed> $values
     * @return list<array{label: string, value: string}>
     */
    private function fields(array $values, string $language): array
    {
        $fields = [];
        foreach ($values as $key => $value) {
            $fields[] = ['label' => $this->label($key, $language), 'value' => $this->fieldValue($key, $value, $language)];
        }

        return $fields;
    }

    private function fieldValue(string $field, mixed $value, string $language): string
    {
        if ($value === null || $value === '') {
            return '—';
        }
        if (is_bool($value)) {
            return $this->label($value ? 'yes' : 'no', $language);
        }
        if ($field === 'recipients' && is_array($value)) {
            return implode("\n", array_map(fn (array $recipient): string => $this->label('recipient', $language).': '.$this->fieldValue('recipient', $recipient, $language), array_values($value)));
        }
        if (is_array($value)) {
            if (array_key_exists('name_'.$language, $value)) {
                return $value['name_'.$language] ?? '—';
            }
            $lines = [];
            foreach ($value as $key => $item) {
                $lines[] = $this->label((string) $key, $language).': '.$this->fieldValue((string) $key, $item, $language);
            }

            return implode("\n", $lines) ?: '—';
        }
        if (str_ends_with($field, '_at')) {
            return $this->dateTime($value);
        }
        if (str_ends_with($field, '_date')) {
            return CarbonImmutable::parse($value)->format('d/m/Y');
        }
        if (in_array($field, ['status', 'priority', 'classification', 'channel', 'dispatch_channel'], true)) {
            return $this->label($value, $language);
        }

        return (string) $value;
    }

    private function dateTime(DateTimeInterface|string $value): string
    {
        return CarbonImmutable::parse($value, 'UTC')->setTimezone('Asia/Vientiane')->format('d/m/Y, H:i');
    }

    /** @param array<string, mixed> $data */
    private function xlsx(array $data): string
    {
        $book = new Spreadsheet;
        try {
            $book->getProperties()->setCreator('XPPL DCMS')->setTitle($data['title']);
            $book->getDefaultStyle()->getFont()->setName('Noto Sans Lao')->setSize(11);
            $sheet = $book->getActiveSheet();
            $sheet->setTitle($data['sheet'] ?? 'Correspondence');
            $lastColumn = Coordinate::stringFromColumnIndex(count($data['columns']));
            $metadata = [$data['title'], $data['workspace'], $this->label('generated', $data['language']).': '.$data['generated'], $this->label('filters', $data['language']).': '.$data['filters'], $this->label('total', $data['language']).': '.count($data['rows'])];
            foreach ($metadata as $index => $value) {
                $row = $index + 1;
                $sheet->mergeCells("A{$row}:{$lastColumn}{$row}")->setCellValueExplicit('A'.$row, $value, DataType::TYPE_STRING);
                $sheet->getRowDimension($row)->setRowHeight($index === 3 ? 44 : 34);
            }
            $sheet->getStyle('A1:'.$lastColumn.'1')->applyFromArray(['font' => ['bold' => true, 'size' => 18, 'color' => ['rgb' => 'FFFFFF']], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '13263D']]]);
            foreach ($data['columns'] as $index => $key) {
                $column = Coordinate::stringFromColumnIndex($index + 1);
                $sheet->setCellValueExplicit($column.'7', $this->label($key, $data['language']), DataType::TYPE_STRING);
                $sheet->getColumnDimension($column)->setWidth(in_array($key, ['subject', 'recipients', 'sender_organization'], true) ? 44 : (str_ends_with($key, '_date') ? 18 : 27));
            }
            $sheet->getRowDimension(7)->setRowHeight(44);
            $sheet->getStyle('A7:'.$lastColumn.'7')->applyFromArray(['font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '087E83']]]);
            foreach ($data['rows'] as $index => $values) {
                $row = $index + 8;
                $lines = 1;
                foreach ($data['columns'] as $columnIndex => $key) {
                    $column = Coordinate::stringFromColumnIndex($columnIndex + 1);
                    $value = $values[$key] ?? null;
                    if ($value instanceof DateTimeInterface && (int) $value->format('Y') >= 1900) {
                        $sheet->setCellValueExplicit($column.$row, Date::PHPToExcel($value), DataType::TYPE_NUMERIC);
                        $sheet->getStyle($column.$row)->getNumberFormat()->setFormatCode('dd/mm/yyyy');
                    } else {
                        $text = $this->display($value);
                        $sheet->setCellValueExplicit($column.$row, $text, DataType::TYPE_STRING);
                        $lines = max($lines, array_sum(array_map(fn (string $line): int => max(1, (int) ceil(mb_strlen($line) / ($sheet->getColumnDimension($column)->getWidth() - 3))), explode("\n", $text))));
                    }
                }
                $sheet->getRowDimension($row)->setRowHeight(max(34, $lines * 19 + 10));
                if ($index % 2 === 1) {
                    $sheet->getStyle('A'.$row.':'.$lastColumn.$row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F1F5F9');
                }
            }
            $lastRow = max(8, count($data['rows']) + 7);
            if (! count($data['rows'])) {
                $sheet->mergeCells('A8:'.$lastColumn.'8')->setCellValueExplicit('A8', $this->label('empty', $data['language']), DataType::TYPE_STRING);
            }
            $sheet->getStyle('A1:'.$lastColumn.$lastRow)->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
            $sheet->getStyle('A7:'.$lastColumn.$lastRow)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_HAIR)->getColor()->setRGB('CBD5E1');
            $sheet->freezePane('C8');
            $sheet->setAutoFilter('A7:'.$lastColumn.max(7, count($data['rows']) + 7));
            $sheet->getPageSetup()->setPaperSize(PageSetup::PAPERSIZE_A4)->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setFitToWidth(1)->setFitToHeight(0)->setRowsToRepeatAtTopByStartAndEnd(1, 7)->setPrintArea('A1:'.$lastColumn.$lastRow);
            $sheet->getHeaderFooter()->setOddFooter('&LXPPL DCMS&R'.$this->label('page', $data['language']).' &P '.$this->label('of', $data['language']).' &N');
            $stream = fopen('php://memory', 'w+b');
            try {
                (new Xlsx($book))->save($stream);
                rewind($stream);

                return stream_get_contents($stream);
            } finally {
                fclose($stream);
            }
        } finally {
            $book->disconnectWorksheets();
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{content: string, pages: int}
     */
    private function pdf(array $data): array
    {
        $directory = storage_path('framework/cache/correspondence-pdf');
        File::ensureDirectoryExists($directory, 0700);
        $pdf = new Mpdf(['tempDir' => $directory, 'format' => $data['detail'] ? 'A4' : 'A4-L', 'default_font' => 'dhyana', 'default_font_size' => 9,
            'margin_left' => 12, 'margin_right' => 12, 'margin_top' => 18, 'margin_bottom' => 18, 'margin_header' => 7, 'margin_footer' => 8]);
        $pdf->SetTitle($data['title']);
        $pdf->SetAuthor('XPPL DCMS');
        $pdf->shrink_tables_to_fit = 1;
        $pdf->SetHTMLHeader('<div style="font-size:8pt;color:#64748b">XPPL DCMS</div>');
        $pdf->SetHTMLFooter('<div style="font-size:8pt;color:#64748b;text-align:right">'.$this->label('page', $data['language']).' {PAGENO} '.$this->label('of', $data['language']).' {nbpg}</div>');
        $parts = preg_split('/(?=<tr[\s>])/', view('correspondence-report', $data + ['report' => $this])->render());
        foreach ($parts as $index => $html) {
            $pdf->WriteHTML($html, HTMLParserMode::DEFAULT_MODE, $index === 0, $index === array_key_last($parts));
        }

        $content = $pdf->OutputBinaryData();

        return ['content' => $content, 'pages' => count($pdf->pages)];
    }

    private function download(string $content, string $filename, string $format, ?int $pages = null): Response
    {
        $headers = ['Content-Type' => $format === 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'];
        if ($pages !== null) {
            $headers['X-PDF-Pages'] = (string) $pages;
        }

        return response($content, 200, $headers);
    }
}
