<?php

namespace App\Http\Controllers;

use App\Authorization\AccessControl;
use App\Authorization\DocumentScope;
use App\Authorization\ManageIncomingCorrespondence;
use App\Authorization\ManageIncomingOperations;
use App\Authorization\MembershipAccess;
use App\Http\Requests\IncomingCorrespondenceRequest;
use App\Http\Resources\IncomingCorrespondenceResource;
use App\Models\Department;
use App\Models\DocumentType;
use App\Models\IncomingCorrespondence;
use App\Models\IncomingCorrespondenceAudit;
use App\Models\RegisterConfiguration;
use App\Models\Site;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class IncomingCorrespondenceController extends Controller
{
    public function contexts(Request $request, AccessControl $roles): JsonResponse
    {
        $contexts = [];
        foreach (Site::query()->where('active', true)->with('company')->get() as $site) {
            $departments = Department::query()->where('site_id', $site->id)->where('active', true)->get();
            foreach ([null, ...$departments->all()] as $department) {
                $scope = new DocumentScope($site->company_id, $site->id, $department?->id);
                $permissions = $roles->permissions($request->user(), $scope->authorizationScope(), $department?->id);
                if (! in_array('documents.metadata.view', $roles->grantedPermissions($request->user(), $scope->authorizationScope()), true)
                    || (! $roles->isSystemAdministrator($request->user()) && ! app(MembershipAccess::class)->matches($request->user(), $scope->authorizationScope(), $department?->id))) {
                    continue;
                }
                if (! $roles->allows($request->user(), 'numbering.issue', $scope->authorizationScope())) {
                    $permissions = array_values(array_diff($permissions, ['numbering.issue']));
                }
                $contexts[] = ['key' => $site->id.':'.($department?->id ?? 'site'), 'company_id' => $site->company_id, 'site_id' => $site->id,
                    'department_id' => $department?->id, 'company' => $site->company->only(['id', 'name_en', 'name_lo']),
                    'site' => $site->only(['id', 'name_en', 'name_lo']), 'department' => $department?->only(['id', 'name_en', 'name_lo']),
                    'permissions' => $permissions];
            }
        }

        return response()->json(['data' => $contexts]);
    }

    public function index(IncomingCorrespondenceRequest $request, ManageIncomingCorrespondence $incoming): JsonResponse
    {
        $page = $request->integer('page', 1);
        $perPage = $request->integer('per_page', 10);
        $rows = [];
        $total = 0;
        // Evaluate the canonical document policy before counting or serializing metadata.
        foreach ($this->filtered($request, $incoming)->lazy() as $record) {
            if ($incoming->canRead($request->user(), $record)) {
                if ($total >= ($page - 1) * $perPage && count($rows) < $perPage) {
                    $rows[] = $record;
                }
                $total++;
            }
        }

        return response()->json(['data' => IncomingCorrespondenceResource::collection($rows), 'meta' => ['total' => $total, 'current_page' => $page, 'per_page' => $perPage]]);
    }

    public function lookups(IncomingCorrespondenceRequest $request): JsonResponse
    {
        $scope = $request->documentScope()->authorizationScope();
        $register = RegisterConfiguration::query()->where('scope_id', $scope->id)->where('register_key', 'incoming')->first();
        $types = DocumentType::query()->where('scope_id', $scope->id)->with('category')->get()
            ->filter(fn (DocumentType $type): bool => $register && $type->effectiveFor($register))
            ->map(fn (DocumentType $type): array => $type->only(['id', 'name_en', 'name_lo', 'category_id']) + ['category' => $type->category->only(['id', 'name_en', 'name_lo'])])->values();

        return response()->json(['data' => $types]);
    }

    public function show(IncomingCorrespondenceRequest $request, string $record, ManageIncomingCorrespondence $incoming, ManageIncomingOperations $operations): JsonResponse
    {
        $model = $incoming->find($request->user(), $request->documentScope(), $record);

        return response()->json(['data' => new IncomingCorrespondenceResource($model), 'audits' => IncomingCorrespondenceAudit::query()->where('correspondence_id', $model->id)->orderBy('id')->get(['id', 'actor_id', 'action', 'changed_fields', 'created_at'])] + $operations->details($request->user(), $model));
    }

    public function export(IncomingCorrespondenceRequest $request, ManageIncomingCorrespondence $incoming): StreamedResponse
    {
        return response()->streamDownload(function () use ($request, $incoming): void {
            $stream = fopen('php://output', 'w');
            fwrite($stream, "\xEF\xBB\xBF");
            $lao = $request->input('language', $request->user()->preferred_language) === 'lo';
            fputcsv($stream, $lao ? ['ເລກທະບຽນ', 'ຫົວຂໍ້', 'ຜູ້ສົ່ງ', 'ວັນທີຮັບ', 'ຊ່ອງທາງ', 'ສະຖານະ', 'ວັນຄົບກຳນົດ', 'ຕ້ອງຕອບ', 'ຕອບແລ້ວ'] : ['Internal number', 'Subject', 'Sender', 'Received date', 'Channel', 'Status', 'Due date', 'Response required', 'Response completed'], escape: '');
            $statuses = ['draft' => 'ຮ່າງ', 'received' => 'ໄດ້ຮັບແລ້ວ', 'assigned' => 'ມອບໝາຍແລ້ວ', 'in_progress' => 'ກຳລັງດຳເນີນ', 'responded' => 'ຕອບແລ້ວ', 'closed' => 'ປິດແລ້ວ'];
            $channels = ['email' => 'ອີເມວ', 'courier' => 'ຂົນສົ່ງດ່ວນ', 'hand_delivery' => 'ສົ່ງດ້ວຍຕົນເອງ', 'post' => 'ໄປສະນີ', 'other' => 'ອື່ນໆ'];
            foreach ($this->filtered($request, $incoming)->lazy() as $record) {
                if (! $incoming->canRead($request->user(), $record)) {
                    continue;
                }
                $state = $record->operation;
                $status = $record->status === 'draft' ? 'draft' : ($state?->status ?? 'received');
                $yes = $lao ? 'ແມ່ນ' : 'Yes';
                $no = $lao ? 'ບໍ່' : 'No';
                $row = [$record->internal_number, $record->subject, $record->sender_organization, $record->received_date->format('Y-m-d'),
                    $lao ? ($channels[$record->channel] ?? '') : str_replace('_', ' ', $record->channel ?? ''),
                    $lao ? $statuses[$status] : ucfirst(str_replace('_', ' ', $status)), $state?->due_date?->format('Y-m-d'), $state?->response_required ? $yes : $no, $state?->response_completed_at ? $yes : $no];
                fputcsv($stream, array_map(fn (mixed $value): string => preg_match('/^[\s]*[=+@\-]/u', (string) $value) ? "'".$value : (string) $value, $row), escape: '');
            }
            fclose($stream);
        }, 'incoming-correspondence.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    /** @return Builder<IncomingCorrespondence> */
    private function filtered(IncomingCorrespondenceRequest $request, ManageIncomingCorrespondence $incoming): Builder
    {
        $query = $incoming->query($request->documentScope())->with(['profile', 'type.category', 'operation'])->orderByDesc('id');
        if ($request->filled('search')) {
            $search = '%'.$request->input('search').'%';
            $query->where(fn (Builder $query): Builder => $query->where('subject', 'like', $search)->orWhere('sender_organization', 'like', $search)->orWhere('internal_number', 'like', $search)->orWhere('external_reference', 'like', $search));
        }
        if ($request->filled('filter_channel')) {
            $query->where('channel', $request->input('filter_channel'));
        }
        if ($request->filled('filter_status')) {
            $status = $request->input('filter_status');
            if ($status === 'draft') {
                $query->where('status', 'draft');
            } else {
                $query->where('status', 'registered')->where(fn (Builder $query): Builder => $query->whereHas('operation', fn (Builder $query): Builder => $query->where('status', $status))
                    ->when($status === 'received', fn (Builder $query): Builder => $query->orWhereDoesntHave('operation')));
            }
        }
        if ($request->filled('filter_due')) {
            $query->whereHas('operation', fn (Builder $query): Builder => $query->where('status', '<>', 'closed')->whereDate('due_date', $request->input('filter_due') === 'today' ? '=' : '<', today()));
        }
        if ($request->filled('filter_response')) {
            $query->whereHas('operation', fn (Builder $query): Builder => $query->where('response_required', true)->when($request->input('filter_response') === 'pending', fn (Builder $query): Builder => $query->whereNull('response_completed_at'), fn (Builder $query): Builder => $query->whereNotNull('response_completed_at')));
        }

        return $query;
    }

    public function store(IncomingCorrespondenceRequest $request, ManageIncomingCorrespondence $incoming): IncomingCorrespondenceResource
    {
        return new IncomingCorrespondenceResource($incoming->save($request->user(), $request->documentScope(), $request->except(['company_id', 'site_id', 'department_id'])));
    }

    public function update(IncomingCorrespondenceRequest $request, string $record, ManageIncomingCorrespondence $incoming): IncomingCorrespondenceResource
    {
        return new IncomingCorrespondenceResource($incoming->save($request->user(), $request->documentScope(), $request->except(['company_id', 'site_id', 'department_id']), $record));
    }

    public function register(IncomingCorrespondenceRequest $request, string $record, ManageIncomingCorrespondence $incoming): IncomingCorrespondenceResource
    {
        return new IncomingCorrespondenceResource($incoming->register($request->user(), $request->documentScope(), $record));
    }
}
