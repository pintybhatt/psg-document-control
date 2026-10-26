<?php

namespace App\Http\Controllers;

use App\Authorization\SearchDocuments;
use App\Http\Requests\DocumentSearchRequest;
use App\Models\DocumentSecurityProfile;
use App\Models\DocumentType;
use App\Models\PhysicalCopy;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\JsonResponse;

class DocumentSearchController extends Controller
{
    public function lookups(DocumentSearchRequest $request): JsonResponse
    {
        $scope = $request->documentScope();
        $types = DocumentType::query()->where('scope_id', $scope->authorizationScope()->id)->with(['category', 'registers'])->orderBy('name_en')->orderBy('id')->get();

        return response()->json(['data' => $types->map(fn (DocumentType $type): array => $type->only(['id', 'name_en', 'name_lo', 'category_id']) + [
            'category' => $type->category->only(['id', 'name_en', 'name_lo']),
            'registers' => $type->registers->pluck('register_key'),
        ])])->header('Cache-Control', 'private, no-store');
    }

    public function index(DocumentSearchRequest $request, SearchDocuments $search): JsonResponse
    {
        $scope = $request->documentScope();
        $page = $search->query($request->user(), $scope, $request->validated())->paginate($request->integer('per_page', 10));
        $types = DocumentType::with('category')->whereIn('id', $page->getCollection()->pluck('type_id'))->get()->keyBy('id');
        $profiles = DocumentSecurityProfile::query()->whereIn('id', $page->getCollection()->pluck('profile_id'))
            ->with(['physicalCopies' => fn (HasMany $query): HasMany => $query->whereIn('id', $search->physicalQuery($request->validated())->select('pc.id'))
                ->with(['location', 'custodian'])->orderBy('identifier')->orderBy('id')->limit(3)])->get()->keyBy('id');
        $rows = $page->getCollection()->map(function (object $row) use ($request, $search, $scope, $types, $profiles): array {
            $search->audit($request->user(), $row->profile_id);
            $type = $types[$row->type_id];
            $route = $row->register === 'controlled' ? 'controlled-documents' : $row->register;

            $values = (array) $row;
            unset($values['profile_id']);

            return $values + ['physical_copies' => $profiles[$row->profile_id]->physicalCopies->map(fn (PhysicalCopy $copy): array => [
                'identifier' => $copy->identifier, 'kind' => $copy->kind, 'revision_id' => $copy->revision_id, 'status' => $copy->status, 'location' => $copy->location->label(), 'custodian' => $copy->custodian?->name,
            ])] + ['key' => $row->register.':'.$row->id, 'type' => $type->only(['id', 'name_en', 'name_lo']), 'category' => $type->category->only(['id', 'name_en', 'name_lo']),
                'detail_url' => route('dcms.'.$route, ['unit' => $scope->siteId.':'.($scope->departmentId ?? 'site'), 'record' => $row->id], false)];
        });

        return response()->json(['data' => $rows, 'meta' => ['total' => $page->total(), 'current_page' => $page->currentPage(), 'per_page' => $page->perPage()]])->header('Cache-Control', 'private, no-store');
    }
}
