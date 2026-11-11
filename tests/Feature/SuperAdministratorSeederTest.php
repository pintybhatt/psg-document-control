<?php

namespace Tests\Feature;

use App\Authorization\AccessControl;
use App\Authorization\PermissionCatalog;
use App\Models\AuthorizationScope;
use App\Models\User;
use App\Models\UserRoleAssignment;
use Database\Seeders\SuperAdministratorSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

class SuperAdministratorSeederTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const KEYS = ['SUPER_ADMIN_EMAIL', 'SUPER_ADMIN_NAME', 'SUPER_ADMIN_PASSWORD', 'SUPER_ADMIN_LANGUAGE'];

    protected function tearDown(): void
    {
        foreach (self::KEYS as $key) {
            unset($_SERVER[$key], $_ENV[$key]);
        }
        parent::tearDown();
    }

    private function configure(array $values): void
    {
        foreach ($values as $key => $value) {
            $_SERVER[$key] = $_ENV[$key] = $value;
        }
    }

    public function test_it_creates_a_global_system_administrator_with_every_available_permission(): void
    {
        $this->configure(['SUPER_ADMIN_EMAIL' => 'Admin@Example.test', 'SUPER_ADMIN_NAME' => 'Super Admin', 'SUPER_ADMIN_PASSWORD' => 'Local-only secret 123']);

        $this->seed(SuperAdministratorSeeder::class);

        $user = User::query()->sole();
        $access = app(AccessControl::class);
        $available = array_keys(array_filter(PermissionCatalog::definitions(), fn (array $definition): bool => $definition['available']));
        $this->assertSame('admin@example.test', $user->email);
        $this->assertTrue(Hash::check('Local-only secret 123', $user->password));
        $this->assertTrue($access->isSystemAdministrator($user));
        $this->assertEqualsCanonicalizing($available, $access->grantedPermissions($user, AuthorizationScope::context('global')));
        $this->assertDatabaseHas('rbac_audits', ['action' => 'administrator.bootstrapped']);
    }

    public function test_it_reuses_an_existing_account_without_changing_its_password_and_is_idempotent(): void
    {
        $user = User::factory()->create(['email' => 'existing@example.test', 'password' => 'Original secret 123']);
        $this->configure(['SUPER_ADMIN_EMAIL' => 'existing@example.test']);

        $this->seed(SuperAdministratorSeeder::class);
        $this->seed(SuperAdministratorSeeder::class);

        $this->assertTrue(Hash::check('Original secret 123', $user->refresh()->password));
        $this->assertSame(1, UserRoleAssignment::query()->where('user_id', $user->id)->where('active', true)->count());
        $this->assertTrue(app(AccessControl::class)->isSystemAdministrator($user));
    }

    public function test_it_requires_a_password_to_create_a_new_account(): void
    {
        $this->configure(['SUPER_ADMIN_EMAIL' => 'new@example.test']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SUPER_ADMIN_PASSWORD');

        try {
            $this->seed(SuperAdministratorSeeder::class);
        } finally {
            $this->assertDatabaseCount('users', 0);
        }
    }

    public function test_it_refuses_to_run_outside_local_and_testing(): void
    {
        $this->configure(['SUPER_ADMIN_EMAIL' => 'admin@example.test', 'SUPER_ADMIN_PASSWORD' => 'Local-only secret 123']);
        $this->app->detectEnvironment(fn (): string => 'production');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('local or testing');

        try {
            $this->app->call([new SuperAdministratorSeeder, 'run']);
        } finally {
            $this->app->detectEnvironment(fn (): string => 'testing');
            $this->assertDatabaseCount('users', 0);
        }
    }

    public function test_the_default_database_seeder_still_creates_no_users(): void
    {
        $this->configure(['SUPER_ADMIN_EMAIL' => 'admin@example.test', 'SUPER_ADMIN_PASSWORD' => 'Local-only secret 123']);

        $this->seed();

        $this->assertDatabaseCount('users', 0);
    }
}
