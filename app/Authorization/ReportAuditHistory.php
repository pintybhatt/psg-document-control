<?php

namespace App\Authorization;

use App\ManagementReport;
use App\Models\ControlledRevision;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ReportAuditHistory
{
    private const DOCUMENT_COLUMNS = ['id', 'register', 'profile_id', 'company_id', 'site_id', 'department_id', 'number', 'official_number', 'title_en', 'title_lo', 'document_date', 'status', 'category_en', 'category_lo', 'type_en', 'type_lo'];

    private const SAFE_VALUES = ['document_revision', 'status', 'due_date', 'response_required', 'response_completed', 'responsible_user_id', 'responsible_department_id', 'dispatch_date', 'dispatch_channel', 'dispatch_reference', 'dispatch_remarks', 'closure_remarks', 'subject', 'sender_organization', 'external_reference', 'correspondence_date', 'received_date', 'document_date', 'signatory_name', 'signatory_title', 'description_en', 'description_lo', 'priority', 'channel', 'delivery_date', 'acknowledged_by', 'acknowledgment_date', 'acknowledgment_reference', 'location_label', 'custodian_name', 'checkout_date', 'expected_return_date', 'observation', 'classification', 'restricted', 'active'];

    public function __construct(private AccessControl $roles, private DocumentReporting $reports, private ManagementReport $labels) {}

    /** @param array<string, mixed> $filters
     * @param  list<array<string, mixed>>  $contexts
     */
    public function query(User $actor, Builder $documents, array $filters, array $contexts): Builder
    {
        if (! empty($filters['include_unattributed'])) {
            abort_unless($this->roles->isSystemAdministrator($actor), 403, 'rbacForbidden');
        }
        $security = ($filters['view'] ?? '') === 'security';
        $queries = [];
        if (! $security) {
            foreach (['incoming' => 'incoming_correspondence_audits', 'outgoing' => 'outgoing_correspondence_audits', 'controlled' => 'controlled_document_audits'] as $register => $table) {
                $key = $register === 'controlled' ? 'document_id' : 'correspondence_id';
                $query = DB::table($table.' as e')->joinSub(clone $documents, 'd', fn (JoinClause $join): JoinClause => $join->on('d.id', '=', 'e.'.$key)->where('d.register', $register));
                $queries[] = $this->project($query, $register, 'e.action', $register === 'controlled' ? 'e.reason' : null, null, 'e.changed_fields');
            }
            foreach (['incoming' => 'incoming_operation_audits', 'outgoing' => 'outgoing_operation_audits'] as $register => $table) {
                $query = DB::table($table.' as e')->joinSub(clone $documents, 'd', fn (JoinClause $join): JoinClause => $join->on('d.id', '=', 'e.correspondence_id')->where('d.register', $register));
                $queries[] = $this->project($query, $register.'-operations', 'e.action', 'e.note', 'e.before', 'e.after');
            }
            $numbering = DB::table('issued_document_numbers as e')->joinSub(clone $documents, 'd', fn (JoinClause $join): JoinClause => $join->on('e.record_type', '=', 'd.register')->whereColumn('e.record_key', DB::raw('CAST(d.id AS CHAR)')));
            $queries[] = $this->project($numbering, 'numbering', "'number.issued'", null, null, null, 'e.issued_by', false, 'e.number');
        } else {
            $decisions = DB::table('document_security_audits as e')->joinSub(clone $documents, 'd', 'd.profile_id', '=', 'e.profile_id');
            $queries[] = $this->project($decisions, 'security', 'e.event', 'e.reason', null, null, 'e.actor_id', false, null, 'e.operation');
        }
        $storage = DB::table('storage_events as e')->joinSub(clone $documents, 'd', function (JoinClause $join) use ($security): void {
            $join->on('d.profile_id', '=', 'e.profile_id');
            if ($security) {
                $join->orWhere(function (JoinClause $join): void {
                    $join->whereNull('e.profile_id')->where('e.action', 'request.denied')->whereColumn('e.after->record', '=', DB::raw('CAST(d.id AS CHAR)'))
                        ->where(function (JoinClause $join): void {
                            foreach (['incoming', 'outgoing', 'controlled'] as $register) {
                                $join->orWhere(fn (JoinClause $join): JoinClause => $join->where('d.register', $register)->whereIn('e.after->route', ['api.v1.storage.attachments.preview', 'api.v1.storage.attachments.download'])->where('e.after->type', $register));
                            }
                        });
                });
            }
        });
        if (! $security) {
            $storage->where('e.action', '<>', 'request.denied');
        } else {
            $storage->whereIn('e.action', ['attachment.preview', 'attachment.download', 'attachment.unavailable', 'request.denied']);
        }
        $queries[] = $this->project($storage, $security ? 'security-storage' : 'storage', 'e.action', 'e.reason', $security ? null : 'e.before', $security ? null : 'e.after', 'e.actor_id', true);
        if ($security && ! array_filter(array_intersect_key($filters, array_flip(['search', 'register', 'category_id', 'type_id', 'status', 'classification', 'owner', 'physical', 'custodian', 'metric', 'revision', 'revision_state'])), fn (mixed $value): bool => $value !== null && $value !== '')) {
            $scopeIds = array_unique(array_map(fn (array $context): ?int => (new DocumentScope($context['company_id'], $context['site_id'], $context['department_id']))->authorizationScope()->id, $contexts));
            $administration = DB::table('rbac_audits as e')->whereIn('e.scope_id', $scopeIds);
            $queries[] = $this->project($administration, 'administration', 'e.action', null, null, null, 'e.actor_id', false, null, null, false);
            if (! empty($filters['include_unattributed'])) {
                abort_unless($this->roles->isSystemAdministrator($actor), 403, 'rbacForbidden');
                $requests = DB::table('storage_events as e')->whereNull('e.profile_id')->where('e.action', 'request.denied')->whereNotExists(fn (Builder $known): Builder => $known->fromSub(clone $documents, 'known')->selectRaw('1')->whereColumn('e.after->record', '=', DB::raw('CAST(known.id AS CHAR)'))->whereColumn('known.register', 'e.after->type')->whereIn('e.after->route', ['api.v1.storage.attachments.preview', 'api.v1.storage.attachments.download']));
                $queries[] = $this->project($requests, 'unattributed', 'e.action', 'e.reason', null, null, 'e.actor_id', true, null, null, false);
                $global = DB::table('document_security_audits as e')->whereNull('e.profile_id');
                $queries[] = $this->project($global, 'unattributed-security', 'e.event', 'e.reason', null, null, 'e.actor_id', false, null, 'e.operation', false);
                $globalRoles = DB::table('rbac_audits as e')->whereNotIn('e.scope_id', $scopeIds);
                $queries[] = $this->project($globalRoles, 'global-administration', 'e.action', null, null, null, 'e.actor_id', false, null, null, false);
            }
        }
        $union = array_shift($queries);
        foreach ($queries as $query) {
            $union->unionAll($query);
        }
        $events = DB::query()->fromSub($union, 'events');
        foreach (['date_from', 'date_to'] as $key) {
            if (! empty($filters[$key])) {
                $date = Carbon::parse($filters[$key], 'Asia/Vientiane')->startOfDay();
                if ($key === 'date_to') {
                    $date->addDay();
                }
                $events->where('timestamp', $key === 'date_from' ? '>=' : '<', $date->utc()->format('Y-m-d H:i:s'));
            }
        }
        if (! empty($filters['event'])) {
            $events->where('event_action', $filters['event']);
        }
        if (! empty($filters['operation'])) {
            $events->where('operation', $filters['operation']);
        }
        if (! empty($filters['outcome'])) {
            $denied = ['access.denied', 'request.denied', 'attachment.unavailable'];
            match ($filters['outcome']) {
                'denied' => $events->whereIn('event_action', $denied), 'allowed' => $events->whereIn('event_action', ['access.allowed', 'attachment.preview', 'attachment.download']), default => $events->whereNotIn('event_action', [...$denied, 'access.allowed', 'attachment.preview', 'attachment.download'])
            };
        }

        return $events->orderByDesc('timestamp')->orderBy('source')->orderByDesc('event_id');
    }

    private function project(Builder $query, string $source, string $action, ?string $reason = null, ?string $before = null, ?string $after = null, string $actor = 'e.actor_id', bool $route = false, ?string $number = null, ?string $operation = null, bool $document = true): Builder
    {
        $query->leftJoin('users as actor', 'actor.id', '=', $actor);
        if ($document) {
            $query->select(array_map(fn (string $column): string => 'd.'.$column, self::DOCUMENT_COLUMNS));
        } else {
            $query->selectRaw(implode(', ', array_map(fn (string $column): string => 'NULL as '.$column, self::DOCUMENT_COLUMNS)));
        }
        if ($source === 'controlled') {
            $query->leftJoin('controlled_revisions as event_revision', fn (JoinClause $join): JoinClause => $join->on('event_revision.id', '=', 'e.revision_id')->on('event_revision.document_id', '=', 'd.id'));
        }
        if ($source === 'storage') {
            $query->leftJoin('physical_copies as event_copy', fn (JoinClause $join): JoinClause => $join->on('event_copy.id', '=', 'e.subject_id')->on('event_copy.profile_id', '=', 'd.profile_id'));
        }
        $query->selectRaw(($source === 'controlled' ? 'event_revision.revision_number' : 'NULL').' as event_revision_number');
        $query->selectRaw(($source === 'storage' ? 'event_copy.identifier' : 'NULL').' as physical_identifier');
        $query->addSelect(['e.id as event_id', 'e.created_at as timestamp', 'actor.name as actor'])->selectRaw('? as source', [$source])->selectRaw($action.' as event_action');
        foreach (['reason' => $reason, 'before_json' => $before, 'after_json' => $after, 'issued_number' => $number, 'operation' => $operation] as $alias => $column) {
            if ($alias === 'operation' && ! $column && $route) {
                $routeColumn = DB::connection()->getQueryGrammar()->wrap('e.after->route');
                $query->selectRaw("CASE WHEN e.action = 'attachment.preview' OR {$routeColumn} = ? THEN 'preview' WHEN e.action = 'attachment.download' OR {$routeColumn} = ? THEN 'download' ELSE NULL END as operation", ['api.v1.storage.attachments.preview', 'api.v1.storage.attachments.download']);
            } else {
                $column ? $query->addSelect($column.' as '.$alias) : $query->selectRaw('NULL as '.$alias);
            }
        }
        $route ? $query->addSelect('e.after->route as event_route') : $query->selectRaw('NULL as event_route');

        return $query;
    }

    /** @return array<string, mixed> */
    public function row(object $row, string $language): array
    {
        $value = (array) $row;
        $value['revision_identifier'] = $row->event_revision_number === null ? null : ControlledRevision::formatIdentifier((int) $row->event_revision_number);
        $value['key'] = $row->source.':'.$row->event_id;
        $value['event_en'] = $this->labels->event($row->event_action, 'en');
        $value['event_lo'] = $this->labels->event($row->event_action, 'lo');
        $value['event'] = $this->labels->event($row->event_action, $language);
        $value['timestamp'] = Carbon::parse($row->timestamp, 'UTC')->toISOString();
        $value['href'] = $row->id && in_array($row->register, ['incoming', 'outgoing', 'controlled'], true) ? route('dcms.'.($row->register === 'controlled' ? 'controlled-documents' : $row->register), ['unit' => $row->site_id.':'.($row->department_id ?? 'site'), 'record' => $row->id], false) : null;
        if (! $row->id) {
            $label = str_contains($row->source, 'administration') ? 'administration' : 'unattributed';
            $value['title_en'] = $this->labels->label($label, 'en');
            $value['title_lo'] = $this->labels->label($label, 'lo');
        }
        if (str_contains($row->source, 'security') || $row->source === 'unattributed' || (($row->event_action === 'request.denied') && $row->source === 'storage')) {
            $value['reason'] = $this->labels->securityReason($row->reason, $language);
        }
        if ($row->event_action === 'request.denied') {
            $operation = match ($row->event_route) {
                'api.v1.storage.attachments.preview' => 'preview', 'api.v1.storage.attachments.download' => 'download', default => 'metadata'
            };
            $value['event_en'] = $this->labels->event('request.denied.'.$operation, 'en');
            $value['event_lo'] = $this->labels->event('request.denied.'.$operation, 'lo');
            $value['event'] = $language === 'lo' ? $value['event_lo'] : $value['event_en'];
        }
        $value['source_en'] = $this->labels->source($row->source, 'en');
        $value['source_lo'] = $this->labels->source($row->source, 'lo');
        $value['before'] = $this->safeValues($row->before_json);
        $value['after'] = $this->safeValues($row->after_json);
        $value['changes'] = [];
        foreach (array_unique([...array_keys($value['before']), ...array_keys($value['after'])]) as $field) {
            if (($value['before'][$field] ?? null) !== ($value['after'][$field] ?? null)) {
                $value['changes'][] = ['label_en' => $this->labels->field($field, 'en'), 'label_lo' => $this->labels->field($field, 'lo'), 'field' => $field, 'before' => $value['before'][$field] ?? null, 'after' => $value['after'][$field] ?? null];
            }
        }
        unset($value['before_json'], $value['after_json'], $value['profile_id'], $value['event_route'], $value['event_revision_number']);

        return $value;
    }

    /** @return array<string, mixed> */
    private function safeValues(?string $json): array
    {
        $values = $json ? json_decode($json, true) : [];
        if (! is_array($values)) {
            return [];
        }
        $values = array_merge($values, is_array($values['details'] ?? null) ? $values['details'] : [], is_array($values['delivery'] ?? null) ? $values['delivery'] : []);

        return array_filter(array_intersect_key($values, array_flip(self::SAFE_VALUES)), fn (mixed $value): bool => is_scalar($value) || $value === null);
    }
}
