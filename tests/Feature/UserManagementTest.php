<?php

namespace Tests\Feature;

use App\Authorization\RbacMutation;
use App\Models\AuthorizationScope;
use App\Models\RbacAudit;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\UserRoleAssignment;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use LogicException;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Concerns\InteractsWithRbac;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use InteractsWithRbac, LazilyRefreshDatabase;

    private function administrator(): User
    {
        $this->definitions();
        $user = User::factory()->create();
        app(RbacMutation::class)->run(function () use ($user): void {
            $scope = AuthorizationScope::context('global')->persist();
            UserRoleAssignment::query()->create(['user_id' => $user->id, 'role_id' => Role::query()->where('code', 'SECURITY_ADMINISTRATOR')->sole()->id, 'scope_id' => $scope->id, 'active' => true]);
        });
        $this->signIn($user);

        return $user;
    }

    /** @return array<string, string> */
    private function newAccount(): array
    {
        return ['name' => 'XPPL Employee', 'email' => 'employee@example.test', 'preferred_language' => 'en', 'password' => 'SecurePass123!', 'password_confirmation' => 'SecurePass123!'];
    }

    public function test_guest_cannot_access_user_management_and_regular_user_is_forbidden(): void
    {
        $this->getJson('/api/v1/user-management')->assertUnauthorized();
        $this->postJson('/api/v1/user-management', $this->newAccount())->assertUnauthorized();
        $this->signIn(User::factory()->create());
        $this->getJson('/api/v1/user-management')->assertForbidden();
        $this->postJson('/api/v1/user-management', $this->newAccount())->assertForbidden();
        $this->assertDatabaseMissing('users', ['email' => 'employee@example.test']);
    }

    public function test_global_administrator_creates_and_lists_accounts_without_granting_privileges(): void
    {
        $this->administrator();
        $response = $this->postJson('/api/v1/user-management', $this->newAccount())->assertCreated()->assertJsonPath('data.email', 'employee@example.test');
        $this->assertArrayNotHasKey('password', $response->json('data'));
        $user = User::query()->where('email', 'employee@example.test')->sole();
        $this->assertTrue(Hash::check('SecurePass123!', $user->password));
        $this->assertSame(0, $user->roleAssignments()->count());
        $this->assertSame(0, $user->memberships()->count());
        $this->getJson('/api/v1/user-management?q=Employee')->assertOk()->assertJsonCount(1, 'data');
        $audit = RbacAudit::query()->where('action', 'user.created')->sole();
        $this->assertArrayNotHasKey('password', $audit->after);
    }

    public function test_update_deactivates_account_and_invalidates_its_sessions(): void
    {
        $admin = $this->administrator();
        $target = User::factory()->create();
        $previousSessionVersion = $target->session_version;
        $this->patchJson('/api/v1/user-management/'.$target->id, ['name' => 'Updated Name', 'active' => false])->assertOk()->assertJsonPath('data.active', false);
        $this->assertFalse($target->refresh()->active);
        $this->assertNotSame($previousSessionVersion, $target->session_version);
        $this->patchJson('/api/v1/user-management/'.$admin->id, ['active' => false])->assertForbidden();
        $this->assertTrue($admin->refresh()->active);
    }

    public function test_password_reset_requires_confirmation_and_never_exposes_password_in_audit(): void
    {
        $this->administrator();
        $target = User::factory()->create();
        $this->patchJson('/api/v1/user-management/'.$target->id, ['password' => 'newpassword'])->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->patchJson('/api/v1/user-management/'.$target->id, ['password' => 'NewPassword123', 'password_confirmation' => 'NewPassword123'])->assertOk();
        $this->assertTrue(Hash::check('NewPassword123', $target->refresh()->password));
        $audit = RbacAudit::query()->where('action', 'user.updated')->sole();
        $this->assertTrue($audit->after['password_changed']);
        $this->assertArrayNotHasKey('password', $audit->after);
    }

    public function test_invalid_email_and_duplicate_identity_are_rejected(): void
    {
        $this->administrator();
        $this->postJson('/api/v1/user-management', array_replace($this->newAccount(), ['email' => 'bad']))->assertUnprocessable()->assertJsonValidationErrors('email');
        User::factory()->create(['email' => 'employee@example.test']);
        $this->postJson('/api/v1/user-management', $this->newAccount())->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_name_email_and_activation_updates_preserve_identity_and_existing_login(): void
    {
        $this->administrator();
        $target = User::factory()->inactive()->create(['password' => 'SecurePass123!']);
        $passwordHash = $target->password;
        $this->patchJson('/api/v1/user-management/'.$target->id, [
            'name' => 'Updated Account', 'email' => ' Updated@Example.Test ', 'active' => true,
        ])->assertOk()->assertJsonPath('data.id', $target->id)->assertJsonPath('data.email', 'updated@example.test');
        $this->assertTrue($target->refresh()->active);
        $this->assertSame($passwordHash, $target->password);
        $this->assertSame('Updated Account', $target->name);
        $this->getJson('/api/v1/user-management?q=updated@example.test')->assertOk()->assertJsonCount(1, 'data');
        $audit = RbacAudit::query()->where('action', 'user.updated')->sole();
        $this->assertFalse($audit->before['active']);
        $this->assertTrue($audit->after['active']);
        $this->assertSame($target->id, (int) $audit->subject_id);
        $this->post('/logout')->assertRedirect();
        $this->post('/login', ['email' => 'updated@example.test', 'password' => 'SecurePass123!', 'language' => 'en'])->assertRedirect('/dashboard');
        $this->getJson('/api/v1/user')->assertOk()->assertJsonPath('data.id', $target->id);
    }

    #[TestWith(['company'])]
    #[TestWith(['site'])]
    #[TestWith(['global'])]
    public function test_delegated_account_permissions_do_not_grant_system_administrator_authority(string $scopeType): void
    {
        $this->definitions();
        $actor = User::factory()->create();
        $target = User::factory()->create();
        $site = Site::factory()->create();
        $scope = AuthorizationScope::context($scopeType, $scopeType === 'global' ? null : $site->company_id, $scopeType === 'site' ? $site->id : null);
        $this->grant($actor, $scope, ['users.view', 'users.manage']);
        $this->signIn($actor);
        $this->getJson('/api/v1/user-management')->assertForbidden();
        $this->postJson('/api/v1/user-management', $this->newAccount())->assertForbidden();
        $this->patchJson('/api/v1/user-management/'.$target->id, ['active' => false])->assertForbidden();
        $this->assertTrue($target->refresh()->active);
        $this->assertDatabaseMissing('users', ['email' => 'employee@example.test']);
    }

    public function test_failed_audit_write_rolls_back_account_creation(): void
    {
        $this->administrator();
        $this->withoutExceptionHandling();
        $event = 'eloquent.creating: '.RbacAudit::class;
        Event::listen($event, function (): void {
            throw new LogicException('Synthetic audit failure');
        });
        try {
            $this->postJson('/api/v1/user-management', $this->newAccount());
            $this->fail('Account creation survived a failed audit.');
        } catch (LogicException $exception) {
            $this->assertSame('Synthetic audit failure', $exception->getMessage());
        } finally {
            Event::forget($event);
        }
        $this->assertDatabaseMissing('users', ['email' => 'employee@example.test']);
    }
}
