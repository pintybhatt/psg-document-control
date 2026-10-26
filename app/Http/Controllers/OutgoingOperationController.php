<?php

namespace App\Http\Controllers;

use App\Authorization\ManageOutgoingOperations;
use App\Http\Requests\OutgoingCorrespondenceRequest;
use App\Http\Resources\OutgoingCorrespondenceResource;
use Illuminate\Http\JsonResponse;

class OutgoingOperationController extends Controller
{
    public function options(OutgoingCorrespondenceRequest $request, string $record, ManageOutgoingOperations $operations): JsonResponse
    {
        return response()->json(['data' => $operations->options($request->user(), $request->documentScope(), $record, $request->input('search'))]);
    }

    public function operate(OutgoingCorrespondenceRequest $request, string $record, ManageOutgoingOperations $operations): OutgoingCorrespondenceResource
    {
        return new OutgoingCorrespondenceResource($operations->act($request->user(), $request->documentScope(), $record, $request->except(['company_id', 'site_id', 'department_id'])));
    }
}
