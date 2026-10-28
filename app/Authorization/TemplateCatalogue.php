<?php

namespace App\Authorization;

use App\Models\AttachmentVersion;
use App\Models\AuthorizationScope;
use App\Models\ControlledDocument;
use App\Models\ControlledRevision;
use App\Models\DocumentSecurityProfile;
use App\Models\DocumentTemplate;
use App\Models\DocumentTemplateAudit;
use App\Models\DocumentTemplatePublication;
use App\Models\StorageEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use LogicException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class TemplateCatalogue
{
    public function __construct(private AccessControl $roles, private DocumentAccess $documents) {}

    public function authorize(User $actor, AuthorizationScope $scope, bool $write = false): void
    {
        $scope = AuthorizationScope::context($scope->scope_type, $scope->company_id, $scope->site_id);
        abort_unless($scope->scope_type === 'site', 403, 'rbacForbidden');
        $this->roles->authorize($actor, 'templates.view', $scope);
        abort_unless(app(MembershipAccess::class)->matches($actor, $scope), 403, 'rbacForbidden');
        if ($write) {
            $this->roles->authorize($actor, 'templates.manage', $scope);
        }
    }

    /** @return array<string, mixed> */
    public static function creationRules(): array
    {
        return ['name_en' => ['required', 'string', 'max:255'], 'name_lo' => ['required', 'string', 'max:255'],
            'description_en' => ['nullable', 'string', 'max:2000'], 'description_lo' => ['nullable', 'string', 'max:2000'],
            'purpose' => ['required', 'string', 'max:100'], 'attachment_version_id' => ['nullable', 'uuid']];
    }

    /** @return Builder<DocumentTemplate> */
    public function query(AuthorizationScope $scope): Builder
    {
        $scope = AuthorizationScope::context($scope->scope_type, $scope->company_id, $scope->site_id);

        return DocumentTemplate::query()->where('scope_id', $scope->id)->with('publication');
    }

    /** @return Builder<AttachmentVersion> */
    private function sourceQuery(AuthorizationScope $scope): Builder
    {
        $scope = AuthorizationScope::context($scope->scope_type, $scope->company_id, $scope->site_id);

        return AttachmentVersion::query()->join('document_attachments as a', 'a.id', '=', 'attachment_versions.attachment_id')
            ->join('controlled_revisions as r', 'r.id', '=', 'a.revision_id')->join('controlled_documents as d', 'd.id', '=', 'r.document_id')
            ->whereColumn('a.profile_id', 'd.profile_id')->where('d.scope_id', $scope->id)->whereNotNull('r.released_at')
            ->where('attachment_versions.mime_type', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document')
            ->whereRaw('LOWER(attachment_versions.original_name) LIKE ?', ['%.docx'])
            ->select(['attachment_versions.*', 'a.profile_id', 'd.document_code', 'r.revision_number', 'r.id as source_revision_id']);
    }

    private function canUseSource(User $actor, AttachmentVersion $version): bool
    {
        $profile = DocumentSecurityProfile::query()->find($version->profile_id);
        $descriptor = $profile ? DocumentSecurityDescriptor::fromProfile($profile) : null;

        return $this->documents->allows($actor, $descriptor, DocumentOperation::Metadata, allowAdministratorBypass: false)
            && $this->documents->allows($actor, $descriptor, DocumentOperation::Download, allowAdministratorBypass: false);
    }

    public function visible(User $actor, AuthorizationScope $scope, DocumentTemplate $template): bool
    {
        if ($template->attachment_version_id === null) {
            return true;
        }
        $source = $this->sourceQuery($scope)->whereKey($template->attachment_version_id)->first();

        return $source !== null && $this->canUseSource($actor, $source);
    }

    public function find(User $actor, AuthorizationScope $scope, string $id, bool $lock = false): DocumentTemplate
    {
        $this->authorize($actor, $scope);
        $template = $this->query($scope)->when($lock, fn (Builder $query): Builder => $query->lockForUpdate())->findOrFail($id);
        if ($lock) {
            $template->setRelation('publication', DocumentTemplatePublication::query()->where('template_id', $template->id)->lockForUpdate()->first());
        }
        abort_unless($this->visible($actor, $scope, $template), 404);

        return $template;
    }

    /** @return list<array<string, mixed>> */
    public function sources(User $actor, AuthorizationScope $scope, string $search): array
    {
        $this->authorize($actor, $scope, true);
        if (trim($search) === '') {
            return [];
        }
        $rows = [];
        foreach ($this->sourceQuery($scope)->where(fn (Builder $query): Builder => $query->where('d.document_code', 'like', '%'.$search.'%')
            ->orWhere('attachment_versions.original_name', 'like', '%'.$search.'%'))->orderByDesc('attachment_versions.created_at')->orderBy('attachment_versions.id')->lazy() as $source) {
            if ($this->canUseSource($actor, $source)) {
                $rows[] = $source->only(['id', 'original_name', 'version', 'document_code']) + ['revision' => ControlledRevision::formatIdentifier((int) $source->revision_number)];
                if (count($rows) === 20) {
                    break;
                }
            }
        }

        return $rows;
    }

    /** @param array<string, mixed> $data */
    public function create(User $actor, AuthorizationScope $scope, array $data): DocumentTemplate
    {
        return DB::transaction(function () use ($actor, $scope, $data): DocumentTemplate {
            $scope = AuthorizationScope::context($scope->scope_type, $scope->company_id, $scope->site_id);
            $this->authorize($actor, $scope, true);
            $this->rejectUnknown($data, array_keys(self::creationRules()));
            $values = Validator::make($data, self::creationRules())->validate();
            if ($values['attachment_version_id'] ?? null) {
                $source = $this->sourceQuery($scope)->whereKey($values['attachment_version_id'])->first();
                abort_unless($source && $this->canUseSource($actor, $source), 404);
            }
            $template = new DocumentTemplate($values + ['scope_id' => $scope->persist()->id, 'status' => 'inactive', 'created_by' => $actor->id]);
            $this->persist($template);
            $this->audit($actor, $template, 'created');

            return $template;
        }, 1);
    }

    /** @param array<string, mixed> $data */
    public function retire(User $actor, AuthorizationScope $scope, string $id, array $data): DocumentTemplate
    {
        return DB::transaction(function () use ($actor, $scope, $id, $data): DocumentTemplate {
            if (DB::getDriverName() === 'sqlite') {
                DB::table('document_templates')->whereRaw('0 = 1')->update(['id' => DB::raw('id')]);
            }
            $scope = AuthorizationScope::context($scope->scope_type, $scope->company_id, $scope->site_id);
            $this->authorize($actor, $scope, true);
            $template = $this->find($actor, $scope, $id, true);
            $this->rejectUnknown($data, ['reason']);
            $validated = Validator::make($data, ['reason' => ['required', 'string', 'max:2000']])->validate();
            if ($template->status !== 'inactive') {
                throw ValidationException::withMessages(['template' => 'templateImmutable']);
            }
            if ($template->publication !== null) {
                $this->roles->authorize($actor, 'templates.publish', $scope);
            }
            $template->fill(['status' => 'retired', 'retired_by' => $actor->id, 'retired_at' => now(), 'retirement_reason' => $validated['reason']]);
            $this->persist($template);
            $this->audit($actor, $template, 'retired');

            return $template;
        }, 1);
    }

    /** @param array<string, mixed> $data */
    public function publish(User $actor, AuthorizationScope $scope, string $id, array $data): DocumentTemplate
    {
        $source = null;
        try {
            return DB::transaction(function () use ($actor, $scope, $id, $data, &$source): DocumentTemplate {
                if (DB::getDriverName() === 'sqlite') {
                    DB::table('document_templates')->whereRaw('0 = 1')->update(['id' => DB::raw('id')]);
                }
                $scope = AuthorizationScope::context($scope->scope_type, $scope->company_id, $scope->site_id);
                $this->authorize($actor, $scope, true);
                $this->roles->authorize($actor, 'templates.publish', $scope);
                $template = $this->find($actor, $scope, $id, true);
                $this->rejectUnknown($data, ['reason']);
                $values = Validator::make($data, ['reason' => ['required', 'string', 'max:2000']])->validate();
                if ($template->status !== 'inactive' || $template->publication !== null || $template->attachment_version_id === null) {
                    throw ValidationException::withMessages(['template' => 'templateNotPublishable']);
                }
                $source = $this->sourceQuery($scope)->whereKey($template->attachment_version_id)->firstOrFail();
                $revision = ControlledRevision::query()->findOrFail($source->source_revision_id);
                $document = ControlledDocument::query()->lockForUpdate()->findOrFail($revision->document_id);
                if ($document->status !== 'current' || $document->current_revision_id !== $revision->id) {
                    throw ValidationException::withMessages(['template' => 'templateApprovalRequired']);
                }
                abort_unless($this->canUseSource($actor, $source), 404);
                $approval = app(DocumentApprovals::class)->state($actor, $document, $revision);
                if ($approval['status'] !== 'approved' || $approval['submission_id'] === null) {
                    throw ValidationException::withMessages(['template' => 'templateApprovalRequired']);
                }
                $stream = $this->verified($source);
                fclose($stream);
                $publication = new DocumentTemplatePublication(['template_id' => $template->id, 'attachment_version_id' => $source->id,
                    'approval_submission_id' => $approval['submission_id'], 'published_by' => $actor->id, 'published_at' => now(), 'reason' => $values['reason'],
                    'snapshot' => $source->only(['id', 'original_name', 'mime_type', 'size', 'sha256', 'version']) + ['revision_id' => $revision->id, 'document_id' => $document->id]]);
                $this->persist($publication);

                return $template->refresh()->load('publication');
            }, 1);
        } catch (Throwable $failure) {
            $this->recordUnavailable($actor, $source, $failure);
            throw $failure;
        }
    }

    public function download(User $actor, AuthorizationScope $scope, string $id): StreamedResponse
    {
        $stream = null;
        $source = null;
        try {
            return DB::transaction(function () use ($actor, $scope, $id, &$stream, &$source): StreamedResponse {
                $template = $this->find($actor, $scope, $id, true);
                abort_unless($template->status === 'inactive' && $template->publication !== null, 404);
                $source = $this->sourceQuery($scope)->whereKey($template->publication->attachment_version_id)->firstOrFail();
                abort_unless($this->canUseSource($actor, $source), 404);
                $stream = $this->verified($source);
                $this->persist(new StorageEvent(['profile_id' => $source->profile_id, 'actor_id' => $actor->id, 'action' => 'attachment.download', 'subject_id' => $source->id,
                    'after' => ['template_id' => $template->id, 'publication_id' => $template->publication->id]]));

                return app(AttachmentFiles::class)->contentResponse($source, $stream);
            }, 1);
        } catch (Throwable $failure) {
            if (is_resource($stream)) {
                fclose($stream);
            }
            $this->recordUnavailable($actor, $source, $failure);
            throw $failure;
        }
    }

    /** @return resource */
    private function verified(AttachmentVersion $source): mixed
    {
        try {
            return app(AttachmentFiles::class)->verifiedStream($source);
        } catch (Throwable $failure) {
            report($failure);
            abort(409, 'storageFileUnavailable');
        }
    }

    /** Record file failure after the failed mutation transaction has rolled back. */
    private function recordUnavailable(User $actor, ?AttachmentVersion $source, Throwable $failure): void
    {
        if ($source && $failure instanceof HttpExceptionInterface && $failure->getStatusCode() === 409 && $failure->getMessage() === 'storageFileUnavailable') {
            $this->persist(new StorageEvent(['profile_id' => $source->profile_id, 'actor_id' => $actor->id, 'action' => 'attachment.unavailable', 'subject_id' => $source->id]));
        }
    }

    /** @param array<string, mixed> $data
     * @param  list<string>  $allowed
     */
    private function rejectUnknown(array $data, array $allowed): void
    {
        if (array_diff(array_keys($data), $allowed) !== []) {
            throw ValidationException::withMessages(['template' => 'templateValidation']);
        }
    }

    private function persist(Model $record): void
    {
        if (! $record->save()) {
            throw new LogicException('Template persistence cancelled.');
        }
    }

    private function audit(User $actor, DocumentTemplate $template, string $action): void
    {
        $this->persist(new DocumentTemplateAudit(['template_id' => $template->id, 'actor_id' => $actor->id, 'action' => $action,
            'snapshot' => $template->getAttributes(), 'reason' => $template->retirement_reason]));
    }
}
