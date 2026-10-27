<?php

namespace App\Http\Controllers;

use App\Authorization\AccessControl;
use App\Authorization\DocumentReporting;
use App\Authorization\DocumentScope;
use App\Authorization\ReportAuditHistory;
use App\Authorization\SearchDocuments;
use App\Http\Requests\ManagementReportRequest;
use App\ManagementReport;
use App\Models\DocumentType;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ManagementReportController extends Controller
{
    public function __construct(private ReportAuditHistory $audits, private AccessControl $roles) {}

    private function permission(array $filters): string
    {
        return match ($filters['view'] ?? 'documents') {
            'audit' => 'documents.audit.view', 'security' => 'security.audit.view', default => 'reports.view'
        };
    }

    private function documentFilters(array $filters): array
    {
        return in_array($filters['view'] ?? '', ['audit', 'security'], true) ? array_diff_key($filters, ['date_from' => true, 'date_to' => true]) : $filters;
    }

    private function formatRow(object $row, array $filters, DocumentReporting $reports, string $language): array
    {
        return in_array($filters['view'] ?? '', ['audit', 'security'], true) ? $this->audits->row($row, $language) : $reports->row($row);
    }

    public function index(ManagementReportRequest $request, DocumentReporting $reports): JsonResponse
    {
        $filters = $request->validated();
        $contexts = $reports->contexts($request->user(), $filters, $this->permission($filters));
        $documents = $reports->metric($reports->documents($request->user(), $this->documentFilters($filters), $contexts), $filters['metric'] ?? '');
        $query = $this->query($documents, $filters, $reports, $request->user(), $contexts);
        $page = $query->paginate($request->integer('per_page', 10));
        $language = $request->input('language', $request->user()->preferred_language);
        $rows = $page->getCollection()->map(fn (object $row): array => $this->formatRow($row, $filters, $reports, $language));
        $reports->audit($request->user(), $contexts, $page->getCollection()->pluck('profile_id')->unique()->values()->all());
        if (in_array($filters['view'] ?? '', ['audit', 'security'], true)) {
            return response()->json(['data' => $rows, 'meta' => ['total' => $page->total(), 'current_page' => $page->currentPage(), 'per_page' => $page->perPage()], 'summary' => ['events' => $page->total()], 'trends' => null,
                'scope' => $contexts, 'filters' => $filters, 'capabilities' => ['include_unattributed' => $this->roles->isSystemAdministrator($request->user())]], headers: ['Cache-Control' => 'private, no-store']);
        }
        $revisionTotals = DB::query()->fromSub($reports->revisions(clone $documents, $filters), 'revision_totals')
            ->selectRaw("COUNT(*) as revisions, SUM(CASE WHEN revision_status = 'draft' THEN 1 ELSE 0 END) as draft_revisions, SUM(CASE WHEN revision_status = 'superseded' THEN 1 ELSE 0 END) as superseded_revisions")->first();

        return response()->json(['data' => $rows, 'meta' => ['total' => $page->total(), 'current_page' => $page->currentPage(), 'per_page' => $page->perPage()],
            'summary' => $reports->totals(clone $documents, $filters) + array_map(intval(...), (array) $revisionTotals), 'trends' => $reports->trends(clone $documents),
            'scope' => $contexts, 'filters' => $filters], headers: ['Cache-Control' => 'private, no-store']);
    }

    public function lookups(ManagementReportRequest $request, DocumentReporting $reports, ManagementReport $labels): JsonResponse
    {
        $contexts = $reports->contexts($request->user(), $request->validated(), $this->permission($request->validated()));
        $scopes = array_unique(array_map(fn (array $context): ?int => (new DocumentScope($context['company_id'], $context['site_id'], $context['department_id']))->authorizationScope()->id, $contexts));
        $types = DocumentType::query()->whereIn('scope_id', $scopes)->with(['category', 'registers'])->orderBy('name_en')->orderBy('id')->get();

        return response()->json(['events' => array_map(fn (string $event): array => ['value' => $event, 'name_en' => $labels->event($event, 'en'), 'name_lo' => $labels->event($event, 'lo')], ($request->input('view') === 'security' ? ['access.allowed', 'access.denied', 'attachment.preview', 'attachment.download', 'attachment.unavailable', 'request.denied', 'role.created', 'role.updated', 'assignment.granted', 'assignment.revoked', 'clearance.granted', 'clearance.revoked', 'grant.granted', 'grant.revoked'] : ['created', 'updated', 'registered', 'number.issued', 'correct_details', 'update', 'dispatch', 'correct_dispatch', 'delivery', 'close', 'reopen', 'revision_prepared', 'released', 'obsolete', 'superseded', 'archived', 'attachment.uploaded', 'attachment.replaced', 'attachment.preview', 'attachment.download', 'custody.checkout', 'custody.return', 'custody.transfer', 'custody.correct', 'custody.inventory'])), 'data' => $types->map(fn (DocumentType $type): array => $type->only(['id', 'category_id', 'name_en', 'name_lo']) + ['category' => $type->category->only(['id', 'name_en', 'name_lo']), 'registers' => $type->registers->pluck('register_key')])], headers: ['Cache-Control' => 'private, no-store']);
    }

    public function export(ManagementReportRequest $request, DocumentReporting $reports, ManagementReport $renderer, SearchDocuments $search): Response
    {
        $filters = $request->validated();
        $contexts = $reports->contexts($request->user(), $filters, $this->permission($filters));
        $documents = $reports->metric($reports->documents($request->user(), $this->documentFilters($filters), $contexts), $filters['metric'] ?? '');
        $query = $this->query($documents, $filters, $reports, $request->user(), $contexts);
        if ((clone $query)->count() > 5000) {
            throw ValidationException::withMessages(['report' => 'reportTooLarge']);
        }
        $records = $query->get();
        foreach ($contexts as $context) {
            $ids = $records->filter(fn (object $row): bool => (int) $row->site_id === $context['site_id'] && $row->department_id === $context['department_id'])->pluck('profile_id')->unique()->values()->all();
            foreach (array_chunk($ids, 100) as $chunk) {
                $search->auditResults($request->user(), new DocumentScope($context['company_id'], $context['site_id'], $context['department_id']), $chunk);
            }
        }
        $reports->audit($request->user(), $contexts, $records->pluck('profile_id')->unique()->values()->all(), 'report.export.'.$request->input('format', 'xlsx'));

        return $renderer->render($request, $records->map(fn (object $row): array => $this->formatRow($row, $filters, $reports, $request->input('language', $request->user()->preferred_language)))->all(), $filters, $contexts);
    }

    /** @param array<string, mixed> $filters */
    private function query(Builder $documents, array $filters, DocumentReporting $reports, User $actor, array $contexts): Builder
    {
        if (in_array($filters['view'] ?? '', ['audit', 'security'], true)) {
            return $this->audits->query($actor, $documents, $filters, $contexts);
        }
        $query = match ($filters['view'] ?? 'documents') {
            'physical' => DB::query()->fromSub($reports->physical($documents, $filters), 'physical'),
            'revisions' => $reports->revisions($documents, $filters),
            default => $documents,
        };
        match ($filters['sort'] ?? 'recent') {
            'number' => $query->orderBy('number'), 'date' => $query->orderByDesc('document_date'), 'oldest' => $query->orderBy('created_at'), default => $query->orderByDesc('created_at'),
        };
        $query->orderBy('register')->orderBy('id');
        if (($filters['view'] ?? '') === 'physical') {
            $query->orderBy('copy_id');
        }
        if (($filters['view'] ?? '') === 'revisions') {
            $query->orderBy('revision_number');
        }

        return $query;
    }
}
