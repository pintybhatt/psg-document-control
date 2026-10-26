<?php

namespace App\Http\Controllers;

use App\Authorization\SearchDocuments;
use App\Http\Requests\DocumentSearchRequest;
use App\Models\DocumentType;
use Illuminate\Http\JsonResponse;

class DocumentSearchController extends Controller
{
    public function index(DocumentSearchRequest $request, SearchDocuments $search): JsonResponse
    {
        $scope = $request->documentScope();
        $page = $search->query($request->user(), $scope, $request->validated())->paginate($request->integer('per_page', 10));
        $types = DocumentType::with('category')->whereIn('id', $page->getCollection()->pluck('type_id'))->get()->keyBy('id');
        $rows = $page->getCollection()->map(function (object $row) use ($request, $search, $scope, $types): array {
            $search->audit($request->user(), $row->profile_id);
            $type = $types[$row->type_id];
            $route = $row->register === 'controlled' ? 'controlled-documents' : $row->register;

            return (array) $row + ['key' => $row->register.':'.$row->id, 'type' => $type->only(['id', 'name_en', 'name_lo']), 'category' => $type->category->only(['id', 'name_en', 'name_lo']),
                'detail_url' => route('dcms.'.$route, ['unit' => $scope->siteId.':'.($scope->departmentId ?? 'site'), 'record' => $row->id], false)];
        });

        return response()->json(['data' => $rows, 'meta' => ['total' => $page->total(), 'current_page' => $page->currentPage(), 'per_page' => $page->perPage()]])->header('Cache-Control', 'private, no-store');
    }
}
