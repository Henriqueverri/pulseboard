<?php

namespace Tests\Feature\Auth;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MeTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_fetch_me(): void
    {
        $user = User::factory()->create([
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
        ]);

        $organization = Organization::factory()->create([
            'name' => "Ada Lovelace's Organization",
        ]);
        $organization->users()->attach($user->id, [
            'role' => Organization::ROLE_OWNER,
        ]);

        $response = $this->actingAs($user)->getJson('/api/v1/auth/me');

        $response->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.email', 'ada@example.com')
            ->assertJsonPath('organizations.0.id', $organization->id)
            ->assertJsonPath('organizations.0.role', Organization::ROLE_OWNER)
            ->assertJsonPath('current_organization.id', $organization->id);
    }

    public function test_unauthenticated_user_cannot_fetch_me(): void
    {
        $response = $this->getJson('/api/v1/auth/me');

        $response->assertUnauthorized();
    }
}
