<?php

namespace Tests\Feature\Api\Realtime;

use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Api\Concerns\InteractsWithOrganizationApi;
use Tests\TestCase;

/**
 * Subscribing to `private-organization.{id}` goes through POST /api/v1/broadcasting/auth: the SPA
 * with its session cookie, the mobile app with its personal access token, and only members.
 */
class BroadcastAuthTest extends TestCase
{
    use InteractsWithOrganizationApi, RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        // The test environment broadcasts to `null`, which signs nothing: authorize with a real
        // Pusher-protocol broadcaster and register the channels on it.
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => 'test-app',
        ]);
        Broadcast::forgetDrivers();
        require base_path('routes/channels.php');

        $this->organization = Organization::factory()->create();
    }

    public function test_a_member_with_a_personal_access_token_is_authorized(): void
    {
        $token = $this->memberOf($this->organization)->createToken('Pixel 8', ['*'], now()->addDay())->plainTextToken;

        $response = $this->withoutHeaders(['Origin', 'Referer'])
            ->withToken($token)
            ->authorize($this->channelOf($this->organization))
            ->assertOk();

        $this->assertStringStartsWith('test-key:', $response->json('auth'));
    }

    public function test_a_member_with_the_spa_session_is_authorized(): void
    {
        $this->actingAs($this->memberOf($this->organization, Organization::ROLE_MEMBER))
            ->authorize($this->channelOf($this->organization))
            ->assertOk()
            ->assertJsonStructure(['auth']);
    }

    public function test_another_organization_is_forbidden(): void
    {
        $other = Organization::factory()->create();

        $this->actingAs($this->memberOf($this->organization))
            ->authorize($this->channelOf($other))
            ->assertForbidden();
    }

    public function test_a_removed_member_can_no_longer_subscribe(): void
    {
        $user = $this->memberOf($this->organization);
        $this->organization->users()->detach($user->id);

        $this->actingAs($user)
            ->authorize($this->channelOf($this->organization))
            ->assertForbidden();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function malformedChannels(): array
    {
        return [
            'not a uuid' => ['private-organization.1'],
            'another channel' => ['private-App.Models.User.1'],
        ];
    }

    #[DataProvider('malformedChannels')]
    public function test_unknown_channels_are_forbidden(string $channel): void
    {
        $this->actingAs($this->memberOf($this->organization))
            ->authorize($channel)
            ->assertForbidden();
    }

    public function test_a_guest_is_unauthenticated(): void
    {
        $this->authorize($this->channelOf($this->organization))->assertUnauthorized();
    }

    public function test_an_integration_api_key_cannot_subscribe(): void
    {
        $this->withoutHeaders(['Origin', 'Referer'])
            ->withToken('pb_Ab12Cd34Ef56_not-a-real-secret')
            ->authorize($this->channelOf($this->organization))
            ->assertUnauthorized();
    }

    private function channelOf(Organization $organization): string
    {
        return 'private-organization.'.$organization->id;
    }

    private function authorize(string $channel): TestResponse
    {
        return $this->postJson('/api/v1/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => $channel,
        ]);
    }
}
