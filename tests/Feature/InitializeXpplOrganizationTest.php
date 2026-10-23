<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Site;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Tests\TestCase;

class InitializeXpplOrganizationTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** @return array<string, string> */
    private function nameOptions(): array
    {
        return [
            '--company-name-en' => 'Example XPPL Company',
            '--company-name-lo' => 'ບໍລິສັດຕົວຢ່າງ XPPL',
            '--site-name-en' => 'Example XPPL Site',
            '--site-name-lo' => 'ສະຖານທີ່ຕົວຢ່າງ XPPL',
        ];
    }

    public function test_initialization_is_idempotent_and_creates_only_the_xppl_company_and_site(): void
    {
        $this->artisan('organization:initialize-xppl', $this->nameOptions())
            ->expectsOutputToContain('XPPL initialization complete.')
            ->assertExitCode(0);
        $company = Company::query()->sole();
        $site = Site::query()->sole();
        $originalCompany = $company->getAttributes();
        $originalSite = $site->getAttributes();

        $this->travel(1)->hours();
        $this->artisan('organization:initialize-xppl', $this->nameOptions())->assertExitCode(0);

        $this->assertSame($originalCompany, $company->refresh()->getAttributes());
        $this->assertSame($originalSite, $site->refresh()->getAttributes());
        $this->assertSame($company->id, $site->company_id);
        $this->assertDatabaseHas('companies', ['code' => 'XPPL', 'name_en' => 'Example XPPL Company', 'name_lo' => 'ບໍລິສັດຕົວຢ່າງ XPPL']);
        $this->assertDatabaseHas('sites', ['code' => 'XPPL', 'name_en' => 'Example XPPL Site', 'name_lo' => 'ສະຖານທີ່ຕົວຢ່າງ XPPL']);
        $this->assertDatabaseCount('companies', 1);
        $this->assertDatabaseCount('sites', 1);
        $this->assertDatabaseCount('departments', 0);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_initialization_does_not_overwrite_existing_names_or_reactivate_records(): void
    {
        $company = Company::factory()->inactive()->create(['code' => 'XPPL', 'name_en' => 'Existing Company']);
        $site = Site::factory()->for($company)->inactive()->create(['code' => 'XPPL', 'name_en' => 'Existing Site']);

        $this->artisan('organization:initialize-xppl', $this->nameOptions())->assertExitCode(0);

        $this->assertSame('Existing Company', $company->refresh()->name_en);
        $this->assertSame('Existing Site', $site->refresh()->name_en);
        $this->assertFalse($company->active);
        $this->assertFalse($site->active);
        $this->assertDatabaseCount('companies', 1);
        $this->assertDatabaseCount('sites', 1);
    }

    public function test_initialization_can_complete_a_company_without_an_initial_site(): void
    {
        $company = Company::factory()->create(['code' => 'XPPL']);
        $otherCompanySite = Site::factory()->create(['code' => 'XPPL']);

        $this->artisan('organization:initialize-xppl', $this->nameOptions())->assertExitCode(0);

        $this->assertDatabaseHas('sites', ['company_id' => $company->id, 'code' => 'XPPL']);
        $this->assertModelExists($otherCompanySite);
        $this->assertDatabaseCount('companies', 2);
        $this->assertDatabaseCount('sites', 2);
    }

    #[TestWith(['--company-name-en', null])]
    #[TestWith(['--company-name-lo', '   '])]
    #[TestWith(['--site-name-en', null])]
    #[TestWith(['--site-name-lo', null])]
    public function test_initialization_requires_all_supplied_names(string $option, ?string $value): void
    {
        $options = array_replace($this->nameOptions(), [$option => $value]);

        $this->artisan('organization:initialize-xppl', $options)->assertExitCode(1);

        $this->assertDatabaseCount('companies', 0);
        $this->assertDatabaseCount('sites', 0);
    }

    public function test_initialization_rejects_names_longer_than_the_database_column_limit(): void
    {
        $options = array_replace($this->nameOptions(), ['--company-name-en' => str_repeat('X', 256)]);

        $this->artisan('organization:initialize-xppl', $options)->assertExitCode(1);

        $this->assertDatabaseCount('companies', 0);
        $this->assertDatabaseCount('sites', 0);
    }

    public function test_default_seeding_creates_no_accounts_or_organizational_records(): void
    {
        $this->seed();

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('companies', 0);
        $this->assertDatabaseCount('sites', 0);
        $this->assertDatabaseCount('departments', 0);
    }

    public function test_initialization_rolls_back_the_company_if_site_creation_fails(): void
    {
        Event::listen('eloquent.creating: '.Site::class, function (Site $site): void {
            throw new RuntimeException('Simulated initialization failure.');
        });

        try {
            $this->artisan('organization:initialize-xppl', $this->nameOptions())->run();
            $this->fail('The simulated site failure did not occur.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated initialization failure.', $exception->getMessage());
        } finally {
            Event::forget('eloquent.creating: '.Site::class);
        }

        $this->assertDatabaseCount('companies', 0);
        $this->assertDatabaseCount('sites', 0);
    }
}
