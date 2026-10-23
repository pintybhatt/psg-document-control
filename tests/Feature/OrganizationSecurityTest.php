<?php

namespace Tests\Feature;

use App\Models\Department;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class OrganizationSecurityTest extends TestCase
{
    use LazilyRefreshDatabase;

    #[TestWith(['companies'])]
    #[TestWith(['sites'])]
    #[TestWith(['departments'])]
    public function test_unauthenticated_organizational_mutations_have_no_http_endpoints(string $resource): void
    {
        $department = Department::factory()->create();
        $model = match ($resource) {
            'companies' => $department->company,
            'sites' => $department->site,
            'departments' => $department,
        };
        $original = $model->refresh()->getAttributes();

        foreach (['', '/api', '/administration'] as $prefix) {
            $this->postJson($prefix.'/'.$resource, ['code' => 'UNAUTHORIZED'])->assertNotFound();
            $this->putJson($prefix.'/'.$resource.'/'.$model->id, ['name_en' => 'Unauthorized'])->assertNotFound();
            $this->patchJson($prefix.'/'.$resource.'/'.$model->id, ['active' => false])->assertNotFound();
            $this->deleteJson($prefix.'/'.$resource.'/'.$model->id)->assertNotFound();
        }

        $this->assertSame($original, $model->refresh()->getAttributes());
        $this->assertDatabaseCount('companies', 1);
        $this->assertDatabaseCount('sites', 1);
        $this->assertDatabaseCount('departments', 1);
    }
}
