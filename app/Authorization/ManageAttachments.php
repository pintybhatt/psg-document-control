<?php

namespace App\Authorization;

use App\Models\AttachmentVersion;
use App\Models\DocumentAttachment;
use App\Models\StorageEvent;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Throwable;

class ManageAttachments
{
    public function __construct(private DocumentStorageTarget $targets, private AttachmentFiles $files) {}

    public function upload(User $actor, DocumentScope $scope, string $type, string $id, ?int $revisionId, UploadedFile $file, string $reason, ?string $attachmentId = null, ?int $expectedVersion = null): DocumentAttachment
    {
        $this->targets->find($actor, $scope, $type, $id, 'documents.attachments.manage');
        $metadata = $this->files->inspect($file);
        $versionId = (string) Str::uuid();
        $key = $this->files->key($versionId);
        try {
            return DB::transaction(function () use ($actor, $scope, $type, $id, $revisionId, $file, $reason, $attachmentId, $expectedVersion, $metadata, $versionId, $key): DocumentAttachment {
                $record = $this->targets->find($actor, $scope, $type, $id, 'documents.attachments.manage', true);
                $this->targets->revision($record, $revisionId, true);
                $attachment = $attachmentId === null ? new DocumentAttachment(['id' => (string) Str::uuid(), 'profile_id' => $record->profile_id, 'revision_id' => $revisionId])
                    : DocumentAttachment::query()->where('profile_id', $record->profile_id)->where('revision_id', $revisionId)->lockForUpdate()->findOrFail($attachmentId);
                $last = $attachment->exists ? $attachment->versions()->reorder()->orderByDesc('version')->value('version') : 0;
                if ($attachment->exists && $last !== $expectedVersion) {
                    throw ValidationException::withMessages(['storage' => 'storageStale']);
                }
                if (! $attachment->exists && ! $attachment->save()) {
                    throw new LogicException('Attachment persistence cancelled.');
                }
                $source = fopen($file->getRealPath(), 'rb');
                try {
                    if (! Storage::disk('documents')->put($key, $source, ['visibility' => 'private'])) {
                        throw new LogicException('Private file persistence failed.');
                    }
                } finally {
                    fclose($source);
                }
                $version = new AttachmentVersion($metadata + ['id' => $versionId, 'attachment_id' => $attachment->id, 'version' => $last + 1, 'reason' => $reason, 'uploaded_by' => $actor->id]);
                if (! $version->save()) {
                    throw new LogicException('Attachment version persistence cancelled.');
                }
                $audit = new StorageEvent(['profile_id' => $record->profile_id, 'actor_id' => $actor->id, 'action' => $last ? 'attachment.replaced' : 'attachment.uploaded', 'subject_id' => $version->id, 'reason' => $reason]);
                if (! $audit->save()) {
                    throw new LogicException('Storage audit persistence cancelled.');
                }

                return $attachment->load('versions');
            }, 1);
        } catch (Throwable $failure) {
            try {
                Storage::disk('documents')->delete($key);
            } catch (Throwable $cleanup) {
                report($cleanup);
            }
            throw $failure;
        }
    }
}
