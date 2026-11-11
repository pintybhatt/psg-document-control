<?php

namespace Tests\Feature;

use App\Authorization\UserProfile;
use App\Models\AuthorizationScope;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Concerns\InteractsWithRbac;
use Tests\TestCase;

class UserProfileTest extends TestCase
{
    use InteractsWithRbac, LazilyRefreshDatabase;

    public function test_profile_lists_only_the_users_active_roles_and_granted_permissions(): void
    {
        $this->definitions();
        $site = Site::factory()->create();
        $scope = AuthorizationScope::context('site', $site->company_id, $site->id)->persist();
        $user = User::factory()->create();
        $other = User::factory()->create();
        $role = $this->grant($user, $scope, ['reports.view', 'documents.metadata.view', 'documents.view']);
        $inactive = $this->grant($user, $scope, ['numbering.manage']);
        $inactive->update(['active' => false]);
        $this->grant($other, $scope, ['users.manage']);

        $profile = app(UserProfile::class)->for($user);

        $this->assertFalse($profile['system_administrator']);
        $this->assertSame([$role->code], array_column($profile['roles'], 'code'));
        $this->assertSame('site', $profile['roles'][0]['scope']['type']);
        $this->assertSame($site->name_en, $profile['roles'][0]['scope']['site']['name_en']);
        $this->assertSame(['documents.metadata.view', 'reports.view'], array_column($profile['permissions'], 'key'));
        $this->assertSame([], $profile['memberships']);
    }

    public function test_profile_page_embeds_the_signed_in_users_profile_only_on_that_page(): void
    {
        $this->withoutVite();
        $this->definitions();
        $user = User::factory()->create();
        $this->grant($user, AuthorizationScope::context('global'), ['reports.view']);
        $this->signIn($user);
        $props = fn (string $path): array => json_decode(html_entity_decode((string) str($this->get($path)->assertOk()->getContent())->between('data-props="', '"></div>')), true);

        $profile = $props('/profile');
        $this->assertSame('profile', $profile['page']);
        $this->assertSame('/profile', $profile['auth']['profileUrl']);
        $this->assertSame(['reports.view'], array_column($profile['profile']['permissions'], 'key'));
        $this->assertNull($props('/dashboard')['profile']);
    }

    public function test_profile_page_requires_sign_in(): void
    {
        $this->get('/profile')->assertRedirect();
    }
}
