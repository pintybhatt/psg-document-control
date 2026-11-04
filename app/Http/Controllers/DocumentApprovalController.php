<?php

namespace App\Http\Controllers;

use App\Authorization\DocumentApprovals;
use App\Authorization\DocumentStorageTarget;
use App\Http\Requests\DocumentApprovalRequest;
use Illuminate\Http\JsonResponse;

class DocumentApprovalController extends Controller
{
    public function show(DocumentApprovalRequest $request, string $type, string $record, DocumentApprovals $approvals, DocumentStorageTarget $targets): JsonResponse
    {
        $model = $targets->find($request->user(), $request->documentScope(), $type, $record);
        $revision = $targets->revision($model, $request->filled('revision_id') ? $request->integer('revision_id') : null);

        return response()->json($approvals->details($request->user(), $model, $revision))->header('Cache-Control', 'private, no-store');
    }

    public function store(DocumentApprovalRequest $request, string $type, string $record, DocumentApprovals $approvals): JsonResponse
    {
        $approvals->act($request->user(), $request->documentScope(), $type, $record, $request->filled('revision_id') ? $request->integer('revision_id') : null, $request->approvalData());

        return response()->json(['saved' => true])->header('Cache-Control', 'private, no-store');
    }
}
