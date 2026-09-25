<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class OrganizationContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_can_access_own_organization_context(): void
    {
        [$user, $organization] = $this->createMemberWithOrganization();

        $response = $this->actingAs($user)
            ->withHeader('X-Organization-Id', $organization->id)
            ->getJson('/api/v1/organization');

        $response->assertOk()
            ->assertJsonPath('organization.id', $organization->id)
            ->assertJsonPath('organization.role', Organization::ROLE_OWNER);
    }

    public function test_user_cannot_access_organization_without_membership(): void
    {
        [$user] = $this->createMemberWithOrganization();
        $foreignOrganization = Organization::factory()->create();

        $response = $this->actingAs($user)
            ->withHeader('X-Organization-Id', $foreignOrganization->id)
            ->getJson('/api/v1/organization');

        $response->assertForbidden()
            ->assertJsonPath('message', 'You do not have access to this organization.');
    }

    public function test_changing_organization_header_does_not_grant_access(): void
    {
        [$userA, $organizationA] = $this->createMemberWithOrganization('A');
        [, $organizationB] = $this->createMemberWithOrganization('B');

        $allowed = $this->actingAs($userA)
            ->withHeader('X-Organization-Id', $organizationA->id)
            ->getJson('/api/v1/organization');

        $allowed->assertOk()
            ->assertJsonPath('organization.id', $organizationA->id);

        $denied = $this->actingAs($userA)
            ->withHeader('X-Organization-Id', $organizationB->id)
            ->getJson('/api/v1/organization');

        $denied->assertForbidden();
    }

    public function test_membership_is_required_even_when_organization_exists(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();

        $this->assertFalse($user->belongsToOrganization($organization));

        $response = $this->actingAs($user)
            ->withHeader('X-Organization-Id', $organization->id)
            ->getJson('/api/v1/organization');

        $response->assertForbidden();
    }

    public function test_missing_organization_header_is_rejected(): void
    {
        [$user] = $this->createMemberWithOrganization();

        $response = $this->actingAs($user)
            ->getJson('/api/v1/organization');

        $response->assertStatus(400);
    }

    public function test_invalid_organization_uuid_is_rejected(): void
    {
        [$user] = $this->createMemberWithOrganization();

        $response = $this->actingAs($user)
            ->withHeader('X-Organization-Id', 'not-a-uuid')
            ->getJson('/api/v1/organization');

        $response->assertStatus(400);
    }

    public function test_unknown_organization_uuid_is_forbidden(): void
    {
        [$user] = $this->createMemberWithOrganization();

        $response = $this->actingAs($user)
            ->withHeader('X-Organization-Id', (string) Str::uuid())
            ->getJson('/api/v1/organization');

        $response->assertForbidden();
    }

    /**
     * @return array{0: User, 1: Organization}
     */
    private function createMemberWithOrganization(string $suffix = ''): array
    {
        $user = User::factory()->create([
            'name' => 'User '.$suffix,
            'email' => 'user'.Str::lower($suffix ?: 'default').'@example.com',
        ]);

        $organization = Organization::factory()->create([
            'name' => 'Org '.$suffix,
        ]);

        $organization->users()->attach($user->id, [
            'role' => Organization::ROLE_OWNER,
        ]);

        return [$user, $organization];
    }
}
