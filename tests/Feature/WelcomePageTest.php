<?php

namespace Tests\Feature;

use Tests\TestCase;

class WelcomePageTest extends TestCase
{
    public function test_welcome_route_renders_the_placeholder_view(): void
    {
        $response = $this->get('/');

        $response->assertOk()
            ->assertViewIs('welcome')
            ->assertSeeHtml('data-testid="welcome-page"');
    }
}
