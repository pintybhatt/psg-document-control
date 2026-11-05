<?php

namespace Tests\Feature;

use App\Authorization\DocumentReporting;
use App\Authorization\ReportAuditHistory;
use App\Authorization\SearchDocuments;
use App\Models\ControlledDocument;
use App\Models\ControlledRevision;
use App\Models\DocumentSecurityProfile;
use App\Models\DocumentType;
use App\Models\OutgoingCorrespondence;
use App\Models\PhysicalCopy;
use App\Models\RegisterConfiguration;
use App\Models\Site;
use App\Models\StorageLocation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithDocumentStorage;
use Tests\Concerns\InteractsWithRbac;
use Tests\TestCase;

class DocumentSearchPerformanceTest extends TestCase
{
    use InteractsWithDocumentStorage, InteractsWithRbac, LazilyRefreshDatabase;

    public function test_representative_scoped_queries_have_accurate_counts_and_bounded_pages(): void
    {
        [$incoming, $actor] = $this->fixture();
        $this->freezeTime();
        $scope = $incoming->profile->documentScope();
        $site = Site::findOrFail($scope->siteId);
        $type = DocumentType::findOrFail($incoming->type_id);
        $prototypes = ['incoming' => $incoming];
        foreach (['outgoing', 'controlled'] as $key) {
            $register = RegisterConfiguration::factory()->create(['scope_id' => $incoming->scope_id, 'register_key' => $key]);
            $type->registers()->attach($register->id, ['scope_id' => $incoming->scope_id]);
            $profile = DocumentSecurityProfile::factory()->atSite($site)->create(['resource_type' => $key, 'created_by' => $actor->id, 'updated_by' => $actor->id]);
            $common = ['scope_id' => $incoming->scope_id, 'register_id' => $register->id, 'type_id' => $type->id, 'profile_id' => $profile->id, 'created_by' => $actor->id, 'updated_by' => $actor->id, 'status' => 'draft'];
            $prototypes[$key] = $key === 'controlled' ? ControlledDocument::factory()->create($common) : OutgoingCorrespondence::create($common + ['subject' => 'Synthetic outgoing', 'document_date' => '2026-10-26', 'signatory_name' => 'Example', 'signatory_title' => 'Officer', 'priority' => 'normal']);
        }
        $revision = ControlledRevision::factory()->create(['document_id' => $prototypes['controlled']->id, 'owner_id' => $actor->id, 'custodian_id' => $actor->id, 'created_by' => $actor->id, 'updated_by' => $actor->id]);
        $location = StorageLocation::create(['company_id' => $scope->companyId, 'site_id' => $scope->siteId, 'room' => 'Benchmark archive', 'cabinet' => 'Cabinet A', 'shelf' => 'Shelf B', 'box' => 'Box C', 'path_hash' => hash('sha256', 'benchmark'), 'created_by' => $actor->id]);
        foreach ($prototypes as $key => $prototype) {
            for ($i = 1; $i <= 1000; $i++) {
                $profile = DocumentSecurityProfile::factory()->atSite($site)->make(['resource_type' => $key, 'restricted' => $i % 4 === 0, 'created_by' => $actor->id, 'updated_by' => $actor->id]);
                $profileId = DB::table('document_security_profiles')->insertGetId($profile->getAttributes() + ['created_at' => now(), 'updated_at' => now()]);
                $attributes = $prototype->getAttributes();
                unset($attributes['id']);
                $attributes['profile_id'] = $profileId;
                $attributes['created_at'] = now()->subDays($i % 30);
                if ($key === 'controlled') {
                    $attributes['document_code'] = 'BENCH/SOP/'.str_pad((string) $i, 6, '0', STR_PAD_LEFT);
                } else {
                    $attributes['subject'] = 'Synthetic benchmark ທົດສອບ '.$i;
                }
                $id = DB::table($prototype->getTable())->insertGetId($attributes);
                DB::table('storage_events')->insert(['profile_id' => $profileId, 'actor_id' => $actor->id, 'action' => 'attachment.download', 'created_at' => now(), 'updated_at' => now()]);
                $revisionId = null;
                if ($key === 'controlled') {
                    $attributes = $revision->getAttributes();
                    unset($attributes['id']);
                    $attributes['document_id'] = $id;
                    $attributes['revision_reason'] = 'Synthetic benchmark revision';
                    $revisionId = DB::table('controlled_revisions')->insertGetId($attributes);
                }
                if ($i % 10 === 1) {
                    PhysicalCopy::create(['id' => (string) Str::uuid(), 'profile_id' => $profileId, 'revision_id' => $revisionId, 'site_id' => $scope->siteId, 'identifier' => 'BENCH/'.$key.'/'.$i, 'kind' => 'original', 'location_id' => $location->id, 'status' => 'stored', 'lock_version' => 1]);
                }
            }
        }
        $search = app(SearchDocuments::class);
        $measurements = [];
        $scenarios = [
            'cross_register' => [[], 2253],
            'combined_filters' => [['type_id' => $type->id, 'category_id' => $type->category_id, 'classification' => 'normal', 'date_from' => '2026-10-26', 'date_to' => '2026-10-26'], 2253],
            'number_sort' => [['sort' => 'number'], 2253],
            'date_sort' => [['sort' => 'date'], 2253],
            'oldest_sort' => [['sort' => 'oldest'], 2253],
            'revision_reason' => [['register' => 'controlled', 'search' => 'benchmark revision', 'revision' => '00', 'revision_state' => 'draft'], 750],
            'lao_text' => [['search' => 'ທົດສອບ'], 2251],
            'physical_location' => [['physical' => 'Box C', 'copy_kind' => 'original', 'custody_status' => 'stored'], 300],
        ];
        foreach ($scenarios as $name => [$filters, $expected]) {
            $times = [];
            $sqlTimes = [];
            for ($run = 0; $run < 5; $run++) {
                DB::enableQueryLog();
                DB::flushQueryLog();
                $start = hrtime(true);
                $page = $search->query($actor, $scope, $filters)->paginate(25, ['*'], 'page', 2);
                $times[] = (hrtime(true) - $start) / 1e6;
                $log = DB::getQueryLog();
                $sqlTimes[] = array_sum(array_column($log, 'time'));
                DB::disableQueryLog();
                if ($name === 'revision_reason' && $run === 0 && DB::getDriverName() === 'mysql') {
                    usort($log, fn (array $first, array $second): int => $second['time'] <=> $first['time']);
                    $plans = [];
                    foreach (array_slice($log, 0, 2) as $logged) {
                        $plans[] = ['query' => $logged['query'], 'plan' => DB::select('EXPLAIN FORMAT=JSON '.$logged['query'], $logged['bindings'])];
                    }
                    file_put_contents('/tmp/dcms-phase8-revision-plans.json', json_encode($plans, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
                }
                $this->assertSame($expected, $page->total());
                $this->assertCount(25, $page->items());
            }
            sort($times);
            sort($sqlTimes);
            $measurements[$name] = ['total' => $expected, 'median_ms' => round($times[2], 3), 'max_ms' => round(max($times), 3), 'median_sql_ms' => round($sqlTimes[2], 3)];
        }
        $start = hrtime(true);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->getJson('/api/v1/document-search?'.http_build_query(['company_id' => $scope->companyId, 'site_id' => $scope->siteId, 'per_page' => 10]))->assertOk()->assertJsonPath('meta.total', 2253)->assertJsonCount(10, 'data');
        $measurements['http_page'] = ['ms' => round((hrtime(true) - $start) / 1e6, 3), 'queries' => count(DB::getQueryLog())];
        DB::disableQueryLog();
        $this->grant($actor, $scope->authorizationScope(), ['reports.view', 'documents.audit.view', 'security.audit.view']);
        $reports = app(DocumentReporting::class);
        $filters = ['company_id' => $scope->companyId, 'site_id' => $scope->siteId];
        $contexts = $reports->contexts($actor, $filters);
        $documents = $reports->documents($actor, $filters, $contexts);
        $reportMeasurements = [];
        $scenarios = [
            'dashboard_totals' => fn () => $reports->totals(clone $documents, []),
            'dashboard_trends' => fn () => $reports->trends(clone $documents),
            'document_page' => fn () => (clone $documents)->orderBy('register')->orderBy('id')->paginate(25, ['*'], 'page', 2),
            'filtered_page' => fn () => $reports->documents($actor, $filters + ['type_id' => $type->id, 'classification' => 'normal'], $contexts)->orderBy('register')->orderBy('id')->paginate(25, ['*'], 'page', 2),
            'revision_page' => fn () => $reports->revisions(clone $documents, [])->orderBy('id')->orderBy('revision_number')->paginate(25, ['*'], 'page', 2),
            'physical_page' => fn () => $reports->physical(clone $documents, [])->orderBy('copy_id')->paginate(25, ['*'], 'page', 2),
            'audit_page' => fn () => app(ReportAuditHistory::class)->query($actor, clone $documents, ['view' => 'audit'], $contexts)->paginate(25),
            'security_page' => fn () => app(ReportAuditHistory::class)->query($actor, clone $documents, ['view' => 'security'], $contexts)->paginate(25),
        ];
        foreach ($scenarios as $name => $execute) {
            $times = [];
            for ($run = 0; $run < 3; $run++) {
                $start = hrtime(true);
                $result = $execute();
                $times[] = (hrtime(true) - $start) / 1e6;
                if ($name === 'dashboard_totals') {
                    $this->assertSame(2253, $result['total']);
                    $this->assertSame(300, $result['physical_copies']);
                } elseif (in_array($name, ['audit_page', 'security_page'], true)) {
                    $this->assertGreaterThanOrEqual(2250, $result->total());
                    $this->assertCount(25, $result->items());
                } elseif (in_array($name, ['document_page', 'filtered_page', 'revision_page', 'physical_page'], true)) {
                    $this->assertSame(match ($name) {
                        'revision_page' => 751, 'physical_page' => 300, default => 2253
                    }, $result->total());
                    $this->assertCount(25, $result->items());
                }
            }
            sort($times);
            $reportMeasurements[$name] = ['median_ms' => round($times[1], 3), 'max_ms' => round(max($times), 3)];
        }
        foreach (['documents' => 2253, 'physical' => 300, 'revisions' => 751] as $view => $expected) {
            foreach (['legacy' => [], 'selected' => ['trend_group' => 'category_en']] as $mode => $trendFilters) {
                DB::flushQueryLog();
                DB::enableQueryLog();
                $start = hrtime(true);
                try {
                    $this->getJson('/api/v1/reports?'.http_build_query($filters + ['view' => $view, 'per_page' => 10] + $trendFilters))
                        ->assertOk()->assertJsonPath('meta.total', $expected)->assertJsonCount(10, 'data');
                    $log = DB::getQueryLog();
                    $reportMeasurements['http_'.$view.($mode === 'selected' ? '_selected' : '')] = ['ms' => round((hrtime(true) - $start) / 1e6, 3), 'queries' => count($log), 'sql_ms' => round(array_sum(array_column($log, 'time')), 3)];
                } finally {
                    DB::disableQueryLog();
                }
                if (DB::getDriverName() === 'mysql' && $view === 'physical' && $mode === 'legacy') {
                    usort($log, fn (array $first, array $second): int => $second['time'] <=> $first['time']);
                    $plans = [];
                    foreach (array_slice($log, 0, 4) as $logged) {
                        $plans[] = ['query' => $logged['query'], 'ms' => $logged['time'], 'plan' => DB::select('EXPLAIN FORMAT=JSON '.$logged['query'], $logged['bindings'])];
                    }
                    file_put_contents('/tmp/dcms-reports-plans.json', json_encode($plans, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
                }
            }
        }
        $inventory = $reports->physical(clone $documents, []);
        $inventoryPlan = DB::select((DB::getDriverName() === 'mysql' ? 'EXPLAIN ' : 'EXPLAIN QUERY PLAN ').$inventory->toSql(), $inventory->getBindings());
        file_put_contents('/tmp/dcms-phase9-performance-'.DB::getDriverName().'.json', json_encode(['documents' => 3003, 'restricted' => 750, 'revisions' => 1001, 'physical_copies' => 300, 'file_events' => 3000, 'runs' => 3, 'measurements' => $reportMeasurements, 'inventory_plan' => $inventoryPlan], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        $query = $search->query($actor, $scope, ['physical' => 'Box C']);
        $plans = DB::select((DB::getDriverName() === 'mysql' ? 'EXPLAIN ' : 'EXPLAIN QUERY PLAN ').$query->toSql(), $query->getBindings());
        file_put_contents('/tmp/dcms-phase8-performance-'.DB::getDriverName().'.json', json_encode(['documents' => 3003, 'restricted' => 750, 'revisions' => 1001, 'physical_copies' => 300, 'runs' => 5, 'measurements' => $measurements, 'physical_query_plan' => $plans], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }
}
