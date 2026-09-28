<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationTimezoneTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_organizations_default_to_sao_paulo(): void
    {
        $organization = Organization::query()->create(['name' => 'Acme', 'slug' => 'acme']);

        $this->assertSame('America/Sao_Paulo', $organization->timezone);
        $this->assertSame('America/Sao_Paulo', $organization->fresh()->timezone);
    }

    public function test_registration_creates_organization_in_default_timezone(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Ana',
            'email' => 'ana@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])
            ->assertCreated()
            ->assertJsonPath('organization.timezone', 'America/Sao_Paulo');
    }

    public function test_timezone_is_exposed_in_me_and_organization(): void
    {
        $organization = Organization::factory()->timezone('Europe/Lisbon')->create();
        $user = User::factory()->create();
        $organization->users()->attach($user->id, ['role' => Organization::ROLE_OWNER]);

        $this->actingAs($user)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('current_organization.timezone', 'Europe/Lisbon');

        $this->actingAs($user)
            ->withHeader('X-Organization-Id', $organization->id)
            ->getJson('/api/v1/organization')
            ->assertOk()
            ->assertJsonPath('organization.timezone', 'Europe/Lisbon');
    }
}
