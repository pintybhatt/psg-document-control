<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Department;
use App\Models\Site;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class OrganizationModelsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_company_persists_bilingual_names_and_defaults_to_active(): void
    {
        $company = Company::query()->create([
            'code' => 'TEST-COMPANY',
            'name_en' => 'Example Company',
            'name_lo' => 'ບໍລິສັດຕົວຢ່າງ',
        ])->refresh();

        $this->assertModelExists($company);
        $this->assertSame('Example Company', $company->name_en);
        $this->assertSame('ບໍລິສັດຕົວຢ່າງ', $company->name_lo);
        $this->assertTrue($company->active);
        $this->assertNotNull($company->created_at);
        $this->assertNotNull($company->updated_at);
    }

    public function test_site_and_department_persist_unicode_and_resolve_their_owners(): void
    {
        $company = Company::factory()->create();
        $site = Site::factory()->for($company)->create([
            'name_en' => 'Example Site', 'name_lo' => 'ສະຖານທີ່ຕົວຢ່າງ',
        ]);
        $department = Department::factory()->for($site)->create([
            'name_en' => 'Example Department', 'name_lo' => 'ພະແນກຕົວຢ່າງ',
        ]);

        $this->assertTrue($company->sites->sole()->is($site));
        $this->assertTrue($site->company->is($company));
        $this->assertTrue($site->departments->sole()->is($department));
        $this->assertTrue($department->site->is($site));
        $this->assertTrue($department->company->is($company));
        $this->assertSame('Example Site', $site->refresh()->name_en);
        $this->assertSame('ສະຖານທີ່ຕົວຢ່າງ', $site->name_lo);
        $this->assertSame('Example Department', $department->refresh()->name_en);
        $this->assertSame('ພະແນກຕົວຢ່າງ', $department->name_lo);
    }

    public function test_active_scopes_exclude_inactive_records_without_hiding_history(): void
    {
        $company = Company::factory()->create();
        $inactiveCompany = Company::factory()->inactive()->create();
        $site = Site::factory()->for($company)->create();
        $inactiveSite = Site::factory()->for($company)->inactive()->create();
        $department = Department::factory()->for($site)->create();
        $inactiveDepartment = Department::factory()->for($site)->inactive()->create();

        $this->assertSame([$company->id], Company::active()->pluck('id')->all());
        $this->assertSame([$site->id], Site::active()->pluck('id')->all());
        $this->assertSame([$department->id], Department::active()->pluck('id')->all());
        $this->assertFalse($inactiveCompany->refresh()->active);
        $this->assertFalse($inactiveSite->refresh()->active);
        $this->assertFalse($inactiveDepartment->refresh()->active);
        $this->assertCount(2, $company->sites);
        $this->assertCount(2, $site->departments);
    }

    public function test_deactivation_preserves_records_and_relationships_without_cascading_flags(): void
    {
        $department = Department::factory()->create();
        $site = $department->site;
        $company = $department->company;

        $company->update(['active' => false]);

        $this->assertTrue($site->refresh()->active);
        $this->assertTrue($department->refresh()->active);
        $this->assertFalse($site->company->active);
        $site->update(['active' => false]);
        $department->update(['active' => false]);
        $this->assertFalse($site->refresh()->active);
        $this->assertFalse($department->refresh()->active);
        $this->assertTrue($department->site->is($site));
        $this->assertDatabaseCount('companies', 1);
        $this->assertDatabaseCount('sites', 1);
        $this->assertDatabaseCount('departments', 1);
    }
}
