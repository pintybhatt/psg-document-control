<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class UiComponentPreviewTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_preview_renders_in_local_and_testing_environments(): void
    {
        $this->withoutVite();
        $user = User::factory()->create();
        $this->actingAs($user)->withSession(['auth.session_version' => $user->session_version]);

        foreach (['local', 'testing'] as $environment) {
            $this->app['env'] = $environment;

            $this->get('/dev/ui-components')
                ->assertOk()
                ->assertViewIs('app')
                ->assertViewHas('page', 'ui-preview')
                ->assertSee('id="dcms-app"', false);
        }
    }

    public function test_preview_is_not_accessible_outside_development_environments(): void
    {
        foreach (['production', 'staging'] as $environment) {
            $this->app['env'] = $environment;

            $this->get('/dev/ui-components')->assertNotFound();
        }
    }
}
