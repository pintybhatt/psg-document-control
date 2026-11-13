<?php

namespace Tests\Feature;

use App\Authorization\DeveloperMasterData;
use App\Authorization\ManageAttachments;
use App\Models\AuthorizationScope;
use App\Models\Company;
use App\Models\DocumentType;
use App\Models\IssuedDocumentNumber;
use App\Models\NumberingSeries;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Tests\Concerns\InteractsWithDocumentStorage;
use Tests\Concerns\InteractsWithRbac;
use Tests\TestCase;

class DeveloperMasterDataTest extends TestCase
{
    use InteractsWithDocumentStorage, InteractsWithRbac, LazilyRefreshDatabase;

    /** @return array<string, mixed> */
    private function package(): array
    {
        return ['schema_version' => 1,
            'company' => ['code' => 'XPPL', 'name_en' => 'Example XPPL', 'name_lo' => 'ບໍລິສັດ', 'active' => true],
            'sites' => [['code' => 'HQ', 'name_en' => 'Headquarters', 'name_lo' => 'ສຳນັກງານ', 'active' => true]],
            'departments' => [['site_code' => 'HQ', 'code' => 'ENG', 'name_en' => 'Engineering', 'name_lo' => 'ວິສະວະກຳ', 'active' => true]],
            'scopes' => ['company', 'site:HQ'],
            'categories' => [['scope' => 'site:HQ', 'code' => 'TECH', 'name_en' => 'Technical', 'name_lo' => 'ເຕັກນິກ', 'description_en' => null, 'description_lo' => null, 'active' => true]],
            'registers' => [['scope' => 'site:HQ', 'description_en' => null, 'description_lo' => null, 'active' => true, 'register_key' => 'controlled']],
            'types' => [['scope' => 'site:HQ', 'code' => 'SOP', 'name_en' => 'Procedure', 'name_lo' => 'ລະບຽບ', 'description_en' => null, 'description_lo' => null, 'active' => true, 'review_enabled' => false, 'review_interval_months' => null, 'review_baseline' => null, 'category_code' => 'TECH', 'register_keys' => ['controlled']]],
            'numbering' => [['scope' => 'site:HQ', 'register_key' => 'controlled', 'purpose' => 'internal_register', 'reset_policy' => 'continuous', 'prefix' => 'XPPL/DEV', 'padding' => 4, 'format' => ['pattern' => '{prefix}/{sequence}'], 'active' => true]],
            'policies' => ['controlled']];
    }

    private function master(): DeveloperMasterData
    {
        return app(DeveloperMasterData::class);
    }

    public function test_migrated_database_requires_an_existing_local_actor_and_never_creates_users(): void
    {
        $plan = $this->master()->import($this->package(), null, true);

        $this->assertContains('conflict', array_column($plan, 'action'));
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('companies', 0);
        try {
            $this->master()->import($this->package(), null, false);
            $this->fail('Missing actor must prevent import.');
        } catch (RuntimeException) {
            $this->assertDatabaseCount('companies', 0);
        }
    }

    public function test_empty_master_database_imports_relationships_and_reruns_without_new_rows_or_audits(): void
    {
        $actor = User::factory()->create();
        $data = $this->package();

        $plan = $this->master()->import($data, $actor, false);

        $this->assertSame(10, count($plan));
        $this->assertSame(['create'], array_values(array_unique(array_column($plan, 'action'))));
        $this->assertDatabaseCount('companies', 1);
        $this->assertDatabaseCount('authorization_scopes', 2);
        $this->assertDatabaseCount('document_type_register', 1);
        $this->assertDatabaseCount('master_data_audits', 3);
        $type = DocumentType::query()->sole();
        $this->assertSame('TECH', $type->category->code);
        $this->assertSame('controlled', $type->registers->sole()->register_key->value);
        $this->assertSame('skip', collect($this->master()->import($data, null, false))->pluck('action')->unique()->sole());
        $this->assertDatabaseCount('master_data_audits', 3);
        $this->assertDatabaseCount('numbering_configurations', 1);
        $this->assertDatabaseCount('numbering_counters', 0);
        $this->assertDatabaseCount('xppl_numbering_counters', 0);
        $this->assertDatabaseCount('xppl_numbering_namespaces', 0);
        $this->assertDatabaseCount('issued_document_numbers', 0);
    }

