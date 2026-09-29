<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class TrustedProxiesTest extends TestCase
{
    use RefreshDatabase;

    private const LOGIN_ATTEMPTS_PER_MINUTE = 6;

    public function test_forwarded_client_ip_is_ignored_by_default(): void
    {
        for ($attempt = 1; $attempt <= self::LOGIN_ATTEMPTS_PER_MINUTE; $attempt++) {
            $this->failedLoginFrom("203.0.113.{$attempt}")->assertUnprocessable();
        }

        $this->failedLoginFrom('203.0.113.99')->assertTooManyRequests();
    }

    public function test_login_rate_limit_is_per_client_behind_a_trusted_proxy(): void
    {
        config(['trustedproxy.proxies' => '*']);

        for ($attempt = 1; $attempt <= self::LOGIN_ATTEMPTS_PER_MINUTE; $attempt++) {
            $this->failedLoginFrom('203.0.113.10')->assertUnprocessable();
        }

        $this->failedLoginFrom('203.0.113.10')->assertTooManyRequests();
        $this->failedLoginFrom('203.0.113.11')->assertUnprocessable();
    }

    public function test_forwarded_https_is_honoured_behind_a_trusted_proxy(): void
    {
        config(['trustedproxy.proxies' => '*']);

        $this->app['router']->get('/api/v1/__scheme', fn () => ['secure' => request()->secure()]);

        $this->getJson('/api/v1/__scheme', ['X-Forwarded-Proto' => 'https'])
            ->assertExactJson(['secure' => true]);
    }

    private function failedLoginFrom(string $clientIp): TestResponse
    {
        return $this->postJson(
            '/api/v1/auth/login',
            ['email' => 'nobody@example.com', 'password' => 'wrong-password'],
            ['X-Forwarded-For' => $clientIp],
        );
    }
}
