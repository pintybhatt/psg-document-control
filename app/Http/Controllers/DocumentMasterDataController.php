<?php

namespace App\Http\Controllers;

use App\Authorization\Confidentiality;
use App\Authorization\ManageDocumentMasterData;
use App\Http\Requests\DocumentMasterDataRequest;
use App\Http\Resources\DocumentMasterDataResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class DocumentMasterDataController extends Controller
{
    public function index(DocumentMasterDataRequest $request, ManageDocumentMasterData $master): AnonymousResourceCollection
    {
        $kind = $request->route('kind');
        $query = $master->query($kind, $request->scopeContext())->with('scope');
        if ($kind === 'types') {
            $query->with(['category', 'registers']);
        }

        return DocumentMasterDataResource::collection($query->orderBy('id')->paginate($request->integer('per_page', 10)));
    }

    public function store(DocumentMasterDataRequest $request, ManageDocumentMasterData $master): DocumentMasterDataResource
    {
        return new DocumentMasterDataResource($master->save($request->user(), $request->scopeContext(), $request->route('kind'), $request->validated()));
    }

    public function update(DocumentMasterDataRequest $request, string $kind, string $record, ManageDocumentMasterData $master): DocumentMasterDataResource
    {
        return new DocumentMasterDataResource($master->save($request->user(), $request->scopeContext(), $kind, $request->validated(), $record));
    }

    public function classifications(DocumentMasterDataRequest $request): JsonResponse
    {
        return response()->json(['data' => array_map(fn (Confidentiality $level): array => [
            'value' => $level->value, 'label_key' => $level->labelKey(), 'description_key' => $level->descriptionKey(),
        ], Confidentiality::cases())]);
    }
}
