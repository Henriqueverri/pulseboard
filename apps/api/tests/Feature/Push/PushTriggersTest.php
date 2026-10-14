<?php

namespace Tests\Feature\Push;

use App\Data\Push\PushMessage;
use App\Enums\TransactionStatus;
use App\Models\NotificationEvent;
use App\Models\Organization;
use App\Models\Product;
use App\Models\PushDevice;
use App\Models\User;
use App\Services\Push\FakePushClient;
use App\Services\Push\PushClient;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\Feature\Api\Concerns\InteractsWithIngestApi;
use Tests\Feature\Api\Concerns\InteractsWithOrganizationApi;
use Tests\TestCase;

/**
 * The integration's sales and stock updates become push alerts for the members' phones: a
 * product crossing its minimum and the daily revenue goal, each at most once per local day.
 */
class PushTriggersTest extends TestCase
{
    use InteractsWithIngestApi, InteractsWithOrganizationApi, RefreshDatabase;

    private const OWNER_TOKEN = 'ExponentPushToken[owner-phone]';

    private const MEMBER_TOKEN = 'ExponentPushToken[member-phone]';

    private Organization $organization;

    private string $plainTextKey;

    private FakePushClient $push;

    protected function setUp(): void
    {
        parent::setUp();

        // 18:00 in São Paulo: "today" is 2026-10-13 for the organization.
        $this->travelTo('2026-10-13 21:00:00');

        $this->organization = Organization::factory()->create(['currency' => 'BRL']);
        [, $this->plainTextKey] = $this->issueApiKey($this->organization);
        $this->registerDevice($this->memberOf($this->organization), self::OWNER_TOKEN);
        $this->registerDevice($this->memberOf($this->organization, Organization::ROLE_MEMBER), self::MEMBER_TOKEN);

        $this->push = app(PushClient::class);
    }

    public function test_a_product_reaching_its_minimum_alerts_every_member_once(): void
    {
        $product = $this->stockedProduct(10, 5);
        $otherOrganization = Organization::factory()->create();
        $this->registerDevice($this->memberOf($otherOrganization), 'ExponentPushToken[outsider]');

        $this->putStock(3, '2026-10-13T20:00:00Z')->assertOk();

        $this->assertEqualsCanonicalizing([self::OWNER_TOKEN, self::MEMBER_TOKEN], $this->sentTokens());
        $message = $this->push->sent[0];
        $this->assertSame('Estoque baixo', $message->title);
        $this->assertSame('Heineken Long Neck (3 un., mínimo 5)', $message->body);
        $this->assertSame([
            'url' => '/',
            'product_id' => $product->id,
            'organization_id' => $this->organization->id,
            'kind' => 'stock_low',
        ], $message->data);
        $this->assertDatabaseHas('notification_events', [
            'organization_id' => $this->organization->id,
            'kind' => NotificationEvent::KIND_STOCK_LOW,
            'dedupe_key' => $product->id.':2026-10-13',
            'recipients' => 2,
        ]);
    }

    public function test_running_out_is_its_own_alert(): void
    {
        $this->stockedProduct(3, 5);

        $this->putStock(0, '2026-10-13T20:00:00Z')->assertOk();

        $this->assertCount(2, $this->push->sent);
        $this->assertSame('Estoque zerado', $this->push->sent[0]->title);
        $this->assertSame('Heineken Long Neck está sem estoque.', $this->push->sent[0]->body);
        $this->assertSame('stock_out', $this->push->sent[0]->data['kind']);
    }

    public function test_a_product_crossing_its_minimum_again_on_the_same_day_is_not_announced_twice(): void
    {
        $this->stockedProduct(10, 5);

        $this->putStock(4, '2026-10-13T19:00:00Z');
        $this->putStock(20, '2026-10-13T19:10:00Z');
        $this->putStock(4, '2026-10-13T19:20:00Z');

        $this->assertCount(2, $this->push->sent);

        // The next local day (00:30 in São Paulo) it is news again.
        $this->travelTo('2026-10-14 03:30:00');
        $this->putStock(20, '2026-10-14T03:00:00Z');
        $this->putStock(4, '2026-10-14T03:10:00Z');

        $this->assertCount(4, $this->push->sent);
    }

    public function test_stock_changes_that_cross_nothing_are_silent(): void
    {
        Product::factory()->for($this->organization)->create(['sku' => 'BAR-HEI']);

        // Turning stock control on, even at zero, is configuration.
        $this->putStock(0, '2026-10-13T19:00:00Z')->assertOk();
        // Still out: no new crossing.
        $this->putStock(0, '2026-10-13T19:10:00Z')->assertOk();
        // Back above the minimum.
        $this->putStock(30, '2026-10-13T19:20:00Z')->assertOk();
        // Lower, but still above the minimum (5).
        $this->putStock(10, '2026-10-13T19:30:00Z')->assertOk();

        $this->assertSame([], $this->push->sent);
        $this->assertDatabaseCount('notification_events', 0);
    }

    public function test_the_daily_goal_is_announced_once_when_paid_revenue_reaches_it(): void
    {
        $this->organization->forceFill(['daily_revenue_goal' => '150.00'])->save();
        $product = Product::factory()->for($this->organization)->create();

        $this->ingestSale($product, 'order-1', '99.80')->assertCreated();
        $this->assertSame([], $this->push->sent);

        $this->ingestSale($product, 'order-2', '99.80')->assertCreated();
        $this->ingestSale($product, 'order-3', '99.80')->assertCreated();

        $this->assertEqualsCanonicalizing([self::OWNER_TOKEN, self::MEMBER_TOKEN], $this->sentTokens());
        $this->assertSame('Meta do dia batida', $this->push->sent[0]->title);
        $this->assertSame('R$ 199,60 em vendas pagas hoje (meta de R$ 150,00).', $this->push->sent[0]->body);
        $this->assertSame('/transactions', $this->push->sent[0]->data['url']);
        $this->assertDatabaseHas('notification_events', [
            'kind' => NotificationEvent::KIND_DAILY_GOAL,
            'dedupe_key' => '2026-10-13',
        ]);
    }

