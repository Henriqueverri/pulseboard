<?php

namespace Tests\Feature\Auth;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_with_valid_credentials_authenticates_user(): void
    {
        $user = User::factory()->create([
            'email' => 'ada@example.com',
            'password' => 'password123',
        ]);

        $organization = Organization::factory()->create();
        $organization->users()->attach($user->id, [
            'role' => Organization::ROLE_OWNER,
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'ada@example.com',
            'password' => 'password123',
        ]);

        $response->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('organization.id', $organization->id)
            ->assertJsonMissingPath('token');

        $this->assertAuthenticatedAs($user);
    }

    public function test_login_with_invalid_credentials_fails(): void
    {
        User::factory()->create([
            'email' => 'ada@example.com',
            'password' => 'password123',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'ada@example.com',
            'password' => 'wrong-password',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);

        $this->assertGuest();
    }
}
