<?php

namespace App\Authorization;

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
            $query = $this->search->query($actor, $scope, $filters)->reorder();
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
            ->leftJoin('users as owner', 'owner.id', '=', 'released.owner_id')
            ->select(['d.*', 'p.company_id', 'p.site_id', 'p.department_id', 'p.classification', 'type.category_id', 'io.due_date', 'released.review_date', 'released.owner_id'])
            ->selectRaw('company.name_en as company_en, company.name_lo as company_lo, site.name_en as site_en, site.name_lo as site_lo, department.name_en as department_en, department.name_lo as department_lo')
            ->selectRaw('type.name_en as type_en, type.name_lo as type_lo, category.name_en as category_en, category.name_lo as category_lo')
            ->selectRaw('COALESCE(released.owner_name, owner.name) as owner_name')
            ->selectRaw('CASE WHEN d.internal_number IS NOT NULL THEN 1 ELSE 0 END as registered')
            ->selectRaw("CASE WHEN d.register = 'incoming' AND d.internal_number IS NOT NULL AND d.status <> 'closed' AND io.response_required = 1 AND io.response_completed_at IS NULL THEN 1 ELSE 0 END as pending_response")
            ->selectRaw("CASE WHEN d.register = 'outgoing' AND EXISTS (SELECT 1 FROM outgoing_recipients recipient JOIN outgoing_deliveries delivery ON delivery.recipient_id = recipient.id WHERE recipient.correspondence_id = d.id AND delivery.acknowledgment_date IS NOT NULL) THEN 1 ELSE 0 END as acknowledged")
            ->selectRaw("CASE WHEN d.register = 'outgoing' AND oo.dispatch_date IS NOT NULL THEN 1 ELSE 0 END as dispatched")
            ->selectRaw("CASE WHEN d.register = 'outgoing' AND EXISTS (SELECT 1 FROM outgoing_recipients recipient WHERE recipient.correspondence_id = d.id AND recipient.kind = 'primary') AND NOT EXISTS (SELECT 1 FROM outgoing_recipients recipient WHERE recipient.correspondence_id = d.id AND recipient.kind = 'primary' AND NOT EXISTS (SELECT 1 FROM outgoing_deliveries delivery WHERE delivery.recipient_id = recipient.id AND delivery.delivery_date IS NOT NULL)) THEN 1 ELSE 0 END as delivered");

        return DB::query()->fromSub($query, 'records');
    }

    /** @param array<string, mixed> $filters */
    public function physical(Builder $documents, array $filters = []): Builder
    {
        return DB::query()->fromSub($documents, 'document')->join('physical_copies as copy', 'copy.profile_id', '=', 'document.profile_id')
            ->join('storage_locations as location', 'location.id', '=', 'copy.location_id')->leftJoin('users as custodian', 'custodian.id', '=', 'copy.custodian_id')
            ->select(['document.*', 'copy.id as copy_id', 'copy.identifier', 'copy.kind', 'copy.status as custody_status', 'copy.checkout_date', 'copy.expected_return_date', 'custodian.name as custodian', 'location.room', 'location.cabinet', 'location.shelf', 'location.box']);
    }

    /** @return array<string, int> */
    public function totals(Builder $documents): array
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
        $physical = DB::query()->fromSub($this->physical(clone $documents), 'copies')->selectRaw("COUNT(*) as physical_copies, SUM(CASE WHEN kind = 'original' THEN 1 ELSE 0 END) as originals, SUM(CASE WHEN custody_status = 'stored' THEN 1 ELSE 0 END) as stored, SUM(CASE WHEN custody_status = 'checked_out' THEN 1 ELSE 0 END) as checked_out, SUM(CASE WHEN custody_status = 'checked_out' AND DATE(expected_return_date) < ? THEN 1 ELSE 0 END) as checkout_overdue", [$today]);
        $physical = $physical->first();

        return $totals + array_map(intval(...), (array) $physical);
    }

    /** @return array<string, mixed> */
    public function trends(Builder $documents): array
    {
        $groups = [];
        foreach (['register' => ['register'], 'category_en' => ['category_id', 'category_en', 'category_lo'], 'department_en' => ['department_id', 'department_en', 'department_lo'], 'site_en' => ['site_id', 'site_en', 'site_lo'], 'company_en' => ['company_id', 'company_en', 'company_lo'], 'status' => ['register', 'status'], 'owner_name' => ['owner_id', 'owner_name']] as $key => $columns) {
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
        foreach (['document_date', 'due_date', 'review_date', 'checkout_date', 'expected_return_date'] as $date) {
            if (array_key_exists($date, $values)) {
                $values[$date] = $values[$date] ? Carbon::parse($values[$date])->format('Y-m-d') : null;
            }
        }
        $route = $row->register === 'controlled' ? 'controlled-documents' : $row->register;
        $values['href'] = route('dcms.'.$route, ['unit' => $row->site_id.':'.($row->department_id ?? 'site'), 'record' => $row->id], false);
        $values['key'] = $row->register.':'.$row->id;
        unset($values['profile_id'], $values['current_revision_id']);

        return $values;
    }
}
