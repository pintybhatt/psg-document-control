<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\InteractsWithRbac;
use Tests\TestCase;

class ProfilePasswordTest extends TestCase
{
    use InteractsWithRbac, LazilyRefreshDatabase;

    public function test_user_changes_password_stays_signed_in_and_other_sessions_end(): void
    {
        $user = User::factory()->create(['password' => 'old-password-1']);
        $oldVersion = $user->session_version;
        $this->signIn($user);

        $this->putJson('/api/v1/profile/password', ['current_password' => 'old-password-1', 'password' => 'New-password-2', 'password_confirmation' => 'New-password-2'])
            ->assertOk()->assertJson(['message' => 'profilePasswordUpdated']);

        $user->refresh();
        $this->assertTrue(Hash::check('New-password-2', $user->password));
        $this->assertNotSame($oldVersion, $user->session_version);
        $this->assertSame($user->session_version, session('auth.session_version'));
        $this->getJson('/api/v1/user')->assertOk();

        $this->flushSession();
        $this->actingAs($user)->withSession(['auth.session_version' => $oldVersion])->getJson('/api/v1/user')->assertUnauthorized();
    }

    public function test_password_change_rejects_wrong_current_mismatch_short_and_unchanged_passwords(): void
    {
        $user = User::factory()->create(['password' => 'old-password-1']);
        $this->signIn($user);

        $cases = [
            [['current_password' => 'wrong', 'password' => 'New-password-2', 'password_confirmation' => 'New-password-2'], 'current_password', 'profilePasswordCurrentInvalid'],
            [['current_password' => 'old-password-1', 'password' => 'New-password-2', 'password_confirmation' => 'Other-password-3'], 'password', 'profilePasswordMismatch'],
            [['current_password' => 'old-password-1', 'password' => 'short', 'password_confirmation' => 'short'], 'password', 'profilePasswordTooShort'],
            [['current_password' => 'old-password-1', 'password' => 'old-password-1', 'password_confirmation' => 'old-password-1'], 'password', 'profilePasswordSame'],
            [['current_password' => 'old-password-1', 'password' => str_repeat('a', 73), 'password_confirmation' => str_repeat('a', 73)], 'password', 'profilePasswordTooLong'],
            [[], 'current_password', 'profilePasswordCurrentRequired'],
        ];
        foreach ($cases as [$body, $field, $message]) {
            $this->putJson('/api/v1/profile/password', $body)->assertUnprocessable()->assertJsonPath("errors.$field.0", $message);
        }
        $this->assertTrue(Hash::check('old-password-1', $user->refresh()->password));
    }

    public function test_password_change_requires_sign_in(): void
    {
        $this->putJson('/api/v1/profile/password', [])->assertUnauthorized();
    }
}
