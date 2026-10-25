<?php

namespace Tests\Feature;

use App\Models\AuthorizationScope;
use App\Models\Company;
use App\Models\Site;
use App\Models\User;
use App\Models\UserRoleAssignment;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Concerns\InteractsWithRbac;
use Tests\TestCase;

class RbacIntegrityTest extends TestCase
{
    use InteractsWithRbac, LazilyRefreshDatabase;

    public function test_scopes_require_matching_company_and_site_and_correct_shape(): void
    {
        $site = Site::factory()->create();
        $other = Company::factory()->create();

        $this->expectException(ValidationException::class);

        AuthorizationScope::context('site', $other->id, $site->id)->persist();
    }

    #[TestWith(['global', true, false, 'global'])]
    #[TestWith(['company', false, false, 'company:1'])]
    #[TestWith(['site', false, true, 'site:1'])]
    #[TestWith(['global', false, false, 'forged'])]
    public function test_database_rejects_invalid_scope_shapes_when_events_are_bypassed(string $type, bool $company, bool $site, string $key): void
    {
        $record = Site::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('authorization_scopes')->insert(['scope_type' => $type, 'company_id' => $company ? $record->company_id : null, 'site_id' => $site ? $record->id : null, 'scope_key' => $key]);
    }

    public function test_database_rejects_mismatched_scope_foreign_keys(): void
    {
        $site = Site::factory()->create();
        $other = Company::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('authorization_scopes')->insert(['scope_type' => 'site', 'company_id' => $other->id, 'site_id' => $site->id, 'scope_key' => 'site:'.$site->id]);
    }

    public function test_assignment_scopes_cannot_widen_a_site_owned_role(): void
    {
        $this->definitions();
        $site = Site::factory()->create();
        $scope = AuthorizationScope::context('site', $site->company_id, $site->id)->persist();
        $user = User::factory()->create();
        $role = $this->grant($user, $scope, []);
        $other = User::factory()->create();
        $global = AuthorizationScope::context('global')->persist();

        $this->expectException(ValidationException::class);

        UserRoleAssignment::query()->create(['user_id' => $other->id, 'role_id' => $role->id, 'scope_id' => $global->id]);
    }

    public function test_duplicate_user_role_scope_assignments_are_rejected(): void
    {
        $assignment = UserRoleAssignment::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('user_role_assignments')->insert($assignment->only(['user_id', 'role_id', 'scope_id', 'active']));
    }
}
