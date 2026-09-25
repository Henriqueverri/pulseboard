<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Simulate Sanctum SPA requests from the Nuxt origin so session + CSRF apply.
        $this->withHeaders([
            'Origin' => 'http://localhost:3000',
            'Referer' => 'http://localhost:3000',
        ]);
    }
}
