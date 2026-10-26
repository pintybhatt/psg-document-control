<?php

namespace App\Http\Controllers;

use App\Authorization\ManageIncomingOperations;
use App\Http\Requests\IncomingCorrespondenceRequest;
use App\Http\Resources\IncomingCorrespondenceResource;
use Illuminate\Http\JsonResponse;

class IncomingOperationController extends Controller
{
    public function options(IncomingCorrespondenceRequest $request, string $record, ManageIncomingOperations $operations): JsonResponse
    {
        return response()->json($operations->options($request->user(), $request->documentScope(), $record, $request->input('search')));
    }

    public function operate(IncomingCorrespondenceRequest $request, string $record, ManageIncomingOperations $operations): IncomingCorrespondenceResource
    {
        return new IncomingCorrespondenceResource($operations->act($request->user(), $request->documentScope(), $record, $request->except(['company_id', 'site_id', 'department_id'])));
    }
}
