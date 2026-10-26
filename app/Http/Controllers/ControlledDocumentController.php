<?php

namespace App\Http\Controllers;

use App\Authorization\CorrespondenceContexts;
use App\Authorization\ManageControlledDocuments;
use App\Http\Requests\ControlledDocumentRequest;
use App\Http\Resources\ControlledDocumentResource;
use App\Models\DocumentType;
use App\Models\RegisterConfiguration;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ControlledDocumentController extends Controller
{
    public function contexts(Request $request, CorrespondenceContexts $contexts): JsonResponse
    {
        return response()->json(['data' => $contexts->forUser($request->user())]);
    }

    public function index(ControlledDocumentRequest $request, ManageControlledDocuments $manager): JsonResponse
    {
        $page = $request->integer('page', 1);
        $perPage = $request->integer('per_page', 10);
        $query = $manager->query($request->documentScope())->with(['profile', 'type.category', 'revisions.owner', 'revisions.custodian', 'currentRevision'])
            ->when($request->filled('status'), fn (Builder $query): Builder => $query->where('status', $request->input('status')))
            ->when($request->filled('type_id'), fn (Builder $query): Builder => $query->where('type_id', $request->integer('type_id')))
            ->when($request->filled('search'), function (Builder $query) use ($request): void {
                $term = '%'.$request->input('search').'%';
                $query->where(fn (Builder $query): Builder => $query->where('document_code', 'like', $term)->orWhere('internal_number', 'like', $term)
                    ->orWhereHas('revisions', fn (Builder $query): Builder => $query->where('title_en', 'like', $term)->orWhere('title_lo', 'like', $term)));
            })->orderByDesc('id');
        $rows = [];
        $total = 0;
        foreach ($query->lazy() as $record) {
            if ($manager->canRead($request->user(), $record)) {
                if ($total >= ($page - 1) * $perPage && count($rows) < $perPage) {
                    $rows[] = $record;
                }
                $total++;
            }
        }

        return response()->json(['data' => ControlledDocumentResource::collection($rows), 'meta' => ['total' => $total, 'current_page' => $page, 'per_page' => $perPage]]);
    }

    public function lookups(ControlledDocumentRequest $request, ManageControlledDocuments $manager): JsonResponse
    {
        $scope = $request->documentScope();
        $register = RegisterConfiguration::query()->where('scope_id', $scope->authorizationScope()->id)->where('register_key', 'controlled')->first();
        $types = DocumentType::query()->where('scope_id', $scope->authorizationScope()->id)->with('category')->get()
            ->filter(fn (DocumentType $type): bool => $register && $type->effectiveFor($register))
            ->map(fn (DocumentType $type): array => $type->only(['id', 'name_en', 'name_lo']) + ['category' => $type->category->only(['id', 'name_en', 'name_lo'])])->values();

        return response()->json(['data' => $types, 'people' => $manager->people($scope)->orderBy('name')->orderBy('id')->get(['id', 'name'])]);
    }

    public function show(ControlledDocumentRequest $request, string $record, ManageControlledDocuments $manager): JsonResponse
    {
        $model = $manager->find($request->user(), $request->documentScope(), $record);
        $related = [];
        $candidates = $manager->query($request->documentScope())->where(fn (Builder $query): Builder => $query->whereKey($model->replaced_by_id)->orWhere('replaced_by_id', $model->id))->with('profile')->get();
        foreach ($candidates as $candidate) {
            if ($manager->canRead($request->user(), $candidate)) {
                $related[] = $candidate->only(['id', 'document_code', 'status']) + ['relationship' => $candidate->id === $model->replaced_by_id ? 'replacement' : 'replaces'];
            }
        }

        return response()->json(['data' => new ControlledDocumentResource($model), 'audits' => $model->audits()->get(['id', 'revision_id', 'actor_id', 'action', 'reason', 'changed_fields', 'created_at']), 'related' => $related]);
    }

    public function store(ControlledDocumentRequest $request, ManageControlledDocuments $manager): ControlledDocumentResource
    {
        return new ControlledDocumentResource($manager->save($request->user(), $request->documentScope(), $request->documentData()));
    }

    public function update(ControlledDocumentRequest $request, string $record, ManageControlledDocuments $manager): ControlledDocumentResource
    {
        return new ControlledDocumentResource($manager->save($request->user(), $request->documentScope(), $request->documentData(), $record));
    }

    public function register(ControlledDocumentRequest $request, string $record, ManageControlledDocuments $manager): ControlledDocumentResource
    {
        return new ControlledDocumentResource($manager->register($request->user(), $request->documentScope(), $record, $request->documentData()));
    }

    public function revise(ControlledDocumentRequest $request, string $record, ManageControlledDocuments $manager): ControlledDocumentResource
    {
        return new ControlledDocumentResource($manager->revise($request->user(), $request->documentScope(), $record, $request->documentData()));
    }

    public function release(ControlledDocumentRequest $request, string $record, ManageControlledDocuments $manager): ControlledDocumentResource
    {
        return new ControlledDocumentResource($manager->release($request->user(), $request->documentScope(), $record, $request->documentData()));
    }

    public function changeStatus(ControlledDocumentRequest $request, string $record, ManageControlledDocuments $manager): ControlledDocumentResource
    {
        return new ControlledDocumentResource($manager->changeStatus($request->user(), $request->documentScope(), $record, $request->documentData()));
    }
}
