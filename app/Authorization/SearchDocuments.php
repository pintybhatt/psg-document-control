<?php

namespace App\Authorization;

use App\Models\ConfidentialityClearance;
use App\Models\DocumentSecurityProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SearchDocuments
{
    public function __construct(private AccessControl $roles, private DocumentAccess $access) {}

    /**
     * The caller must authorize the exact workspace before building this query.
     * Clearance and grant predicates mirror DocumentAccess for metadata only.
     *
     * @return EloquentBuilder<DocumentSecurityProfile>
     */
    public function visibleProfiles(User $actor, DocumentScope $scope): EloquentBuilder
    {
        app(ManageIncomingCorrespondence::class)->authorizeContext($actor, $scope);
        $profiles = DocumentSecurityProfile::query()->where('active', true)
            ->where('company_id', $scope->companyId)->where('site_id', $scope->siteId)->where('department_id', $scope->departmentId)
            ->whereIn('classification', array_column(Confidentiality::cases(), 'value'));
        if ($this->roles->isSystemAdministrator($actor)) {
            return $profiles;
        }
        $clearances = ConfidentialityClearance::query()->where('user_id', $actor->id)->where('company_id', $scope->companyId)
            ->where('site_id', $scope->siteId)->where('department_id', $scope->departmentId)->where('active', true)->whereNull('revoked_at')
            ->where(fn (EloquentBuilder $query): EloquentBuilder => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))->select('classification');

        return $profiles->where(fn (EloquentBuilder $query): EloquentBuilder => $query->where('classification', 'normal')->orWhereIn('classification', $clearances))
            ->where(fn (EloquentBuilder $query): EloquentBuilder => $query->where(fn (EloquentBuilder $query): EloquentBuilder => $query->where('restricted', false)->where('classification', '<>', 'highly_confidential'))
                ->orWhereHas('accessGrants', fn (EloquentBuilder $query): EloquentBuilder => $query->where('user_id', $actor->id)->where('operation', 'metadata')->where('active', true)->whereNull('revoked_at')
                    ->where(fn (EloquentBuilder $query): EloquentBuilder => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))));
    }

    /** @param array<string, mixed> $filters */
    public function query(User $actor, DocumentScope $scope, array $filters): Builder
    {
        $profiles = $this->visibleProfiles($actor, $scope);
        if (! empty($filters['classification'])) {
            $profiles->where('classification', $filters['classification']);
        }
        $union = null;
        foreach (['incoming', 'outgoing', 'controlled'] as $register) {
            if (! empty($filters['register']) && $filters['register'] !== $register) {
                continue;
            }
            if ($register !== 'controlled' && (isset($filters['revision']) || ! empty($filters['revision_state']) || ! empty($filters['owner']))) {
                continue;
            }
            $table = match ($register) {
                'incoming' => 'incoming_correspondences', 'outgoing' => 'outgoing_correspondences', default => 'controlled_documents'
            };
            $query = DB::table($table.' as d')->where('d.scope_id', $scope->authorizationScope()->id)
                ->whereIn('d.profile_id', (clone $profiles)->where('resource_type', $register)->select('id'));
            $number = $register === 'controlled' ? 'd.document_code' : 'd.internal_number';
            $date = match ($register) {
                'incoming' => 'd.received_date', 'outgoing' => 'd.document_date', default => 'r.effective_date'
            };
            if ($register === 'controlled') {
                $query->leftJoin('controlled_revisions as r', function ($join): void {
                    $join->on('r.document_id', '=', 'd.id')->whereRaw('r.id = COALESCE(d.current_revision_id, (SELECT MAX(latest.id) FROM controlled_revisions latest WHERE latest.document_id = d.id))');
                });
            }
            if ($register !== 'controlled') {
                $operation = $register === 'incoming' ? 'incoming_correspondence_operations' : 'outgoing_operations';
                $query->leftJoin($operation.' as op', 'op.correspondence_id', '=', 'd.id');
            }
            $status = $register === 'controlled' ? 'd.status' : ($register === 'incoming' ? "CASE WHEN d.status = 'draft' THEN 'draft' ELSE COALESCE(op.status, 'received') END" : 'COALESCE(op.status, d.status)');
            $query->select(['d.id', 'd.profile_id', 'd.internal_number', 'd.type_id', 'd.created_at'])
                ->selectRaw($status.' as status')->selectRaw(($register === 'controlled' ? "CASE WHEN d.status = 'current' AND r.released_at IS NOT NULL THEN r.revision_number ELSE NULL END" : 'NULL').' as authoritative_revision')
                ->selectRaw(($register === 'controlled' ? '(SELECT dr.revision_number FROM controlled_revisions dr WHERE dr.document_id = d.id AND dr.released_at IS NULL LIMIT 1)' : 'NULL').' as draft_revision')
                ->selectRaw('? as register', [$register])->selectRaw($number.' as number')->selectRaw($date.' as document_date')
                ->selectRaw(($register === 'controlled' ? 'r.title_en' : 'd.subject').' as title_en')
                ->selectRaw(($register === 'controlled' ? 'r.title_lo' : 'NULL').' as title_lo')
                ->selectRaw(($register === 'outgoing' ? 'd.official_number' : 'NULL').' as official_number')
                ->selectRaw(($register === 'incoming' ? 'd.external_reference' : 'NULL').' as external_reference');
            if (! empty($filters['type_id'])) {
                $query->where('d.type_id', $filters['type_id']);
            }
            if (! empty($filters['category_id'])) {
                $query->whereIn('d.type_id', DB::table('document_types')->where('category_id', $filters['category_id'])->select('id'));
            }
            if (! empty($filters['status'])) {
                $query->whereRaw($status.' = ?', [$filters['status']]);
            }
            foreach (['date_from' => '>=', 'date_to' => '<='] as $filter => $operator) {
                if (! empty($filters[$filter])) {
                    $query->where($date, $filter === 'date_to' ? '<' : '>=', $filter === 'date_to' ? Carbon::parse($filters[$filter])->addDay()->format('Y-m-d') : $filters[$filter]);
                }
            }
            if ($register === 'controlled' && (isset($filters['revision']) || ! empty($filters['revision_state']) || ! empty($filters['owner']))) {
                $query->whereExists(function (Builder $revision) use ($filters): void {
                    $revision->selectRaw('1')->from('controlled_revisions as rev')->whereColumn('rev.document_id', 'd.id');
                    if (isset($filters['revision'])) {
                        $revision->where('rev.revision_number', (int) $filters['revision']);
                    }
                    match ($filters['revision_state'] ?? '') {
                        'draft' => $revision->whereNull('rev.released_at'),
                        'current' => $revision->whereNotNull('rev.released_at')->whereColumn('rev.id', 'd.current_revision_id')->where('d.status', 'current'),
                        'superseded' => $revision->whereNotNull('rev.released_at')->where(fn (Builder $q): Builder => $q->whereColumn('rev.id', '<>', 'd.current_revision_id')->orWhere('d.status', 'superseded')),
                        default => null,
                    };
                    if (! empty($filters['owner'])) {
                        $value = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filters['owner']).'%';
                        $revision->where(fn (Builder $q): Builder => $q->whereRaw("rev.owner_name LIKE ? ESCAPE '!'", [$value])->orWhereRaw("rev.custodian_name LIKE ? ESCAPE '!'", [$value])
                            ->orWhereExists(fn (Builder $person): Builder => $person->selectRaw('1')->from('users')->where(fn (Builder $id): Builder => $id->whereColumn('users.id', 'rev.owner_id')->orWhereColumn('users.id', 'rev.custodian_id'))->whereRaw("users.name LIKE ? ESCAPE '!'", [$value])));
                    }
                });
            }
            if (! empty($filters['search'])) {
                $term = (string) $filters['search'];
                $exact = ! empty($filters['exact']);
                $value = $exact ? $term : '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term).'%';
                $columns = ['d.internal_number'];
                $columns = [...$columns, ...match ($register) {
                    'incoming' => ['d.external_reference', 'd.subject', 'd.description_en', 'd.description_lo', 'd.sender_organization'], 'outgoing' => ['d.official_number', 'd.subject', 'd.description_en', 'd.description_lo'], default => ['d.document_code']
                }];
                $query->where(function (Builder $query) use ($columns, $value, $exact, $register): void {
                    foreach ($columns as $column) {
                        $query->orWhereRaw($column.($exact ? ' = ?' : " LIKE ? ESCAPE '!'"), [$value]);
                    }
                    if ($register === 'controlled') {
                        $query->orWhereExists(function (Builder $revision) use ($value, $exact): void {
                            $revision->selectRaw('1')->from('controlled_revisions as rev')->whereColumn('rev.document_id', 'd.id')
                                ->where(function (Builder $text) use ($value, $exact): void {
                                    foreach (['title_en', 'title_lo', 'description_en', 'description_lo', 'revision_reason', 'change_description'] as $column) {
                                        $text->orWhereRaw('rev.'.$column.($exact ? ' = ?' : " LIKE ? ESCAPE '!'"), [$value]);
                                    }
                                });
                        });
                    }
                });
            }
            $union = $union ? $union->unionAll($query) : $query;
        }
        if ($union === null) {
            $union = DB::table('controlled_documents as d')->selectRaw('d.id, d.created_at, d.document_code as number, NULL as document_date, ? as register', ['controlled'])->whereRaw('1 = 0');
        }
        $query = DB::query()->fromSub($union, 'results');
        match ($filters['sort'] ?? 'recent') {
            'oldest' => $query->orderBy('created_at'), 'number' => $query->orderBy('number'), 'date' => $query->orderByDesc('document_date'), default => $query->orderByDesc('created_at'),
        };

        return $query->orderBy('register')->orderBy('id');
    }

    public function audit(User $actor, int $profileId): void
    {
        $profile = DocumentSecurityProfile::findOrFail($profileId);
        abort_unless($this->access->allows($actor, DocumentSecurityDescriptor::fromProfile($profile), DocumentOperation::Metadata), 404);
    }
}
