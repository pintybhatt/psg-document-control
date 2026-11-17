<?php

namespace App\Http\Controllers;

use App\Authorization\CorrespondenceContexts;
use App\Authorization\DocumentApprovals;
use App\Authorization\ManageOutgoingCorrespondence;
use App\Authorization\ManageOutgoingOperations;
use App\Authorization\XpplNumbering;
use App\CorrespondenceReport;
use App\Http\Requests\OutgoingCorrespondenceRequest;
use App\Http\Resources\OutgoingCorrespondenceResource;
use App\Models\DocumentType;
use App\Models\OutgoingCorrespondence;
use App\Models\RegisterConfiguration;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class OutgoingCorrespondenceController extends Controller
{
    public function contexts(Request $request, CorrespondenceContexts $contexts): JsonResponse
    {
        return response()->json(['data' => $contexts->forUser($request->user())]);
    }

    public function index(OutgoingCorrespondenceRequest $request, ManageOutgoingCorrespondence $outgoing): JsonResponse
    {
        $page = $request->integer('page', 1);
        $perPage = $request->integer('per_page', 10);
        $rows = [];
        $total = 0;
        foreach ($this->filtered($request, $outgoing)->lazy() as $record) {
            if ($outgoing->canRead($request->user(), $record)) {
                if ($total >= ($page - 1) * $perPage && count($rows) < $perPage) {
                    $rows[] = $record;
                }
                $total++;
            }
        }

        return response()->json(['data' => OutgoingCorrespondenceResource::collection($rows), 'meta' => ['total' => $total, 'current_page' => $page, 'per_page' => $perPage]]);
    }

    /** @return Builder<OutgoingCorrespondence> */
    private function filtered(OutgoingCorrespondenceRequest $request, ManageOutgoingCorrespondence $outgoing): Builder
    {
        return $outgoing->query($request->documentScope())->with(['profile', 'type.category', 'recipients.delivery', 'operation'])->when($request->filled('search'), function (Builder $query) use ($request): void {
            $term = '%'.$request->input('search').'%';
            $query->where(fn (Builder $query): Builder => $query->where('subject', 'like', $term)->orWhere('internal_number', 'like', $term)->orWhere('official_number', 'like', $term)->orWhereHas('recipients', fn (Builder $query): Builder => $query->where('organization', 'like', $term)));
        })->orderByDesc('id');
    }

    public function lookups(OutgoingCorrespondenceRequest $request): JsonResponse
    {
        $scope = $request->documentScope()->authorizationScope();
        $register = RegisterConfiguration::query()->where('scope_id', $scope->id)->where('register_key', 'outgoing')->first();
        $automatic = app(XpplNumbering::class)->policyActive($scope->company_id, 'outgoing');
        $types = DocumentType::query()->where('scope_id', $scope->id)->with('category')->get()
            ->filter(fn (DocumentType $type): bool => $register && $type->effectiveFor($register))
            ->map(fn (DocumentType $type): array => $type->only(['id', 'code', 'name_en', 'name_lo', 'category_id']) + ['category' => $type->category->only(['id', 'name_en', 'name_lo'])])->values();

        return response()->json(['data' => $types, 'automatic_numbering' => $automatic]);
    }

    public function show(OutgoingCorrespondenceRequest $request, string $record, ManageOutgoingCorrespondence $outgoing, ManageOutgoingOperations $operations): JsonResponse
    {
        $model = $outgoing->find($request->user(), $request->documentScope(), $record);

        return response()->json(['data' => new OutgoingCorrespondenceResource($model), 'approval_execution' => app(DocumentApprovals::class)->panel($request->user(), $model), 'audits' => $model->audits()->get(['id', 'actor_id', 'action', 'changed_fields', 'created_at'])] + $operations->details($request->user(), $model));
    }

    public function export(OutgoingCorrespondenceRequest $request, ManageOutgoingCorrespondence $manager, CorrespondenceReport $report): Response
    {
        $records = $this->filtered($request, $manager)->lazy()->filter(fn (OutgoingCorrespondence $record): bool => $manager->canRead($request->user(), $record));

        return $report->register($request, 'outgoing', $records);
    }

    public function detailPdf(OutgoingCorrespondenceRequest $request, string $record, ManageOutgoingCorrespondence $manager, ManageOutgoingOperations $operations, CorrespondenceReport $report): Response
    {
        $model = $manager->find($request->user(), $request->documentScope(), $record);
        $model->loadMissing(['type.category', 'operation', 'recipients.delivery']);
        $details = $operations->details($request->user(), $model);
        $audits = $model->audits()->orderBy('id')->get();

        return $report->detail($request, 'outgoing', (new OutgoingCorrespondenceResource($model))->resolve($request), $details, $audits);
    }

    public function store(OutgoingCorrespondenceRequest $request, ManageOutgoingCorrespondence $outgoing): OutgoingCorrespondenceResource
    {
        return new OutgoingCorrespondenceResource($outgoing->save($request->user(), $request->documentScope(), $request->except(['company_id', 'site_id', 'department_id'])));
    }

    public function update(OutgoingCorrespondenceRequest $request, string $record, ManageOutgoingCorrespondence $outgoing): OutgoingCorrespondenceResource
    {
        return new OutgoingCorrespondenceResource($outgoing->save($request->user(), $request->documentScope(), $request->except(['company_id', 'site_id', 'department_id']), $record));
    }

    public function register(OutgoingCorrespondenceRequest $request, string $record, ManageOutgoingCorrespondence $outgoing): OutgoingCorrespondenceResource
    {
        return new OutgoingCorrespondenceResource($outgoing->register($request->user(), $request->documentScope(), $record, $request->filled('namespace_id') ? $request->integer('namespace_id') : null));
    }
}