    public function test_dry_run_executes_no_insert_update_delete_or_scope_persistence(): void
    {
        $actor = User::factory()->create();
        $writes = [];
        DB::listen(function (QueryExecuted $event) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete|replace|create|drop|alter)/i', $event->sql)) {
                $writes[] = $event->sql;
            }
        });

        $plan = $this->master()->import($this->package(), $actor, true);

        $this->assertSame([], $writes);
        $this->assertSame(['create'], array_values(array_unique(array_column($plan, 'action'))));
        $this->assertDatabaseCount('authorization_scopes', 0);
    }

    public function test_partial_configuration_with_different_ids_reuses_business_keys_and_creates_missing_scopes(): void
    {
        $actor = User::factory()->create();
        $data = $this->package();
        $other = Company::factory()->create(['id' => 40]);
        $company = Company::factory()->create($data['company'] + ['id' => 80]);
        $site = Site::factory()->create($data['sites'][0] + ['id' => 90, 'company_id' => $company->id]);
        $before = $company->fresh()->getAttributes();

        $plan = $this->master()->import($data, $actor, false);

        $this->assertSame(['skip', 'skip'], array_slice(array_column($plan, 'action'), 0, 2));
        $this->assertSame($before, $company->fresh()->getAttributes());
        $this->assertDatabaseHas('departments', ['company_id' => 80, 'site_id' => 90, 'code' => 'ENG']);
        $this->assertSame(80, NumberingSeries::query()->sole()->scope->company_id);
        $this->assertModelExists($other);
        $this->assertSame(2, AuthorizationScope::query()->count());
    }

    #[TestWith(['company'])]
    #[TestWith(['category'])]
    #[TestWith(['numbering'])]
    #[TestWith(['mapping'])]
    public function test_conflicts_are_reported_and_nothing_is_overwritten_or_partially_created(string $kind): void
    {
        $actor = User::factory()->create();
        $data = $this->package();
        $this->master()->import($data, $actor, false);
        if ($kind === 'company') {
            $data['company']['name_en'] = 'Changed';
        } elseif ($kind === 'category') {
            $data['categories'][0]['name_en'] = 'Changed';
        } elseif ($kind === 'numbering') {
            $data['numbering'][0]['padding'] = 6;
        } else {
            $data['types'][0]['register_keys'] = [];
        }
        $data['departments'][] = ['site_code' => 'HQ', 'code' => 'NEW', 'name_en' => 'New', 'name_lo' => 'ໃໝ່', 'active' => true];
        $before = $this->master()->export('XPPL');

        $plan = $this->master()->import($data, $actor, true);
        $this->assertContains('conflict', array_column($plan, 'action'));
        try {
            $this->master()->import($data, $actor, false);
            $this->fail('Conflict must prevent import.');
        } catch (RuntimeException) {
            $this->assertSame($before, $this->master()->export('XPPL'));
            $this->assertDatabaseCount('departments', 1);
        }
    }

    #[TestWith(['category'])]
    #[TestWith(['register'])]
    #[TestWith(['site'])]
    #[TestWith(['scope'])]
    #[TestWith(['duplicate'])]
    #[TestWith(['operational'])]
    #[TestWith(['format'])]
    #[TestWith(['annual_format'])]
    #[TestWith(['review_policy'])]
    #[TestWith(['duplicate_prefix'])]
    public function test_complete_input_rejects_invalid_references_duplicate_keys_and_numbering_formats(string $kind): void
    {
        $actor = User::factory()->create();
        $data = $this->package();
        match ($kind) {
            'category' => $data['types'][0]['category_code'] = 'MISSING',
            'register' => $data['numbering'][0]['register_key'] = 'outgoing',
            'site' => $data['departments'][0]['site_code'] = 'MISSING',
            'scope' => $data['scopes'] = [],
            'duplicate' => $data['types'][] = $data['types'][0],
            'operational' => $data['numbering'][0]['counter'] = 100,
            'format' => $data['numbering'][0]['format'] = ['pattern' => '{unknown}/{sequence}'],
            'annual_format' => $data['numbering'][0]['reset_policy'] = 'annual',
            'review_policy' => $data['types'][0]['review_enabled'] = true,
            'duplicate_prefix' => $data['numbering'][] = $data['numbering'][0],
        };

        try {
            $this->master()->import($data, $actor, false);
            $this->fail('Invalid input must prevent writes.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('companies', 0);
        }
    }

    public function test_unexpected_failure_rolls_back_every_master_and_audit(): void
    {
        $actor = User::factory()->create();
        $events = DocumentType::getEventDispatcher();
        DocumentType::setEventDispatcher(clone $events);
        DocumentType::creating(fn () => throw new RuntimeException('Synthetic failure'));

        try {
            $this->master()->import($this->package(), $actor, false);
            $this->fail('Creation failure must roll back.');
        } catch (RuntimeException $failure) {
            $this->assertSame('Synthetic failure', $failure->getMessage());
            foreach (['companies', 'sites', 'departments', 'authorization_scopes', 'document_categories', 'register_configurations', 'master_data_audits', 'numbering_series'] as $table) {
                $this->assertDatabaseCount($table, 0);
            }
        } finally {
            DocumentType::setEventDispatcher($events);
        }
    }

    public function test_operational_documents_files_and_existing_numbering_state_are_untouched(): void
    {
        [$record, $actor] = $this->fixture();
        $recordBefore = $record->fresh()->getAttributes();
        $attachment = app(ManageAttachments::class)->upload($actor, $record->profile->documentScope(), 'incoming', (string) $record->id, null, $this->pdf(), 'Synthetic import preservation');
        $filesBefore = Storage::disk('documents')->allFiles();
        $bytesBefore = array_map(fn (string $path): string => Storage::disk('documents')->get($path), $filesBefore);
        $this->master()->import($this->package(), $actor, false);
        $series = NumberingSeries::query()->sole();
        DB::table('numbering_counters')->insert(['series_id' => $series->id, 'period' => 0, 'value' => 0]);
        DB::table('numbering_counters')->where('series_id', $series->id)->update(['value' => 1]);
        IssuedDocumentNumber::query()->create(['series_id' => $series->id, 'configuration_id' => $series->currentConfiguration->id,
            'period' => 0, 'year' => 2026, 'sequence' => 1, 'number' => 'XPPL/DEV/0001', 'issued_by' => $actor->id,
            'record_type' => 'synthetic-local-history', 'record_key' => '1']);
        $counterBefore = DB::table('numbering_counters')->get()->toArray();
        $auditBefore = DB::table('master_data_audits')->count();
        $tables = ['users', 'roles', 'user_role_assignments', 'incoming_correspondences', 'document_attachments', 'attachment_versions', 'numbering_counters', 'issued_document_numbers', 'xppl_numbering_namespaces', 'xppl_numbering_mappings', 'xppl_numbering_counters', 'xppl_numbering_allocations'];
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = json_encode(DB::table($table)->get());
        }

        $this->master()->import($this->package(), null, false);

        $this->assertSame($recordBefore, $record->fresh()->getAttributes());
        $this->assertSame(json_encode($counterBefore), json_encode(DB::table('numbering_counters')->get()->toArray()));
        $this->assertSame($auditBefore, DB::table('master_data_audits')->count());
        foreach ($tables as $table) {
            $this->assertSame($before[$table], json_encode(DB::table($table)->get()), $table);
        }
        $this->assertSame($filesBefore, Storage::disk('documents')->allFiles());
        $this->assertSame($bytesBefore, array_map(fn (string $path): string => Storage::disk('documents')->get($path), $filesBefore));
    }

    public function test_prefix_reserved_by_an_unrelated_series_blocks_import_without_changing_it(): void
    {
        $actor = User::factory()->create();
        $foreign = NumberingSeries::factory()->create();
        DB::table('numbering_namespaces')->insert(['prefix' => 'XPPL/DEV', 'series_id' => $foreign->id]);
        $before = $foreign->fresh()->getAttributes();

        $plan = $this->master()->import($this->package(), $actor, true);

        $this->assertSame('conflict', collect($plan)->firstWhere('kind', 'numbering')['action']);
        try {
            $this->master()->import($this->package(), $actor, false);
            $this->fail('Reserved prefix must prevent import.');
        } catch (RuntimeException) {
            $this->assertDatabaseMissing('companies', ['code' => 'XPPL']);
            $this->assertSame($before, $foreign->fresh()->getAttributes());
            $this->assertDatabaseHas('numbering_namespaces', ['prefix' => 'XPPL/DEV', 'series_id' => $foreign->id]);
        }
    }

    public function test_export_uses_business_references_and_omits_sensitive_and_operational_fields(): void
    {
        $actor = User::factory()->create();
        $this->master()->import($this->package(), $actor, false);

        $data = $this->master()->export('XPPL');

        $this->assertSame($this->package(), $data);
        $this->assertSame(1, User::query()->count());
        $this->assertStringNotContainsString('created_by', json_encode($data));
        $this->assertStringNotContainsString('counter', json_encode($data));
    }

    public function test_distributed_dev_snapshot_imports_into_a_fresh_database_without_operational_numbering_state(): void
    {
        $actor = User::factory()->create();
        $data = json_decode(file_get_contents(database_path('master-data/xppl-master.json')), true, flags: JSON_THROW_ON_ERROR);

        $this->master()->import($data, $actor, false);

        foreach (['companies' => 1, 'sites' => 1, 'departments' => 19, 'document_categories' => 39, 'document_types' => 19, 'register_configurations' => 3, 'document_type_register' => 24, 'numbering_series' => 4, 'numbering_configurations' => 4, 'xppl_numbering_policies' => 1, 'xppl_correspondence_policies' => 2] as $table => $count) {
            $this->assertDatabaseCount($table, $count);
        }
        $this->assertSame(['skip'], array_values(array_unique(array_column($this->master()->import($data, null, false), 'action'))));
        foreach (['numbering_counters', 'issued_document_numbers', 'xppl_numbering_counters', 'xppl_numbering_allocations', 'xppl_numbering_mappings'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_commands_dry_run_import_and_export_and_refuse_other_environments(): void
    {
        $actor = User::factory()->create();
        $path = tempnam(sys_get_temp_dir(), 'dcms-master-');
        $output = $path.'.json';
        file_put_contents($path, json_encode($this->package()));
        try {
            $options = ['--file' => $path, '--actor' => (string) $actor->id];
            $this->artisan('dcms:master-import', $options + ['--dry-run' => true])->expectsOutputToContain('DRY RUN: no database writes.')->assertSuccessful();
            $this->assertDatabaseCount('companies', 0);
            $this->artisan('dcms:master-import', $options)->assertSuccessful();
            $this->artisan('dcms:master-export', ['--company' => 'XPPL', '--file' => $output])->assertSuccessful();
            $this->assertSame($this->package(), json_decode(file_get_contents($output), true));
            $this->app->detectEnvironment(fn (): string => 'uat');
            $this->artisan('dcms:master-import', $options)->assertFailed();
            $this->artisan('dcms:master-export', ['--file' => $output])->assertFailed();
        } finally {
            unlink($path);
            if (is_file($output)) {
                unlink($output);
            }
        }
    }
}
