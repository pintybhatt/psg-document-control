<?php

namespace App\Http\Controllers;

use App\Authorization\AttachmentFiles;
use App\Authorization\DocumentOperation;
use App\Authorization\DocumentStorageTarget;
use App\Authorization\ManageAttachments;
use App\Http\Requests\DocumentStorageRequest;
use App\Http\Resources\AttachmentResource;
use App\Models\AttachmentVersion;
use App\Models\DocumentAttachment;
use App\Models\StorageEvent;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class DocumentStorageController extends Controller
{
    public function index(DocumentStorageRequest $request, DocumentStorageTarget $targets): JsonResponse
    {
        $record = $targets->find($request->user(), $request->documentScope(), $request->route('type'), $request->route('record'));
        $revisionId = $request->filled('revision_id') ? $request->integer('revision_id') : null;
        $revision = $targets->revision($record, $revisionId);
        $rows = DocumentAttachment::query()->where('profile_id', $record->profile_id)->where('revision_id', $revisionId)->with('versions')->orderBy('created_at')->orderBy('id')->get();

        return response()->json(['data' => AttachmentResource::collection($rows), 'capabilities' => ['preview' => $targets->canContent($request->user(), $record, DocumentOperation::Preview), 'download' => $targets->canContent($request->user(), $record, DocumentOperation::Download)], 'writable' => $revision === null || ($revision->released_at === null && in_array($record->status, ['draft', 'current'], true)), 'max_upload_kb' => config('document_storage.max_upload_kb')]);
    }

    public function store(DocumentStorageRequest $request, ManageAttachments $attachments): AttachmentResource
    {
        return new AttachmentResource($attachments->upload($request->user(), $request->documentScope(), $request->route('type'), $request->route('record'),
            $request->filled('revision_id') ? $request->integer('revision_id') : null, $request->file('file'), $request->validated('reason'), $request->validated('attachment_id'),
            $request->filled('expected_version') ? $request->integer('expected_version') : null));
    }

    public function preview(DocumentStorageRequest $request, DocumentStorageTarget $targets, AttachmentFiles $files): StreamedResponse
    {
        return $this->content($request, $targets, $files, DocumentOperation::Preview);
    }

    public function download(DocumentStorageRequest $request, DocumentStorageTarget $targets, AttachmentFiles $files): StreamedResponse
    {
        return $this->content($request, $targets, $files, DocumentOperation::Download);
    }

    private function content(DocumentStorageRequest $request, DocumentStorageTarget $targets, AttachmentFiles $files, DocumentOperation $operation): StreamedResponse
    {
        $record = $targets->find($request->user(), $request->documentScope(), $request->route('type'), $request->route('record'));
        $targets->content($request->user(), $record, $operation);
        $revisionId = $request->filled('revision_id') ? $request->integer('revision_id') : null;
        $targets->revision($record, $revisionId);
        $version = AttachmentVersion::query()->whereIn('attachment_id', DocumentAttachment::query()->where('profile_id', $record->profile_id)->where('revision_id', $revisionId)->select('id'))->findOrFail($request->route('version'));
        $preview = $operation === DocumentOperation::Preview;
        abort_if($preview && ! in_array($version->mime_type, ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'], true), 415, 'storageDownloadOnly');
        try {
            $stream = $files->verifiedStream($version);
        } catch (Throwable $failure) {
            StorageEvent::query()->create(['profile_id' => $record->profile_id, 'actor_id' => $request->user()->id, 'action' => 'attachment.unavailable', 'subject_id' => $version->id]);
            report($failure);
            abort(409, 'storageFileUnavailable');
        }
        try {
            $event = new StorageEvent(['profile_id' => $record->profile_id, 'actor_id' => $request->user()->id, 'action' => 'attachment.'.$operation->value, 'subject_id' => $version->id]);
            if (! $event->save()) {
                throw new \LogicException('File access audit persistence cancelled.');
            }
        } catch (Throwable $failure) {
            fclose($stream);
            throw $failure;
        }

        return $files->contentResponse($version, $stream, $preview);
    }
}
