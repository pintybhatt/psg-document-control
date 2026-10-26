<?php

namespace App\Http\Controllers;

use App\Authorization\CorrespondenceContexts;
use App\Authorization\ManageOutgoingCorrespondence;
use App\Http\Requests\OutgoingCorrespondenceRequest;
use App\Http\Resources\OutgoingCorrespondenceResource;
use App\Models\DocumentType;
use App\Models\RegisterConfiguration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
        foreach ($outgoing->query($request->documentScope())->with(['profile', 'type.category', 'recipients'])->orderByDesc('id')->lazy() as $record) {
            if ($outgoing->canRead($request->user(), $record)) {
                if ($total >= ($page - 1) * $perPage && count($rows) < $perPage) {
                    $rows[] = $record;
                }
                $total++;
            }
        }

        return response()->json(['data' => OutgoingCorrespondenceResource::collection($rows), 'meta' => ['total' => $total, 'current_page' => $page, 'per_page' => $perPage]]);
    }

    public function lookups(OutgoingCorrespondenceRequest $request): JsonResponse
    {
        $scope = $request->documentScope()->authorizationScope();
        $register = RegisterConfiguration::query()->where('scope_id', $scope->id)->where('register_key', 'outgoing')->first();
        $types = DocumentType::query()->where('scope_id', $scope->id)->with('category')->get()
            ->filter(fn (DocumentType $type): bool => $register && $type->effectiveFor($register))
            ->map(fn (DocumentType $type): array => $type->only(['id', 'name_en', 'name_lo', 'category_id']) + ['category' => $type->category->only(['id', 'name_en', 'name_lo'])])->values();

        return response()->json(['data' => $types]);
    }

    public function show(OutgoingCorrespondenceRequest $request, string $record, ManageOutgoingCorrespondence $outgoing): JsonResponse
    {
        $model = $outgoing->find($request->user(), $request->documentScope(), $record);

        return response()->json(['data' => new OutgoingCorrespondenceResource($model), 'audits' => $model->audits()->get(['id', 'actor_id', 'action', 'changed_fields', 'created_at'])]);
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
        return new OutgoingCorrespondenceResource($outgoing->register($request->user(), $request->documentScope(), $record));
    }
}
