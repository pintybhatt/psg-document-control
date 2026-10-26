<?php

namespace App\Http\Controllers;

use App\Authorization\DocumentStorageTarget;
use App\Authorization\ManageAttachments;
use App\Http\Requests\DocumentStorageRequest;
use App\Http\Resources\AttachmentResource;
use App\Models\DocumentAttachment;
use Illuminate\Http\JsonResponse;

class DocumentStorageController extends Controller
{
    public function index(DocumentStorageRequest $request, DocumentStorageTarget $targets): JsonResponse
    {
        $record = $targets->find($request->user(), $request->documentScope(), $request->route('type'), $request->route('record'));
        $revisionId = $request->filled('revision_id') ? $request->integer('revision_id') : null;
        $revision = $targets->revision($record, $revisionId);
        $rows = DocumentAttachment::query()->where('profile_id', $record->profile_id)->where('revision_id', $revisionId)->with('versions')->orderBy('created_at')->orderBy('id')->get();

        return response()->json(['data' => AttachmentResource::collection($rows), 'writable' => $revision === null || ($revision->released_at === null && in_array($record->status, ['draft', 'current'], true)), 'max_upload_kb' => config('document_storage.max_upload_kb')]);
    }

    public function store(DocumentStorageRequest $request, ManageAttachments $attachments): AttachmentResource
    {
        return new AttachmentResource($attachments->upload($request->user(), $request->documentScope(), $request->route('type'), $request->route('record'),
            $request->filled('revision_id') ? $request->integer('revision_id') : null, $request->file('file'), $request->validated('reason'), $request->validated('attachment_id'),
            $request->filled('expected_version') ? $request->integer('expected_version') : null));
    }
}