    public function test_only_paid_sales_of_today_count_towards_the_goal(): void
    {
        $this->organization->forceFill(['daily_revenue_goal' => '50.00'])->save();
        $product = Product::factory()->for($this->organization)->create();

        $this->ingestSale($product, 'order-pending', '99.80', 'pending')->assertCreated();
        $this->ingestSale($product, 'order-yesterday', '99.80', 'paid', '2026-10-12T15:00:00-03:00')->assertCreated();

        $this->assertSame([], $this->push->sent);

        // Paying the pending sale puts today's revenue over the goal.
        $this->asIntegration($this->plainTextKey)
            ->postJson('/api/v1/ingest/transactions/order-pending/status-changes', [
                'status' => 'paid',
                'occurred_at' => '2026-10-13T17:30:00-03:00',
            ])
            ->assertCreated();

        $this->assertCount(2, $this->push->sent);
        $this->assertSame('R$ 99,80 em vendas pagas hoje (meta de R$ 50,00).', $this->push->sent[0]->body);
    }

    public function test_without_a_goal_sales_send_nothing(): void
    {
        $product = Product::factory()->for($this->organization)->create();

        $this->ingestSale($product, 'order-1', '99.80')->assertCreated();

        $this->assertSame([], $this->push->sent);
    }

    public function test_a_token_expo_no_longer_knows_is_removed(): void
    {
        $this->push->unregistered = [self::MEMBER_TOKEN];
        $this->stockedProduct(10, 5);

        $this->putStock(3, '2026-10-13T20:00:00Z')->assertOk();

        $this->assertSame([self::OWNER_TOKEN], $this->sentTokens());
        $this->assertDatabaseMissing('push_devices', ['token' => self::MEMBER_TOKEN]);
        $this->assertDatabaseHas('push_devices', ['token' => self::OWNER_TOKEN]);
        $this->assertDatabaseHas('notification_events', ['kind' => 'stock_low', 'recipients' => 1]);
    }

    public function test_an_organization_without_devices_still_claims_the_alert(): void
    {
        PushDevice::query()->delete();
        $this->stockedProduct(10, 5);

        $this->putStock(3, '2026-10-13T20:00:00Z')->assertOk();

        $this->assertSame([], $this->push->sent);
        $this->assertDatabaseHas('notification_events', ['kind' => 'stock_low', 'recipients' => 0]);
    }

    public function test_a_failed_broadcast_does_not_stop_the_push(): void
    {
        Broadcast::extend('failing', fn () => new class extends Broadcaster
        {
            public function auth($request): mixed
            {
                return null;
            }

            public function validAuthenticationResponse($request, $result): mixed
            {
                return null;
            }

            public function broadcast(array $channels, $event, array $payload = []): void
            {
                throw new RuntimeException('Reverb is down.');
            }
        });
        config(['broadcasting.connections.failing' => ['driver' => 'failing'], 'broadcasting.default' => 'failing']);
        $this->stockedProduct(10, 5);

        $this->putStock(3, '2026-10-13T20:00:00Z')->assertOk();

        $this->assertCount(2, $this->push->sent);
    }

    private function registerDevice(User $user, string $token): void
    {
        $accessToken = $user->createToken('Pixel 8', ['*'], now()->addDay())->accessToken;

        PushDevice::query()->create([
            'user_id' => $user->id,
            'personal_access_token_id' => $accessToken->id,
            'token' => $token,
            'platform' => 'android',
            'last_seen_at' => now(),
        ]);
    }

    private function stockedProduct(int $quantity, int $min): Product
    {
        return Product::factory()->for($this->organization)->stocked($quantity, $min)->create([
            'sku' => 'BAR-HEI',
            'name' => 'Heineken Long Neck',
            'stock_updated_at' => '2026-10-13 18:00:00',
        ]);
    }

    private function putStock(int $quantity, string $occurredAt): TestResponse
    {
        return $this->asIntegration($this->plainTextKey)->putJson('/api/v1/ingest/products/BAR-HEI/stock', [
            'tracked' => true,
            'quantity' => $quantity,
            'min_quantity' => 5,
            'occurred_at' => $occurredAt,
        ]);
    }

    private function ingestSale(
        Product $product,
        string $externalId,
        string $total,
        string $status = 'paid',
        string $occurredAt = '2026-10-13T17:00:00-03:00',
    ): TestResponse {
        return $this->asIntegration($this->plainTextKey)->postJson('/api/v1/ingest/transactions', [
            'external_id' => $externalId,
            'status' => TransactionStatus::from($status)->value,
            'occurred_at' => $occurredAt,
            'currency' => 'BRL',
            'customer' => ['external_id' => 'customer-1', 'name' => 'Ana Lima', 'email' => 'ana@example.com'],
            'items' => [['sku' => $product->sku, 'quantity' => 2, 'unit_price' => number_format((float) $total / 2, 2, '.', '')]],
            'total_amount' => $total,
        ]);
    }

    /**
     * @return list<string>
     */
    private function sentTokens(): array
    {
        return array_map(fn (PushMessage $message): string => $message->token, $this->push->sent);
    }
}
