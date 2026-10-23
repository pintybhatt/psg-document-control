<?php

namespace Tests\Feature;

use Tests\TestCase;

class ApplicationShellTest extends TestCase
{
    public function test_all_workspace_sections_render_the_vue_shell(): void
    {
        $this->withoutVite();

        $pages = [
            'dashboard',
            'incoming',
            'outgoing',
            'controlled-documents',
            'document-search',
            'reports',
            'administration',
        ];

        foreach ($pages as $page) {
            $this->get('/'.$page)
                ->assertOk()
                ->assertViewIs('app')
                ->assertViewHas('page', $page)
                ->assertSee('id="dcms-app"', false)
                ->assertSee('data-props=', false)
                ->assertSee('Phonesack Group');
        }
    }

    public function test_unregistered_workspace_paths_are_not_caught_by_the_shell(): void
    {
        $this->get('/incoming/register')->assertNotFound();
        $this->get('/unknown-section')->assertNotFound();
    }

    public function test_existing_welcome_page_remains_available(): void
    {
        $this->get('/')->assertOk()->assertViewIs('welcome');
    }
}
