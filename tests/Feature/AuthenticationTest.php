<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** @return array{email: string, password: string, language: string} */
    private function credentials(User $user, string $language = 'en'): array
    {
        return ['email' => $user->email, 'password' => 'password', 'language' => $language];
    }

    public function test_login_view_is_public_and_does_not_expose_credentials(): void
    {
        $this->withoutVite();

        $this->get('/login')->assertOk()->assertViewHas('page', 'login')
            ->assertSee('csrfToken')->assertDontSee('password_confirmation');
    }

    #[TestWith(['/dashboard'])]
    #[TestWith(['/incoming'])]
    #[TestWith(['/outgoing'])]
    #[TestWith(['/controlled-documents'])]
    #[TestWith(['/document-search'])]
    #[TestWith(['/reports'])]
    #[TestWith(['/administration'])]
    #[TestWith(['/dev/ui-components'])]
    public function test_protected_browser_routes_redirect_guests_and_return_401_for_json(string $path): void
    {
        $this->get($path)->assertRedirect('/login');
        $this->getJson($path)->assertUnauthorized();
    }

    public function test_current_user_endpoint_returns_401_even_without_an_accept_header(): void
    {
        $this->get('/api/v1/user')->assertUnauthorized()->assertJson(['message' => 'Unauthenticated.']);
    }

    public function test_login_regenerates_the_session_and_csrf_token_and_persists_lao_preference(): void
    {
        $user = User::factory()->create();
        $this->withSession(['language' => 'en', '_token' => 'previous-token']);
        $sessionId = session()->getId();

        $this->post('/login', $this->credentials($user, 'lo'))->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($sessionId, session()->getId());
        $this->assertNotSame('previous-token', session()->token());
        $this->assertSame($user->session_version, session('auth.session_version'));
        $this->assertSame('lo', $user->refresh()->preferred_language);
        $this->assertSame('lo', session('language'));
    }

    public function test_login_returns_to_the_protected_browser_destination(): void
    {
        $user = User::factory()->create();
        $this->get('/reports')->assertRedirect('/login');

        $this->post('/login', $this->credentials($user))->assertRedirect('/reports');
    }

    public function test_email_login_is_case_normalized_and_password_is_hashed(): void
    {
        $user = User::factory()->create(['email' => 'Example@EXAMPLE.TEST']);

        $this->post('/login', array_replace($this->credentials($user), ['email' => 'EXAMPLE@example.test']))->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);
        $this->assertSame('example@example.test', $user->email);
        $this->assertTrue(Hash::check('password', $user->password));
        $this->assertNotSame('password', $user->password);
    }

    public function test_normalized_login_identity_is_unique(): void
    {
        User::factory()->create(['email' => 'Example@example.test']);

        $this->expectException(QueryException::class);

        User::factory()->create(['email' => 'EXAMPLE@EXAMPLE.TEST']);
    }

    public function test_login_rejects_password_suffixes_beyond_the_bcrypt_byte_limit(): void
    {
        $password = str_repeat('A', 72);
        $user = User::factory()->create(['password' => $password]);

        $this->postJson('/login', ['email' => $user->email, 'password' => $password.'suffix', 'language' => 'en'])
            ->assertUnprocessable()->assertJsonPath('errors.email.0', 'authInvalidCredentials');

        $this->assertGuest();
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_invalid_credentials_and_inactive_accounts_receive_the_same_safe_error(bool $inactive): void
    {
        $user = $inactive ? User::factory()->inactive()->create() : User::factory()->create();
        $credentials = array_replace($this->credentials($user, 'lo'), ['password' => $inactive ? 'password' : 'invalid-secret']);

        $this->from('/login')->post('/login', $credentials)->assertRedirect('/login')
            ->assertSessionHasErrors(['email' => 'authInvalidCredentials'])
            ->assertSessionMissing('_old_input.password');

        $this->assertGuest();
        $this->assertSame('lo', session('language'));
    }

    public function test_unknown_accounts_receive_the_same_authentication_error(): void
    {
        $this->postJson('/login', ['email' => 'unknown@example.test', 'password' => 'unverified-secret', 'language' => 'en'])
            ->assertUnprocessable()->assertJsonValidationErrors(['email'])
            ->assertJsonPath('errors.email.0', 'authInvalidCredentials');

        $this->assertGuest();
    }

    public function test_required_login_fields_and_language_are_validated(): void
    {
        $this->postJson('/login', [])->assertUnprocessable()->assertJsonValidationErrors(['email', 'password', 'language']);
        $this->postJson('/login', ['email' => 'invalid', 'password' => 'x', 'language' => 'unsupported'])
            ->assertUnprocessable()->assertJsonValidationErrors(['email', 'language']);
        $this->assertGuest();
    }

    public function test_login_is_throttled_after_five_failures_and_recovers_after_the_window(): void
    {
        $user = User::factory()->create();
        $invalid = array_replace($this->credentials($user), ['password' => 'incorrect']);
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/login', $invalid)->assertUnprocessable();
        }

        $this->postJson('/login', $this->credentials($user))->assertTooManyRequests()->assertJsonPath('errors.email.0', 'authThrottled');
        $this->assertGuest();
        $this->travel(61)->seconds();
        $this->post('/login', $this->credentials($user))->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
    }

    public function test_login_ip_limit_applies_across_different_identities(): void
    {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $this->postJson('/login', ['email' => 'unknown-'.$attempt.'@example.test', 'password' => 'incorrect', 'language' => 'en'])->assertUnprocessable();
        }

        $this->postJson('/login', ['email' => 'another@example.test', 'password' => 'incorrect', 'language' => 'en'])->assertTooManyRequests();
    }

    public function test_current_user_response_contains_only_the_explicit_public_fields(): void
    {
        $user = User::factory()->create();
        $this->post('/login', $this->credentials($user));

        $this->getJson('/api/v1/user')->assertOk()->assertExactJson(['data' => [
            'id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'preferred_language' => 'en',
        ]]);
        $this->assertArrayNotHasKey('session_version', $user->toArray());
        $this->assertArrayNotHasKey('password', $user->toArray());
    }

    public function test_logout_invalidates_session_regenerates_csrf_and_preserves_language(): void
    {
        $user = User::factory()->create();
        $this->post('/login', $this->credentials($user));
        $this->withSession(['private-marker' => 'sample']);
        $previousId = session()->getId();
        $previousToken = session()->token();

        $this->post('/logout', ['language' => 'lo'])->assertRedirect('/login');

        $this->assertGuest();
        $this->assertNotSame($previousId, session()->getId());
        $this->assertNotSame($previousToken, session()->token());
        $this->assertNull(session('private-marker'));
        $this->assertNull(session('auth.session_version'));
        $this->assertSame('lo', session('language'));
        $this->assertSame('lo', $user->refresh()->preferred_language);
        $this->getJson('/api/v1/user')->assertUnauthorized();
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_deactivation_revokes_an_existing_session_even_after_reactivation(bool $reactivate): void
    {
        $user = User::factory()->create();
        $this->post('/login', $this->credentials($user));
        $originalVersion = $user->session_version;

        $user->deactivate();
        if ($reactivate) {
            $user->forceFill(['active' => true])->save();
        }

        $this->assertNotSame($originalVersion, $user->session_version);
        $this->getJson('/api/v1/user')->assertUnauthorized();
        $this->assertGuest();
        $this->assertNull(session('auth.session_version'));
    }

    public function test_active_flag_is_checked_even_if_deactivation_bypasses_model_events(): void
    {
        $user = User::factory()->create();
        $this->post('/login', $this->credentials($user));

        User::query()->whereKey($user->id)->update(['active' => false]);

        $this->get('/dashboard')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_password_changes_revoke_existing_sessions(): void
    {
        $user = User::factory()->create();
        $this->post('/login', $this->credentials($user));

        $user->update(['password' => 'A new test-only password']);

        $this->getJson('/api/v1/user')->assertUnauthorized();
    }

    public function test_missing_session_revocation_state_is_not_accepted(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/v1/user')->assertUnauthorized();
    }

    public function test_login_and_logout_reject_cross_site_requests_without_csrf_tokens(): void
    {
        $user = User::factory()->create();
        $this->app['env'] = 'local';

        $this->post('/login', $this->credentials($user))->assertStatus(419);
        $this->assertGuest();
        $this->withSession(['_token' => 'known-csrf-token']);
        $this->post('/login', $this->credentials($user) + ['_token' => 'known-csrf-token'])->assertRedirect('/dashboard');
        $this->post('/logout')->assertStatus(419);
        $this->assertAuthenticatedAs($user);
    }

    public function test_no_public_registration_recovery_or_user_mutation_routes_are_available(): void
    {
        foreach (['/register', '/forgot-password', '/reset-password', '/users', '/api/v1/users'] as $path) {
            $this->postJson($path, [])->assertNotFound();
        }
        $this->get('/logout')->assertMethodNotAllowed();
    }

    public function test_authenticated_users_are_redirected_away_from_login(): void
    {
        $user = User::factory()->create();
        $this->post('/login', $this->credentials($user));

        $this->get('/login')->assertRedirect('/dashboard');
    }
}
