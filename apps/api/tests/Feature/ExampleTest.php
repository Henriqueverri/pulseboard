<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_root_describes_the_api_without_starting_a_session(): void
    {
        $response = $this->get('/');

        $response->assertOk()
            ->assertJsonPath('app', config('app.name'))
            ->assertJsonPath('health', url('/api/v1/health'))
            ->assertCookieMissing(config('session.cookie'))
            ->assertCookieMissing('XSRF-TOKEN');
    }
}
