<?php

namespace Tests\Feature;

use Tests\TestCase;

class WelcomePageTest extends TestCase
{
    public function test_welcome_route_renders_the_package_landing_page(): void
    {
        $response = $this->get('/');

        $response->assertOk()
            ->assertViewIs('bfc::landing')
            ->assertSeeHtml('data-testid="landing"');
    }
}
