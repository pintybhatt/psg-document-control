<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class BootstrapAdministratorTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** @return array<string, string> */
    private function identity(): array
    {
        return ['--name' => 'Example Initial Account', '--email' => 'bootstrap@example.test', '--language' => 'lo'];
    }

    public function test_bootstrap_uses_hidden_confirmed_password_and_creates_no_organization_or_roles(): void
    {
        $password = 'A test-only long password 123';

        $this->artisan('user:bootstrap-admin', $this->identity())
            ->expectsQuestion('Password', $password)->expectsQuestion('Confirm password', $password)
            ->expectsOutputToContain('No role, site assignment or document privileges were granted.')
            ->doesntExpectOutputToContain($password)->assertExitCode(0);

        $user = User::query()->sole();
        $this->assertTrue(Hash::check($password, $user->password));
        $this->assertNotSame($password, $user->password);
        $this->assertTrue($user->active);
        $this->assertSame('lo', $user->preferred_language);
        $this->assertNull($user->email_verified_at);
        $this->assertDatabaseCount('companies', 0);
        $this->assertDatabaseCount('sites', 0);
        $this->assertDatabaseCount('departments', 0);
    }

    public function test_existing_identity_is_a_no_op_without_password_prompts_or_reactivation(): void
    {
        $user = User::factory()->inactive()->create(['email' => 'bootstrap@example.test']);
        $original = $user->refresh()->getAttributes();

        $this->artisan('user:bootstrap-admin', $this->identity() + ['--no-interaction' => true])->assertExitCode(0);

        $this->assertSame($original, $user->refresh()->getAttributes());
        $this->assertDatabaseCount('users', 1);
    }

    public function test_bootstrap_refuses_to_create_a_second_identity(): void
    {
        User::factory()->create();

        $this->artisan('user:bootstrap-admin', $this->identity())->assertExitCode(1);

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseMissing('users', ['email' => 'bootstrap@example.test']);
    }

    public function test_noninteractive_bootstrap_cannot_create_an_account_with_a_default_password(): void
    {
        $this->artisan('user:bootstrap-admin', $this->identity() + ['--no-interaction' => true])->assertExitCode(1);

        $this->assertDatabaseCount('users', 0);
    }

    #[TestWith([8])]
    #[TestWith([9])]
    #[TestWith([10])]
    #[TestWith([11])]
    #[TestWith([12])]
    #[TestWith([13])]
    #[TestWith([14])]
    #[TestWith([15])]
    public function test_local_bootstrap_accepts_passwords_between_eight_and_fifteen_characters(int $length): void
    {
        $this->app['env'] = 'local';
        $password = str_repeat('x', $length);

        $this->artisan('user:bootstrap-admin', $this->identity())
            ->expectsQuestion('Password', $password)->expectsQuestion('Confirm password', $password)
            ->assertExitCode(0);

        $this->assertTrue(Hash::check($password, User::query()->sole()->password));
        $this->assertDatabaseCount('roles', 0);
        $this->assertDatabaseCount('user_role_assignments', 0);
        $this->assertDatabaseCount('companies', 0);
        $this->assertDatabaseCount('sites', 0);
        $this->assertDatabaseCount('departments', 0);
    }

    #[TestWith(['testing', 8])]
    #[TestWith(['testing', 15])]
    #[TestWith(['staging', 8])]
    #[TestWith(['staging', 15])]
    #[TestWith(['production', 8])]
    #[TestWith(['production', 15])]
    #[TestWith(['development', 8])]
    #[TestWith(['development', 15])]
    #[TestWith(['', 15])]
    public function test_nonlocal_bootstrap_rejects_passwords_below_sixteen_characters(string $environment, int $length): void
    {
        $this->app['env'] = $environment;
        $password = str_repeat('x', $length);

        $this->artisan('user:bootstrap-admin', $this->identity())
            ->expectsQuestion('Password', $password)->expectsQuestion('Confirm password', $password)
            ->expectsOutputToContain('Password must be at least 16 characters')
            ->assertExitCode(1);

        $this->assertDatabaseCount('users', 0);
    }

    #[TestWith(['testing'])]
    #[TestWith(['production'])]
    public function test_nonlocal_bootstrap_accepts_the_sixteen_character_minimum(string $environment): void
    {
        $this->app['env'] = $environment;
        $password = '1234567890abcdef';

        $this->artisan('user:bootstrap-admin', $this->identity())
            ->expectsQuestion('Password', $password)->expectsQuestion('Confirm password', $password)
            ->assertExitCode(0);

        $this->assertTrue(Hash::check($password, User::query()->sole()->password));
    }

    #[TestWith(['1234567', '1234567'])]
    #[TestWith(['12345678', '87654321'])]
    #[TestWith(["valid\0password", "valid\0password"])]
    public function test_local_bootstrap_rejects_short_null_byte_or_mismatched_passwords(string $password, string $confirmation): void
    {
        $this->app['env'] = 'local';

        $this->artisan('user:bootstrap-admin', $this->identity())
            ->expectsQuestion('Password', $password)->expectsQuestion('Confirm password', $confirmation)
            ->expectsOutputToContain('Password must be at least 8 characters')
            ->assertExitCode(1);

        $this->assertDatabaseCount('users', 0);
    }

    #[TestWith(['short', 'short'])]
    #[TestWith(['A test-only long password 123', 'A different long password 123'])]
    #[TestWith(['', ''])]
    public function test_unsafe_or_mismatched_passwords_create_no_account(string $password, string $confirmation): void
    {
        $this->artisan('user:bootstrap-admin', $this->identity())
            ->expectsQuestion('Password', $password)->expectsQuestion('Confirm password', $confirmation)
            ->assertExitCode(1);

        $this->assertDatabaseCount('users', 0);
    }

    #[TestWith(['local'])]
    #[TestWith(['testing'])]
    public function test_passwords_over_the_bcrypt_byte_limit_are_rejected(string $environment): void
    {
        $this->app['env'] = $environment;
        $password = str_repeat('ກ', 25);

        $this->artisan('user:bootstrap-admin', $this->identity())
            ->expectsQuestion('Password', $password)->expectsQuestion('Confirm password', $password)
            ->assertExitCode(1);

        $this->assertDatabaseCount('users', 0);
    }

    public function test_identity_validation_and_default_seeding_create_no_accounts(): void
    {
        $this->artisan('user:bootstrap-admin', ['--name' => 'Example', '--email' => 'invalid'])->assertExitCode(1);
        $this->seed();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_concurrent_bootstrap_lock_prevents_account_creation(): void
    {
        $lock = Cache::lock('dcms:initial-user-bootstrap', 30);
        $lock->get();
        $password = 'A test-only long password 123';

        try {
            $this->artisan('user:bootstrap-admin', $this->identity())
                ->expectsQuestion('Password', $password)->expectsQuestion('Confirm password', $password)
                ->expectsOutputToContain('Another bootstrap operation is in progress.')
                ->assertExitCode(1);
        } finally {
            $lock->release();
        }

        $this->assertDatabaseCount('users', 0);
    }
}
