<?php

namespace Tests\Feature\Ai;

use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Api\Concerns\InteractsWithOrganizationApi;
use Tests\TestCase;

class OrganizationInsightsTest extends TestCase
{
    use InteractsWithOrganizationApi, RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        config(['ai.enabled' => true]);
        $this->organization = Organization::factory()->create();
    }

    public function test_an_owner_enables_insights(): void
    {
        $owner = $this->memberOf($this->organization);

        $this->actingInOrganization($owner, $this->organization)
            ->putJson('/api/v1/organization/insights', ['enabled' => true])
            ->assertOk()
            ->assertJsonPath('organization.id', $this->organization->id)
            ->assertJsonPath('organization.role', 'owner')
            ->assertJsonPath('organization.insights', ['available' => true, 'enabled' => true]);

        $this->organization->refresh();
        $this->assertTrue($this->organization->insightsEnabled());
        $this->assertSame($owner->id, $this->organization->ai_insights_enabled_by);
    }

    public function test_enabling_twice_keeps_the_original_opt_in(): void
    {
        $first = $this->memberOf($this->organization);
        $second = $this->memberOf($this->organization);
        $this->organization->enableInsights($first);
        $enabledAt = $this->organization->refresh()->ai_insights_enabled_at;

        $this->travel(1)->hours();

        $this->actingInOrganization($second, $this->organization)
            ->putJson('/api/v1/organization/insights', ['enabled' => true])
            ->assertOk();

        $this->organization->refresh();
        $this->assertSame($first->id, $this->organization->ai_insights_enabled_by);
        $this->assertTrue($enabledAt->equalTo($this->organization->ai_insights_enabled_at));
    }

    public function test_an_owner_disables_insights(): void
    {
        $owner = $this->memberOf($this->organization);
        $this->organization->enableInsights($owner);

        $this->actingInOrganization($owner, $this->organization)
            ->putJson('/api/v1/organization/insights', ['enabled' => false])
            ->assertOk()
            ->assertJsonPath('organization.insights.enabled', false);

        $this->organization->refresh();
        $this->assertFalse($this->organization->insightsEnabled());
        $this->assertNull($this->organization->ai_insights_enabled_by);
    }

    public function test_a_member_cannot_change_the_opt_in(): void
    {
        $member = $this->memberOf($this->organization, Organization::ROLE_MEMBER);

        $this->actingInOrganization($member, $this->organization)
            ->putJson('/api/v1/organization/insights', ['enabled' => true])
            ->assertForbidden();

        $this->assertFalse($this->organization->refresh()->insightsEnabled());
    }

    public function test_the_kill_switch_blocks_enabling_but_never_opting_out(): void
    {
        config(['ai.enabled' => false]);
        $owner = $this->memberOf($this->organization);

        $this->actingInOrganization($owner, $this->organization)
            ->putJson('/api/v1/organization/insights', ['enabled' => true])
            ->assertStatus(503)
            ->assertExactJson(['message' => 'Insights are currently unavailable.', 'code' => 'ai_disabled']);

        $this->organization->enableInsights($owner);

        $this->actingInOrganization($owner, $this->organization)
            ->putJson('/api/v1/organization/insights', ['enabled' => false])
            ->assertOk()
            ->assertJsonPath('organization.insights', ['available' => false, 'enabled' => false]);
    }

    public function test_the_payload_is_validated_and_never_takes_an_organization(): void
    {
        $owner = $this->memberOf($this->organization);
        $other = Organization::factory()->create();

        $this->actingInOrganization($owner, $this->organization)
            ->putJson('/api/v1/organization/insights', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('enabled');

        $this->actingInOrganization($owner, $this->organization)
            ->putJson('/api/v1/organization/insights', ['enabled' => 'yes please'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('enabled');

        $this->actingInOrganization($owner, $this->organization)
            ->putJson('/api/v1/organization/insights', ['enabled' => true, 'organization_id' => $other->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('organization_id');

        $this->assertFalse($other->refresh()->insightsEnabled());
        $this->assertFalse($this->organization->refresh()->insightsEnabled());
    }

    public function test_another_tenant_cannot_be_changed(): void
    {
        $owner = $this->memberOf($this->organization);
        $other = Organization::factory()->create();

        $this->actingInOrganization($owner, $other)
            ->putJson('/api/v1/organization/insights', ['enabled' => true])
            ->assertForbidden();

        $this->assertFalse($other->refresh()->insightsEnabled());
    }

    public function test_authentication_and_organization_header_are_required(): void
    {
        $owner = $this->memberOf($this->organization);

        $this->putJson('/api/v1/organization/insights', ['enabled' => true])->assertUnauthorized();

        $this->actingAs($owner)
            ->putJson('/api/v1/organization/insights', ['enabled' => true])
            ->assertStatus(400);

        $this->assertFalse($this->organization->refresh()->insightsEnabled());
    }

    public function test_an_integration_bearer_token_is_a_401(): void
    {
        $this->withHeaders(['Authorization' => 'Bearer pb_Ab12Cd34Ef56_not-a-real-secret', 'X-Organization-Id' => $this->organization->id])
            ->putJson('/api/v1/organization/insights', ['enabled' => true])
            ->assertUnauthorized();
    }

    public function test_the_organization_exposes_insights_flags_to_the_front(): void
    {
        $owner = $this->memberOf($this->organization);

        $this->actingInOrganization($owner, $this->organization)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('current_organization.insights', ['available' => true, 'enabled' => false]);

        $this->organization->enableInsights($owner);
        config(['ai.enabled' => false]);

        $this->actingInOrganization($owner, $this->organization)
            ->getJson('/api/v1/organization')
            ->assertOk()
            ->assertJsonPath('organization.insights', ['available' => false, 'enabled' => true]);
    }
}
