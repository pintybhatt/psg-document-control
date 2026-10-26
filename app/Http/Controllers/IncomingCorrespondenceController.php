<?php

namespace App\Http\Controllers;

use App\Authorization\CorrespondenceContexts;
use App\Authorization\ManageIncomingCorrespondence;
use App\Authorization\ManageIncomingOperations;
use App\CorrespondenceReport;
use App\Http\Requests\IncomingCorrespondenceRequest;
use App\Http\Resources\IncomingCorrespondenceResource;
use App\Models\DocumentType;
use App\Models\IncomingCorrespondence;
use App\Models\IncomingCorrespondenceAudit;
use App\Models\RegisterConfiguration;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class IncomingCorrespondenceController extends Controller
{
    public function contexts(Request $request, CorrespondenceContexts $contexts): JsonResponse
    {
        return response()->json(['data' => $contexts->forUser($request->user())]);
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

    public function export(IncomingCorrespondenceRequest $request, ManageIncomingCorrespondence $manager, CorrespondenceReport $report): Response
    {
        $records = $this->filtered($request, $manager)->lazy()->filter(fn (IncomingCorrespondence $record): bool => $manager->canRead($request->user(), $record));

        return $report->register($request, 'incoming', $records);
    }

    public function detailPdf(IncomingCorrespondenceRequest $request, string $record, ManageIncomingCorrespondence $manager, ManageIncomingOperations $operations, CorrespondenceReport $report): Response
    {
        $model = $manager->find($request->user(), $request->documentScope(), $record);
        $model->loadMissing(['type.category', 'operation']);
        $details = $operations->details($request->user(), $model);
        $audits = IncomingCorrespondenceAudit::query()->where('correspondence_id', $model->id)->orderBy('id')->get();

        return $report->detail($request, 'incoming', (new IncomingCorrespondenceResource($model))->resolve($request), $details, $audits);
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
