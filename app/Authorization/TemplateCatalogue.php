<?php

namespace App\Authorization;

use App\Models\AttachmentVersion;
use App\Models\AuthorizationScope;
use App\Models\ControlledRevision;
use App\Models\DocumentSecurityProfile;
use App\Models\DocumentTemplate;
use App\Models\DocumentTemplateAudit;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use LogicException;

class TemplateCatalogue
{
    public function __construct(private AccessControl $roles, private DocumentAccess $documents) {}

    public function authorize(User $actor, AuthorizationScope $scope, bool $write = false): void
    {
        $scope = AuthorizationScope::context($scope->scope_type, $scope->company_id, $scope->site_id);
        abort_unless($scope->scope_type === 'site', 403, 'rbacForbidden');
        $this->roles->authorize($actor, 'templates.view', $scope);
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

        return DocumentTemplate::query()->where('scope_id', $scope->id);
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
            ->select(['attachment_versions.*', 'a.profile_id', 'd.document_code', 'r.revision_number']);
    }

    private function canUseSource(User $actor, AttachmentVersion $version): bool
    {
        $profile = DocumentSecurityProfile::query()->find($version->profile_id);
        $descriptor = $profile ? DocumentSecurityDescriptor::fromProfile($profile) : null;

        return $this->documents->allows($actor, $descriptor, DocumentOperation::Metadata)
            && $this->documents->allows($actor, $descriptor, DocumentOperation::Download);
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
            $template->fill(['status' => 'retired', 'retired_by' => $actor->id, 'retired_at' => now(), 'retirement_reason' => $validated['reason']]);
            $this->persist($template);
            $this->audit($actor, $template, 'retired');

            return $template;
        }, 1);
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
