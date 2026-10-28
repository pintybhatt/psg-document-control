<?php

namespace App\Authorization;

use App\Models\ControlledRevision;
use App\Models\DocumentSecurityAudit;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;

class DocumentReporting
{
    public function __construct(private SearchDocuments $search, private CorrespondenceContexts $workspaces, private AccessControl $roles) {}

    /** @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    public function contexts(User $actor, array $filters, ?string $permission = null): array
    {
        $contexts = array_values(array_filter($this->workspaces->forUser($actor), function (array $context) use ($filters, $permission): bool {
            return (! empty($filters['all_sites']) || ($context['company_id'] === (int) $filters['company_id'] && $context['site_id'] === (int) $filters['site_id']))
                && (! empty($filters['all_departments']) || $context['department_id'] === (isset($filters['department_id']) ? (int) $filters['department_id'] : null))
                && ($permission === null || in_array($permission, $context['permissions'], true));
        }));
        abort_if($contexts === [], 403, 'rbacForbidden');

        return $contexts;
    }

    /** One row per document identity; recipients, revisions and copies never multiply this dataset.
     * @param  array<string, mixed>  $filters
     * @param  list<array<string, mixed>>  $contexts
     */
    public function documents(User $actor, array $filters, array $contexts): Builder
    {
        $union = null;
        foreach ($contexts as $context) {
            $scope = new DocumentScope($context['company_id'], $context['site_id'], $context['department_id']);
            $searchFilters = array_diff_key($filters, ['owner' => true, 'revision' => true, 'revision_state' => true]);
            $query = $this->search->query($actor, $scope, $searchFilters)->reorder();
            $union = $union ? $union->unionAll($query) : $query;
        }
        $query = DB::query()->fromSub($union, 'd')->join('document_security_profiles as p', 'p.id', '=', 'd.profile_id')
            ->join('document_types as type', 'type.id', '=', 'd.type_id')->join('document_categories as category', 'category.id', '=', 'type.category_id')
            ->leftJoin('departments as department', 'department.id', '=', 'p.department_id')
            ->leftJoin('companies as company', 'company.id', '=', 'p.company_id')->leftJoin('sites as site', 'site.id', '=', 'p.site_id')
            ->leftJoin('incoming_correspondences as inc', fn (JoinClause $join): JoinClause => $join->on('inc.id', '=', 'd.id')->where('d.register', 'incoming'))
            ->leftJoin('incoming_correspondence_operations as io', 'io.correspondence_id', '=', 'inc.id')
            ->leftJoin('outgoing_correspondences as outgoing', fn (JoinClause $join): JoinClause => $join->on('outgoing.id', '=', 'd.id')->where('d.register', 'outgoing'))
            ->leftJoin('outgoing_operations as oo', 'oo.correspondence_id', '=', 'outgoing.id')
            ->leftJoin('controlled_documents as cd', fn (JoinClause $join): JoinClause => $join->on('cd.id', '=', 'd.id')->where('d.register', 'controlled'))
            ->leftJoin('controlled_revisions as released', fn (JoinClause $join): JoinClause => $join->on('released.id', '=', 'cd.current_revision_id')->whereNotNull('released.released_at'))
            ->leftJoin('controlled_revisions as draft_focus', fn (JoinClause $join): JoinClause => $join->on('draft_focus.document_id', '=', 'cd.id')->where('draft_focus.draft_slot', 1))
            ->leftJoin('users as owner', fn (JoinClause $join): JoinClause => $join->whereRaw('owner.id = COALESCE(released.owner_id, draft_focus.owner_id)'))
            ->leftJoin('users as custodian', fn (JoinClause $join): JoinClause => $join->whereRaw('custodian.id = COALESCE(released.custodian_id, draft_focus.custodian_id)'))
            ->select(['d.*', 'p.company_id', 'p.site_id', 'p.department_id', 'p.classification', 'type.category_id', 'io.due_date', 'released.review_date'])
            ->selectRaw('company.name_en as company_en, company.name_lo as company_lo, site.name_en as site_en, site.name_lo as site_lo, department.name_en as department_en, department.name_lo as department_lo')
            ->selectRaw('type.name_en as type_en, type.name_lo as type_lo, category.name_en as category_en, category.name_lo as category_lo')
            ->selectRaw('COALESCE(released.owner_id, draft_focus.owner_id) as owner_id, COALESCE(released.owner_name, owner.name) as owner_name, COALESCE(released.custodian_name, custodian.name) as custodian_name')
            ->selectRaw('CASE WHEN d.internal_number IS NOT NULL THEN 1 ELSE 0 END as registered')
            ->selectRaw("CASE WHEN d.register = 'incoming' AND d.internal_number IS NOT NULL AND d.status <> 'closed' AND io.response_required = 1 AND io.response_completed_at IS NULL THEN 1 ELSE 0 END as pending_response")
            ->selectRaw("CASE WHEN d.register = 'outgoing' AND EXISTS (SELECT 1 FROM outgoing_recipients recipient JOIN outgoing_deliveries delivery ON delivery.recipient_id = recipient.id WHERE recipient.correspondence_id = d.id AND delivery.acknowledgment_date IS NOT NULL) THEN 1 ELSE 0 END as acknowledged")
            ->selectRaw("CASE WHEN d.register = 'outgoing' AND oo.dispatch_date IS NOT NULL THEN 1 ELSE 0 END as dispatched")
            ->selectRaw("CASE WHEN d.register = 'outgoing' AND EXISTS (SELECT 1 FROM outgoing_recipients recipient WHERE recipient.correspondence_id = d.id AND recipient.kind = 'primary') AND NOT EXISTS (SELECT 1 FROM outgoing_recipients recipient WHERE recipient.correspondence_id = d.id AND recipient.kind = 'primary' AND NOT EXISTS (SELECT 1 FROM outgoing_deliveries delivery WHERE delivery.recipient_id = recipient.id AND delivery.delivery_date IS NOT NULL)) THEN 1 ELSE 0 END as delivered");

        $documents = DB::query()->fromSub($query, 'records');
        if (! empty($filters['owner']) && ($filters['view'] ?? 'documents') !== 'revisions') {
            $term = $this->term($filters['owner']);
            $documents->where(fn (Builder $query): Builder => $query->whereRaw("owner_name LIKE ? ESCAPE '!'", [$term])->orWhereRaw("custodian_name LIKE ? ESCAPE '!'", [$term]));
        }

        return $documents;
    }

