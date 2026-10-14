<?php

namespace Tests\Feature\Push;

use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Api\Concerns\InteractsWithOrganizationApi;
use Tests\TestCase;

/**
 * The daily revenue goal is set by an owner of the current organization and exposed to every
 * member through the organization resource.
 */
class OrganizationNotificationsTest extends TestCase
{
    use InteractsWithOrganizationApi, RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
    }

    public function test_an_owner_sets_and_clears_the_daily_goal(): void
    {
        $owner = $this->memberOf($this->organization);

        $this->actingInOrganization($owner, $this->organization)
            ->putJson('/api/v1/organization/notifications', ['daily_revenue_goal' => '1500.5'])
            ->assertOk()
            ->assertJsonPath('organization.daily_revenue_goal', '1500.50')
            ->assertJsonPath('organization.role', Organization::ROLE_OWNER);

        $this->actingInOrganization($owner, $this->organization)
            ->getJson('/api/v1/auth/me')
            ->assertJsonPath('current_organization.daily_revenue_goal', '1500.50');

        $this->actingInOrganization($owner, $this->organization)
            ->putJson('/api/v1/organization/notifications', ['daily_revenue_goal' => null])
            ->assertOk()
            ->assertJsonPath('organization.daily_revenue_goal', null);

        $this->assertNull($this->organization->refresh()->daily_revenue_goal);
    }

    public function test_a_member_cannot_change_the_goal(): void
    {
        $member = $this->memberOf($this->organization, Organization::ROLE_MEMBER);

        $this->actingInOrganization($member, $this->organization)
            ->putJson('/api/v1/organization/notifications', ['daily_revenue_goal' => '100.00'])
            ->assertForbidden();

        $this->assertNull($this->organization->refresh()->daily_revenue_goal);
    }

    public function test_the_goal_only_changes_in_the_current_organization(): void
    {
        $owner = $this->memberOf($this->organization);
        $other = Organization::factory()->create();
        $other->users()->attach($owner->id, ['role' => Organization::ROLE_OWNER]);

        $this->actingInOrganization($owner, $this->organization)
            ->putJson('/api/v1/organization/notifications', ['daily_revenue_goal' => '100.00'])
            ->assertOk();

        $this->assertNull($other->refresh()->daily_revenue_goal);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidPayloads(): array
    {
        return [
            'missing' => [[], 'daily_revenue_goal'],
            'zero' => [['daily_revenue_goal' => '0'], 'daily_revenue_goal'],
            'negative' => [['daily_revenue_goal' => '-10'], 'daily_revenue_goal'],
            'three decimals' => [['daily_revenue_goal' => '10.123'], 'daily_revenue_goal'],
            'text' => [['daily_revenue_goal' => 'mil reais'], 'daily_revenue_goal'],
            'another organization' => [['daily_revenue_goal' => '10', 'organization_id' => 'x'], 'organization_id'],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('invalidPayloads')]
    public function test_invalid_goals_are_rejected(array $payload, string $field): void
    {
        $this->actingInOrganization($this->memberOf($this->organization), $this->organization)
            ->putJson('/api/v1/organization/notifications', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);
    }
}
