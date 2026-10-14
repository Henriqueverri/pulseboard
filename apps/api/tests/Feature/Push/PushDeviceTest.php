<?php

namespace Tests\Feature\Push;

use App\Models\Organization;
use App\Models\PushDevice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Api\Concerns\InteractsWithOrganizationApi;
use Tests\TestCase;

/**
 * The mobile app registers its Expo push token with its personal access token. The device
 * lives as long as that token: revoking it, letting it be replaced or deleting the user
 * removes the device too.
 */
class PushDeviceTest extends TestCase
{
    use InteractsWithOrganizationApi, RefreshDatabase;

    private const TOKEN = 'ExponentPushToken[xxxxxxxxxxxxxxxxxxxxxx]';

    private const PASSWORD = 'password';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->memberOf(Organization::factory()->create());
    }

    public function test_the_app_registers_its_token(): void
    {
        $this->freezeSecond();
        $accessToken = $this->issueToken($this->user);

        $this->asNativeClient($accessToken)
            ->postJson('/api/v1/devices/push-token', ['token' => self::TOKEN, 'platform' => 'android'])
            ->assertNoContent();

        $device = PushDevice::query()->sole();
        $this->assertSame($this->user->id, $device->user_id);
        $this->assertSame('android', $device->platform);
        $this->assertSame($this->user->tokens()->sole()->id, $device->personal_access_token_id);
        $this->assertTrue($device->last_seen_at->equalTo(now()));
    }

    public function test_registering_again_refreshes_the_device(): void
    {
        $accessToken = $this->issueToken($this->user);
        $this->asNativeClient($accessToken)
            ->postJson('/api/v1/devices/push-token', ['token' => self::TOKEN, 'platform' => 'android']);

        $this->travel(2)->days();
        $this->asNativeClient($accessToken)
            ->postJson('/api/v1/devices/push-token', ['token' => self::TOKEN, 'platform' => 'android'])
            ->assertNoContent();

        $this->assertTrue(PushDevice::query()->sole()->last_seen_at->isToday());
    }

    public function test_a_token_registered_by_someone_else_moves_to_the_current_user(): void
    {
        $previous = $this->memberOf(Organization::factory()->create());
        $this->asNativeClient($this->issueToken($previous))
            ->postJson('/api/v1/devices/push-token', ['token' => self::TOKEN, 'platform' => 'ios']);

        $this->asNativeClient($this->issueToken($this->user))
            ->postJson('/api/v1/devices/push-token', ['token' => self::TOKEN, 'platform' => 'ios'])
            ->assertNoContent();

        $this->assertSame($this->user->id, PushDevice::query()->sole()->user_id);
    }

    public function test_a_browser_session_cannot_register_a_device(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/v1/devices/push-token', ['token' => self::TOKEN, 'platform' => 'android'])
            ->assertStatus(400);

        $this->assertDatabaseCount('push_devices', 0);
    }

    public function test_registering_requires_authentication(): void
    {
        $this->asNativeClient()
            ->postJson('/api/v1/devices/push-token', ['token' => self::TOKEN, 'platform' => 'android'])
            ->assertUnauthorized();
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidPayloads(): array
    {
        return [
            'missing token' => [['platform' => 'android'], 'token'],
            'not an Expo token' => [['token' => 'fcm:abc123', 'platform' => 'android'], 'token'],
            'Expo token with spaces' => [['token' => 'ExponentPushToken[a b]', 'platform' => 'android'], 'token'],
            'missing platform' => [['token' => self::TOKEN], 'platform'],
            'unknown platform' => [['token' => self::TOKEN, 'platform' => 'web'], 'platform'],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('invalidPayloads')]
    public function test_invalid_registrations_are_rejected(array $payload, string $field): void
    {
        $this->asNativeClient($this->issueToken($this->user))
            ->postJson('/api/v1/devices/push-token', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);
    }

    public function test_the_app_unregisters_its_token(): void
    {
        $accessToken = $this->issueToken($this->user);
        $this->asNativeClient($accessToken)
            ->postJson('/api/v1/devices/push-token', ['token' => self::TOKEN, 'platform' => 'android']);

        $this->asNativeClient($accessToken)
            ->deleteJson('/api/v1/devices/push-token', ['token' => self::TOKEN])
            ->assertNoContent();
        // Idempotent.
        $this->asNativeClient($accessToken)
            ->deleteJson('/api/v1/devices/push-token', ['token' => self::TOKEN])
            ->assertNoContent();

        $this->assertDatabaseCount('push_devices', 0);
    }

    public function test_a_user_cannot_unregister_someone_elses_device(): void
    {
        $owner = $this->memberOf(Organization::factory()->create());
        $this->asNativeClient($this->issueToken($owner))
            ->postJson('/api/v1/devices/push-token', ['token' => self::TOKEN, 'platform' => 'android']);

        $this->asNativeClient($this->issueToken($this->user))
            ->deleteJson('/api/v1/devices/push-token', ['token' => self::TOKEN])
            ->assertNoContent();

        $this->assertSame($owner->id, PushDevice::query()->sole()->user_id);
    }

    public function test_signing_out_removes_the_device(): void
    {
        $accessToken = $this->issueToken($this->user);
        $this->asNativeClient($accessToken)
            ->postJson('/api/v1/devices/push-token', ['token' => self::TOKEN, 'platform' => 'android']);

        $this->asNativeClient($accessToken)->deleteJson('/api/v1/auth/tokens/current')->assertNoContent();

        $this->assertDatabaseCount('push_devices', 0);
    }

    public function test_logging_out_with_the_access_token_removes_the_device(): void
    {
        $accessToken = $this->issueToken($this->user);
        $this->asNativeClient($accessToken)
            ->postJson('/api/v1/devices/push-token', ['token' => self::TOKEN, 'platform' => 'android']);

        $this->asNativeClient($accessToken)->postJson('/api/v1/auth/logout')->assertOk();

        $this->assertDatabaseCount('push_devices', 0);
    }

    public function test_signing_in_again_on_the_same_device_drops_the_old_registration(): void
    {
        $this->asNativeClient($this->issueToken($this->user))
            ->postJson('/api/v1/devices/push-token', ['token' => self::TOKEN, 'platform' => 'android']);

        // Same device name: the previous access token (and its device) is replaced.
        $this->issueToken($this->user);

        $this->assertDatabaseCount('push_devices', 0);
    }

    private function issueToken(User $user): string
    {
        return $this->asNativeClient()
            ->postJson('/api/v1/auth/tokens', ['email' => $user->email, 'password' => self::PASSWORD, 'device_name' => 'Pixel 8 · a1b2'])
            ->assertCreated()
            ->json('token');
    }

    private function asNativeClient(?string $token = null): static
    {
        Auth::forgetGuards();
        $this->flushHeaders();

        return $token === null ? $this : $this->withToken($token);
    }
}
