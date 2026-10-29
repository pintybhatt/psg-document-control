<?php

namespace Tests\Feature;

use App\Authorization\RbacMutation;
use App\Models\AuthorizationScope;
use App\Models\RbacAudit;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRoleAssignment;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
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
}
