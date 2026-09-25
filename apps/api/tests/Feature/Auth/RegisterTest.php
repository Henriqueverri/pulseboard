<?php

namespace Tests\Feature\Auth;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RegisterTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_creates_user_organization_and_owner_membership(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertCreated()
            ->assertJsonPath('user.email', 'ada@example.com')
            ->assertJsonPath('organization.role', Organization::ROLE_OWNER)
            ->assertJsonMissingPath('token')
            ->assertJsonMissingPath('user.password');

        $this->assertDatabaseHas('users', [
            'email' => 'ada@example.com',
            'name' => 'Ada Lovelace',
        ]);

        $user = User::query()->where('email', 'ada@example.com')->first();
        $this->assertNotNull($user);
        $this->assertAuthenticatedAs($user);

        $this->assertDatabaseCount('organizations', 1);
        $organization = Organization::query()->first();
        $this->assertNotNull($organization);
        $this->assertSame("Ada Lovelace's Organization", $organization->name);
        $this->assertSame('BRL', $organization->currency);

        $this->assertDatabaseHas('organization_user', [
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => Organization::ROLE_OWNER,
        ]);

        $this->assertSame(1, DB::table('organization_user')->count());
    }

    public function test_register_rejects_duplicate_email(): void
    {
        User::factory()->create(['email' => 'ada@example.com']);

        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }
}