    /** @param array<string, mixed> $filters */
    public function physical(Builder $documents, array $filters = []): Builder
    {
        $query = DB::query()->fromSub($documents, 'document')->join('physical_copies as copy', 'copy.profile_id', '=', 'document.profile_id')
            ->join('storage_locations as location', 'location.id', '=', 'copy.location_id')->leftJoin('users as custodian', 'custodian.id', '=', 'copy.custodian_id')
            ->select(['document.*', 'copy.id as copy_id', 'copy.identifier', 'copy.kind', 'copy.status as custody_status', 'copy.checkout_date', 'copy.expected_return_date', 'custodian.name as custodian', 'location.room', 'location.cabinet', 'location.shelf', 'location.box']);
        $query->selectSub($this->discrepancies()->whereColumn('inventory.subject_id', 'copy.id')->selectRaw('1')->limit(1), 'inventory_discrepancy');
        $query->whereIn('copy.id', $this->search->physicalQuery($filters)->select('pc.id'));
        if (($filters['metric'] ?? '') === 'checkout_overdue') {
            $query->where('copy.status', 'checked_out')->whereDate('copy.expected_return_date', '<', today());
        }
        if (($filters['metric'] ?? '') === 'inventory_discrepancy') {
            $query->whereIn('copy.id', $this->discrepancies()->select('subject_id'));
        }

        return $query;
    }

    private function term(string $value): string
    {
        return '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value).'%';
    }

    public function discrepancies(): Builder
    {
        return DB::table('storage_events as inventory')->where('inventory.action', 'custody.inventory')->whereJsonContains('inventory.after->observation', 'discrepancy')
            ->whereNotExists(fn (Builder $later): Builder => $later->selectRaw('1')->from('storage_events as later')->whereColumn('later.profile_id', 'inventory.profile_id')->whereColumn('later.subject_id', 'inventory.subject_id')->where('later.action', 'custody.inventory')->whereColumn('later.id', '>', 'inventory.id'));
    }

    public function metric(Builder $documents, string $metric): Builder
    {
        $today = today()->format('Y-m-d');
        match ($metric) {
            'registered' => $documents->where('registered', 1),
            'open' => $documents->where('registered', 1)->whereIn('register', ['incoming', 'outgoing'])->where('status', '<>', 'closed'),
            'closed', 'in_progress' => $documents->where('status', $metric),
            'pending_response', 'dispatched', 'delivered', 'acknowledged' => $documents->where($metric, 1),
            'overdue' => $documents->where('register', 'incoming')->where('registered', 1)->where('status', '<>', 'closed')->whereDate('due_date', '<', $today),
            'due_soon' => $documents->where('register', 'incoming')->where('registered', 1)->where('status', '<>', 'closed')->whereBetween(DB::raw('DATE(due_date)'), [$today, today()->addDays(7)->format('Y-m-d')]),
            'review_overdue' => $documents->whereNotNull('authoritative_revision')->whereDate('review_date', '<', $today),
            'review_soon' => $documents->whereNotNull('authoritative_revision')->whereBetween(DB::raw('DATE(review_date)'), [$today, today()->addDays(30)->format('Y-m-d')]),
            'checkout_overdue' => $documents->whereIn('profile_id', DB::table('physical_copies')->where('status', 'checked_out')->whereDate('expected_return_date', '<', $today)->select('profile_id')),
            'inventory_discrepancy' => $documents->whereIn('profile_id', $this->discrepancies()->select('profile_id')),
            default => null,
        };

        return $documents;
    }

