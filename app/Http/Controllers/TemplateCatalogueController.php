<?php

namespace App\Http\Controllers;

use App\Authorization\TemplateCatalogue;
use App\Http\Requests\TemplateCatalogueRequest;
use App\Http\Resources\DocumentTemplateResource;
use App\Models\DocumentTemplateAudit;
use Illuminate\Http\JsonResponse;

class TemplateCatalogueController extends Controller
{
    public function index(TemplateCatalogueRequest $request, TemplateCatalogue $catalogue): JsonResponse
    {
        $scope = $request->scopeContext();
        $catalogue->authorize($request->user(), $scope);
        $page = $request->integer('page', 1);
        $perPage = $request->integer('per_page', 10);
        $rows = [];
        $total = 0;
        foreach ($catalogue->query($scope)->orderByDesc('id')->lazy() as $template) {
            if ($catalogue->visible($request->user(), $scope, $template)) {
                if ($total >= ($page - 1) * $perPage && count($rows) < $perPage) {
                    $rows[] = $template;
                }
                $total++;
            }
        }

        return response()->json(['data' => DocumentTemplateResource::collection($rows), 'meta' => ['total' => $total], 'publication_enabled' => false])->header('Cache-Control', 'private, no-store');
    }

    public function sources(TemplateCatalogueRequest $request, TemplateCatalogue $catalogue): JsonResponse
    {
        return response()->json(['data' => $catalogue->sources($request->user(), $request->scopeContext(), $request->string('q')->toString())])->header('Cache-Control', 'private, no-store');
    }

    public function show(TemplateCatalogueRequest $request, string $template, TemplateCatalogue $catalogue): JsonResponse
    {
        $scope = $request->scopeContext();
        $catalogue->authorize($request->user(), $scope);
        $record = $catalogue->find($request->user(), $scope, $template);

        return response()->json(['data' => new DocumentTemplateResource($record), 'history' => DocumentTemplateAudit::query()->where('template_id', $record->id)->orderBy('id')
            ->get(['id', 'actor_id', 'action', 'snapshot', 'reason', 'created_at']), 'publication_enabled' => false])->header('Cache-Control', 'private, no-store');
    }

    public function store(TemplateCatalogueRequest $request, TemplateCatalogue $catalogue): DocumentTemplateResource
    {
        return new DocumentTemplateResource($catalogue->create($request->user(), $request->scopeContext(), $request->catalogueData()));
    }

    public function retire(TemplateCatalogueRequest $request, string $template, TemplateCatalogue $catalogue): DocumentTemplateResource
    {
        return new DocumentTemplateResource($catalogue->retire($request->user(), $request->scopeContext(), $template, $request->catalogueData()));
    }
}
