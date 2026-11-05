<?php

namespace Tests\Feature;

use App\Authorization\CorrespondenceContexts;
use App\Authorization\DocumentReporting;
use App\Models\Department;
use App\Models\Role;
use App\Models\Site;
use App\Models\UserRoleAssignment;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithDocumentStorage;
use Tests\Concerns\InteractsWithRbac;
use Tests\TestCase;

class ReportingPerformanceTest extends TestCase
{
    use InteractsWithDocumentStorage, InteractsWithRbac, LazilyRefreshDatabase;

    public function test_reporting_work_does_not_multiply_registers_by_department(): void
    {
        [$record, $actor] = $this->fixture();
        $role = Role::query()->where('code', Role::SYSTEM_ADMINISTRATOR_CODE)->sole();
        UserRoleAssignment::factory()->create(['user_id' => $actor->id, 'role_id' => $role->id, 'scope_id' => $role->scope_id]);
        Department::factory()->count(19)->for(Site::findOrFail($record->profile->site_id))->create();
        Site::factory()->create();
        $reports = app(DocumentReporting::class);
        $measure = function (callable $operation): array {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $start = hrtime(true);
            try {
                $value = $operation();
                $queries = DB::getQueryLog();

                return [$value, ['queries' => count($queries), 'sql_bytes' => array_sum(array_map(fn (array $query): int => strlen($query['query']), $queries)), 'wall_ms' => round((hrtime(true) - $start) / 1e6, 2)]];
            } finally {
                DB::disableQueryLog();
            }
        };
        [$contexts, $contextMetrics] = $measure(fn (): array => app(CorrespondenceContexts::class)->forUser($actor));
        $siteContexts = array_values(array_filter($contexts, fn (array $context): bool => $context['site_id'] === $record->profile->site_id));
        [$documents, $constructionMetrics] = $measure(fn () => $reports->documents($actor, [], $siteContexts));
        [$totals, $totalMetrics] = $measure(fn (): array => $reports->totals(clone $documents));
        [$trends, $trendMetrics] = $measure(fn (): array => $reports->trends(clone $documents));
        [$dashboardTrends, $dashboardTrendMetrics] = $measure(fn (): array => $reports->trends(clone $documents, ['monthly', 'category_en', 'department_en']));
        $metrics = ['contexts' => $contextMetrics, 'construction' => $constructionMetrics + ['sql_bytes_generated' => strlen($documents->toSql()), 'register_branches' => substr_count(strtolower($documents->toSql()), 'union all') + 1], 'totals' => $totalMetrics, 'trends' => $trendMetrics, 'dashboard_trends' => $dashboardTrendMetrics];
        if (getenv('DCMS_REPORTING_BENCHMARK') === '1') {
            fwrite(STDOUT, json_encode($metrics, JSON_THROW_ON_ERROR).PHP_EOL);
        }

        $this->assertCount(21, $contexts);
        $this->assertCount(20, $siteContexts);
        $this->assertSame(1, $totals['total']);
        $this->assertSame(1, (int) $trends['register'][0]->total);
        $this->assertLessThanOrEqual(25, $contextMetrics['queries']);
        $this->assertLessThanOrEqual(25, $constructionMetrics['queries']);
        $this->assertSame(3, $metrics['construction']['register_branches']);
        $this->assertLessThan(15000, $metrics['construction']['sql_bytes_generated']);
        $this->assertSame(2, $totalMetrics['queries']);
        $this->assertSame(9, $trendMetrics['queries']);
        $this->assertSame(3, $dashboardTrendMetrics['queries']);
        $this->assertSame(['category_en', 'department_en', 'monthly'], array_keys($dashboardTrends));
        foreach ($dashboardTrends as $group => $rows) {
            $this->assertSame($trends[$group]->map(fn (object $row): array => (array) $row)->all(), $rows->map(fn (object $row): array => (array) $row)->all());
        }
    }

    public function test_selected_trends_keep_group_limits_null_departments_and_month_ordering(): void
    {
        $rows = null;
        for ($index = 0; $index < 40; $index++) {
            $row = DB::query()->selectRaw('? as register, ? as category_id, ? as category_en, ? as category_lo, NULL as department_id, NULL as department_en, NULL as department_lo, ? as document_date',
                ['incoming', $index + 1, 'Category '.($index + 1), 'Lao '.($index + 1), sprintf('%04d-%02d-01', 2023 + intdiv($index, 12), $index % 12 + 1)]);
            $rows = $rows === null ? $row : $rows->unionAll($row);
        }
        $rows->unionAll(DB::query()->selectRaw('? as register, ? as category_id, ? as category_en, ? as category_lo, NULL as department_id, NULL as department_en, NULL as department_lo, NULL as document_date',
            ['incoming', 40, 'Category 40', 'Lao 40']));
        $documents = DB::query()->fromSub($rows, 'documents');

        $trends = app(DocumentReporting::class)->trends($documents, ['category_en', 'department_en', 'monthly']);

        $this->assertCount(20, $trends['category_en']);
        $this->assertSame([40, ...range(1, 19)], $trends['category_en']->map(fn (object $row): int => (int) $row->category_id)->all());
        $this->assertSame(2, (int) $trends['category_en'][0]->total);
        $this->assertCount(1, $trends['department_en']);
        $this->assertNull($trends['department_en'][0]->department_id);
        $this->assertSame(41, (int) $trends['department_en'][0]->total);
        $this->assertCount(36, $trends['monthly']);
        $this->assertSame('2026-04', $trends['monthly'][0]->month);
        $this->assertSame('2023-05', $trends['monthly'][35]->month);
        $this->assertSame([], app(DocumentReporting::class)->trends($documents, []));
    }
}