    /** @param array<string, mixed> $filters */
    public function revisions(Builder $documents, array $filters): Builder
    {
        $query = DB::query()->fromSub($documents, 'document')->join('controlled_revisions as revision', fn (JoinClause $join): JoinClause => $join->on('revision.document_id', '=', 'document.id')->where('document.register', 'controlled'))
            ->leftJoin('users as revision_owner', 'revision_owner.id', '=', 'revision.owner_id')->leftJoin('users as revision_custodian', 'revision_custodian.id', '=', 'revision.custodian_id')
            ->select(['document.*', 'revision.id as revision_id', 'revision.revision_number', 'revision.issue_date', 'revision.effective_date', 'revision.review_date as revision_review_date', 'revision.revision_reason', 'revision.change_description', 'revision.title_en as revision_title_en', 'revision.title_lo as revision_title_lo'])
            ->selectRaw("CASE WHEN revision.released_at IS NULL THEN 'draft' WHEN revision.id = document.current_revision_id THEN document.status ELSE 'superseded' END as revision_status")
            ->selectRaw('COALESCE(revision.owner_name, revision_owner.name) as revision_owner, COALESCE(revision.custodian_name, revision_custodian.name) as revision_custodian');
        $query = DB::query()->fromSub($query, 'revisions');
        if (isset($filters['revision'])) {
            $query->where('revision_number', (int) $filters['revision']);
        }
        if (! empty($filters['revision_state'])) {
            $query->where('revision_status', $filters['revision_state']);
        }
        if (! empty($filters['owner'])) {
            $value = $this->term($filters['owner']);
            $query->where(fn (Builder $query): Builder => $query->whereRaw("revision_owner LIKE ? ESCAPE '!'", [$value])->orWhereRaw("revision_custodian LIKE ? ESCAPE '!'", [$value]));
        }

        return $query;
    }

