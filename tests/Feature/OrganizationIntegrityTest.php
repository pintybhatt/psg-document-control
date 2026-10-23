<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Department;
use App\Models\Site;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class OrganizationIntegrityTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_duplicate_company_codes_are_rejected(): void
    {
        $company = Company::factory()->create();

        $this->expectException(QueryException::class);

        Company::factory()->create(['code' => $company->code]);
    }

    public function test_duplicate_site_codes_within_one_company_are_rejected(): void
    {
        $site = Site::factory()->create();

        $this->expectException(QueryException::class);

        Site::factory()->for($site->company)->create(['code' => $site->code]);
    }

    public function test_the_same_site_code_can_be_used_by_different_companies(): void
    {
        $site = Site::factory()->create(['code' => 'MAIN']);

        $otherSite = Site::factory()->create(['code' => 'MAIN']);

        $this->assertNotSame($site->company_id, $otherSite->company_id);
        $this->assertDatabaseCount('sites', 2);
    }

    public function test_a_site_cannot_reference_a_nonexistent_company(): void
    {
        $company = Company::factory()->create();

        $this->expectException(QueryException::class);

        Site::factory()->create(['company_id' => $company->id + 1]);
    }

    public function test_duplicate_department_codes_within_one_site_are_rejected(): void
    {
        $department = Department::factory()->create();

        $this->expectException(QueryException::class);

        Department::factory()->for($department->site)->create(['code' => $department->code]);
    }

    #[TestWith([true])]
    #[TestWith([false])]
    public function test_department_codes_can_be_reused_at_other_sites(bool $sameCompany): void
    {
        $department = Department::factory()->create(['code' => 'EXAMPLE']);
        $otherCompany = $sameCompany ? $department->company : Company::factory()->create();
        $otherSite = Site::factory()->for($otherCompany)->create();

        $otherDepartment = Department::factory()->for($otherSite)->create(['code' => 'EXAMPLE']);

        $this->assertNotSame($department->site_id, $otherDepartment->site_id);
        $this->assertSame($otherCompany->id, $otherDepartment->company_id);
        $this->assertDatabaseCount('departments', 2);
    }

    public function test_model_creation_rejects_a_department_in_another_companys_site(): void
    {
        $site = Site::factory()->create();
        $otherCompany = Company::factory()->create();

        try {
            Department::factory()->for($site)->create(['company_id' => $otherCompany->id]);
            $this->fail('An inconsistent department was accepted.');
        } catch (ValidationException $exception) {
            $this->assertSame(['The selected site id is invalid.'], $exception->errors()['site_id']);
        }

        $this->assertDatabaseCount('departments', 0);
    }

    public function test_model_updates_reject_a_department_company_mismatch(): void
    {
        $department = Department::factory()->create();
        $originalCompanyId = $department->company_id;
        $otherCompany = Company::factory()->create();

        try {
            $department->update(['company_id' => $otherCompany->id]);
            $this->fail('An inconsistent department update was accepted.');
        } catch (ValidationException $exception) {
            $this->assertSame(['The selected site id is invalid.'], $exception->errors()['site_id']);
        }

        $this->assertSame($originalCompanyId, $department->refresh()->company_id);
    }

    public function test_model_validation_rejects_a_nonexistent_site(): void
    {
        $department = Department::factory()->create();

        $this->expectException(ValidationException::class);

        $department->update(['site_id' => $department->site_id + 1]);
    }

    public function test_database_rejects_inconsistent_departments_when_model_events_are_bypassed(): void
    {
        $site = Site::factory()->create();
        $otherCompany = Company::factory()->create();
        $attributes = Department::factory()->for($site)->raw(['company_id' => $otherCompany->id]);

        $this->expectException(QueryException::class);

        DB::table('departments')->insert($attributes);
    }

    #[TestWith(['company_id'])]
    #[TestWith(['site_id'])]
    public function test_database_rejects_inconsistent_department_updates(string $column): void
    {
        $department = Department::factory()->create();
        $otherSite = Site::factory()->create();
        $value = $column === 'company_id' ? $otherSite->company_id : $otherSite->id;

        $this->expectException(QueryException::class);

        Department::query()->whereKey($department->id)->update([$column => $value]);
    }

    public function test_database_rejects_departments_without_a_valid_site_even_with_model_events_bypassed(): void
    {
        $department = Department::factory()->create();

        $this->expectException(QueryException::class);

        Department::query()->whereKey($department->id)->update(['site_id' => $department->site_id + 1]);
    }

    public function test_a_site_cannot_move_to_another_company_while_departments_reference_it(): void
    {
        $department = Department::factory()->create();
        $otherCompany = Company::factory()->create();

        $this->expectException(QueryException::class);

        Site::query()->whereKey($department->site_id)->update(['company_id' => $otherCompany->id]);
    }

    public function test_a_company_with_sites_cannot_be_deleted_without_departments(): void
    {
        $site = Site::factory()->create();

        $this->expectException(QueryException::class);

        $site->company->delete();
    }

    #[TestWith(['company'])]
    #[TestWith(['site'])]
    public function test_referenced_organizational_records_cannot_be_deleted(string $relationship): void
    {
        $department = Department::factory()->create();
        $parent = $department->{$relationship};

        $this->expectException(QueryException::class);

        $parent->delete();
    }
}
