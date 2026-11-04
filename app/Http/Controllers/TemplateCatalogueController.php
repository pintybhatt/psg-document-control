<?php

namespace App\Http\Controllers;

use App\Authorization\TemplateCatalogue;
use App\Http\Requests\TemplateCatalogueRequest;
use App\Http\Resources\DocumentTemplateResource;
use App\Models\DocumentTemplateAudit;
use App\Models\StorageEvent;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

        return response()->json(['data' => DocumentTemplateResource::collection($rows), 'meta' => ['total' => $total], 'publication_enabled' => true])->header('Cache-Control', 'private, no-store');
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

        $history = DocumentTemplateAudit::query()->where('template_id', $record->id)->orderBy('id')->get(['id', 'actor_id', 'action', 'snapshot', 'reason', 'created_at'])
            ->map(fn (DocumentTemplateAudit $entry): array => $entry->toArray() + ['key' => ($entry->action === 'created' ? '0:' : '3:').'catalogue:'.$entry->id]);
        if ($publication = $record->publication) {
            $history->push(['id' => $publication->id, 'key' => '1:publication:'.$publication->id, 'actor_id' => $publication->published_by, 'action' => 'published',
                'snapshot' => $publication->snapshot, 'reason' => $publication->reason, 'created_at' => $publication->published_at->toISOString()]);
        }
        foreach (StorageEvent::query()->where('after->template_id', $record->id)->where('action', 'attachment.download')->orderBy('id')->get() as $entry) {
            $history->push(['id' => $entry->id, 'key' => '2:download:'.$entry->id, 'actor_id' => $entry->actor_id, 'action' => 'downloaded', 'snapshot' => $entry->after, 'reason' => null, 'created_at' => $entry->created_at->toISOString()]);
        }

        return response()->json(['data' => new DocumentTemplateResource($record), 'history' => $history->sortBy(['created_at', 'key'])->values(), 'publication_enabled' => true])->header('Cache-Control', 'private, no-store');
    }

    public function store(TemplateCatalogueRequest $request, TemplateCatalogue $catalogue): DocumentTemplateResource
    {
        return new DocumentTemplateResource($catalogue->create($request->user(), $request->scopeContext(), $request->catalogueData()));
    }

    public function retire(TemplateCatalogueRequest $request, string $template, TemplateCatalogue $catalogue): DocumentTemplateResource
    {
        return new DocumentTemplateResource($catalogue->retire($request->user(), $request->scopeContext(), $template, $request->catalogueData()));
    }

    public function publish(TemplateCatalogueRequest $request, string $template, TemplateCatalogue $catalogue): DocumentTemplateResource
    {
        return new DocumentTemplateResource($catalogue->publish($request->user(), $request->scopeContext(), $template, $request->catalogueData()));
    }

    public function download(TemplateCatalogueRequest $request, string $template, TemplateCatalogue $catalogue): StreamedResponse
    {
        return $catalogue->download($request->user(), $request->scopeContext(), $template);
    }
}