    /** @return array<string, int> */
    public function totals(Builder $documents, array $filters = []): array
    {
        $today = today()->format('Y-m-d');
        $soon = today()->addDays(7)->format('Y-m-d');
        $reviewSoon = today()->addDays(30)->format('Y-m-d');
        $row = (clone $documents)->selectRaw("COUNT(*) as total,
            SUM(CASE WHEN register = 'incoming' THEN 1 ELSE 0 END) as incoming,
            SUM(CASE WHEN register = 'outgoing' THEN 1 ELSE 0 END) as outgoing,
            SUM(CASE WHEN register = 'controlled' THEN 1 ELSE 0 END) as controlled,
            SUM(CASE WHEN register = 'incoming' AND registered = 1 AND status <> 'closed' THEN 1 ELSE 0 END) as open_incoming,
            SUM(CASE WHEN register = 'outgoing' AND registered = 1 AND status <> 'closed' THEN 1 ELSE 0 END) as open_outgoing,
            SUM(CASE WHEN register <> 'controlled' AND status = 'closed' THEN 1 ELSE 0 END) as closed,
            SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
            SUM(pending_response) as pending_response,
            SUM(CASE WHEN register = 'incoming' AND registered = 1 AND status <> 'closed' AND DATE(due_date) < ? THEN 1 ELSE 0 END) as overdue,
            SUM(CASE WHEN register = 'incoming' AND registered = 1 AND status <> 'closed' AND DATE(due_date) BETWEEN ? AND ? THEN 1 ELSE 0 END) as due_soon,
            SUM(CASE WHEN register = 'controlled' AND authoritative_revision IS NOT NULL AND DATE(review_date) < ? THEN 1 ELSE 0 END) as review_overdue,
            SUM(CASE WHEN register = 'controlled' AND authoritative_revision IS NOT NULL AND DATE(review_date) BETWEEN ? AND ? THEN 1 ELSE 0 END) as review_soon,
            SUM(CASE WHEN authoritative_revision IS NOT NULL THEN 1 ELSE 0 END) as authoritative,
            SUM(CASE WHEN draft_revision IS NOT NULL THEN 1 ELSE 0 END) as draft_documents,
            SUM(CASE WHEN register = 'controlled' AND status = 'obsolete' THEN 1 ELSE 0 END) as obsolete,
            SUM(CASE WHEN register = 'controlled' AND status = 'archived' THEN 1 ELSE 0 END) as archived,
            SUM(dispatched) as dispatched, SUM(delivered) as delivered, SUM(acknowledged) as acknowledged,
            SUM(CASE WHEN register = 'incoming' AND registered = 1 THEN 1 ELSE 0 END) as registered_incoming,
            SUM(CASE WHEN register = 'outgoing' AND registered = 1 THEN 1 ELSE 0 END) as registered_outgoing", [$today, $today, $soon, $today, $today, $reviewSoon])->first();
        $totals = array_map(intval(...), (array) $row);
        $physical = DB::query()->fromSub($this->physical(clone $documents, $filters), 'copies')->selectRaw("COUNT(*) as physical_copies, SUM(COALESCE(inventory_discrepancy, 0)) as outstanding_discrepancies, SUM(CASE WHEN kind = 'original' THEN 1 ELSE 0 END) as originals, SUM(CASE WHEN custody_status = 'stored' THEN 1 ELSE 0 END) as `stored`, SUM(CASE WHEN custody_status = 'checked_out' THEN 1 ELSE 0 END) as checked_out, SUM(CASE WHEN custody_status = 'checked_out' AND DATE(expected_return_date) < ? THEN 1 ELSE 0 END) as checkout_overdue", [$today]);
        $physical = $physical->first();

        return $totals + array_map(intval(...), (array) $physical);
    }

    /** @return array<string, mixed> */
    public function trends(Builder $documents): array
    {
        $groups = [];
        foreach (['register' => ['register'], 'type_en' => ['type_id', 'type_en', 'type_lo'], 'category_en' => ['category_id', 'category_en', 'category_lo'], 'department_en' => ['department_id', 'department_en', 'department_lo'], 'site_en' => ['site_id', 'site_en', 'site_lo'], 'company_en' => ['company_id', 'company_en', 'company_lo'], 'status' => ['register', 'status'], 'owner_name' => ['owner_id', 'owner_name']] as $key => $columns) {
            $groups[$key] = (clone $documents)->select($columns)->selectRaw('COUNT(*) as total')->groupBy(...$columns)->orderByDesc('total')->orderBy($columns[0])->limit(20)->get();
        }
        $groups['monthly'] = (clone $documents)->selectRaw('SUBSTR(document_date, 1, 7) as month, register, COUNT(*) as total')->whereNotNull('document_date')
            ->groupByRaw('SUBSTR(document_date, 1, 7), register')->orderByDesc('month')->orderBy('register')->limit(36)->get();

        return $groups;
    }

    /** @param list<array<string, mixed>> $contexts
     * @param  list<int>  $profileIds
     */
    public function audit(User $actor, array $contexts, array $profileIds, string $event = 'report.viewed'): void
    {
        $privileged = $this->roles->isSystemAdministrator($actor);
        foreach ($contexts as $context) {
            $audit = DocumentSecurityAudit::query()->create(['actor_id' => $actor->id, 'event' => 'access.allowed', 'operation' => 'metadata', 'reason' => $privileged ? 'system_administrator' : null,
                'after' => ['surface' => $event, 'company_id' => $context['company_id'], 'site_id' => $context['site_id'], 'department_id' => $context['department_id'], 'returned_profiles' => $profileIds]]);
            if (! $audit->exists) {
                throw new LogicException('Report audit persistence cancelled.');
            }
        }
    }

    /** @return array<string, mixed> */
    public function row(object $row): array
    {
        $values = (array) $row;
        foreach (['document_date', 'due_date', 'review_date', 'checkout_date', 'expected_return_date', 'issue_date', 'effective_date', 'revision_review_date'] as $date) {
            if (array_key_exists($date, $values)) {
                $values[$date] = $values[$date] ? Carbon::parse($values[$date])->format('Y-m-d') : null;
            }
        }
        $route = $row->register === 'controlled' ? 'controlled-documents' : $row->register;
        $values['href'] = route('dcms.'.$route, ['unit' => $row->site_id.':'.($row->department_id ?? 'site'), 'record' => $row->id], false);
        $values['key'] = $values['copy_id'] ?? (isset($values['revision_id']) ? 'revision:'.$values['revision_id'] : $row->register.':'.$row->id);
        if (isset($values['revision_id'])) {
            $values['title_en'] = $values['revision_title_en'];
            $values['title_lo'] = $values['revision_title_lo'];
        }
        if (isset($values['revision_number'])) {
            $values['revision_identifier'] = ControlledRevision::formatIdentifier((int) $values['revision_number']);
        }
        unset($values['profile_id'], $values['current_revision_id']);

        return $values;
    }
}
