<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * '/' requires auth since 2026-10-27 (PLANNING_LOG.md) - an
     * unauthenticated request now redirects to the login page instead of
     * rendering the dashboard directly.
     */
    public function test_the_application_redirects_guests_to_login(): void
    {
        $response = $this->get('/');

        $response->assertRedirect('/login');
    }
}
