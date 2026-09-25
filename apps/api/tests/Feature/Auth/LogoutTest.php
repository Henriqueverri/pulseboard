<?php

namespace Tests\Feature\Auth;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class LogoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_logout_invalidates_session(): void
    {
        $user = User::factory()->create([
            'email' => 'ada@example.com',
            'password' => 'password123',
        ]);

        $organization = Organization::factory()->create();
        $organization->users()->attach($user->id, [
            'role' => Organization::ROLE_OWNER,
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'ada@example.com',
            'password' => 'password123',
        ])->assertOk();

        $this->assertAuthenticatedAs($user, 'web');

        $this->postJson('/api/v1/auth/logout')
            ->assertOk()
            ->assertJsonPath('message', 'Logged out.');

        $this->assertGuest('web');

        // Sanctum's RequestGuard caches the user for the app lifetime in tests;
        // forget guards so the next request re-resolves from the (invalidated) session.
        Auth::forgetGuards();

        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
    }
}
