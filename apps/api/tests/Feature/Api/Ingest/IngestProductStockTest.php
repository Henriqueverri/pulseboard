<?php

namespace Tests\Feature\Api\Ingest;

use App\Models\Organization;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Api\Concerns\InteractsWithIngestApi;
use Tests\Feature\Api\Concerns\InteractsWithOrganizationApi;
use Tests\TestCase;

class IngestProductStockTest extends TestCase
{
    use InteractsWithIngestApi, InteractsWithOrganizationApi, RefreshDatabase;

    private Organization $organization;

    private string $plainTextKey;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        [, $this->plainTextKey] = $this->issueApiKey($this->organization);
        $this->product = Product::factory()->for($this->organization)->create(['sku' => 'BAR-HEI']);
    }

    public function test_the_first_update_turns_stock_control_on(): void
    {
        $this->putStock('BAR-HEI', $this->payload(quantity: 3, min: 6, at: '2026-10-13T20:00:00.123456-03:00'))
            ->assertOk()
            ->assertHeaderMissing('Idempotent-Replayed')
            ->assertExactJson(['data' => [
                'product_id' => $this->product->id,
                'sku' => 'BAR-HEI',
                'tracked' => true,
                'quantity' => 3,
                'min_quantity' => 6,
                'status' => 'low',
                'updated_at' => '2026-10-13T23:00:00.123456Z',
            ]]);

        $this->product->refresh();
        $this->assertTrue($this->product->stock_tracked);
        $this->assertSame(3, $this->product->stock_quantity);
        $this->assertSame(6, $this->product->stock_min);
    }

    /**
     * @return array<string, array{0: int, 1: int, 2: string}>
     */
    public static function statuses(): array
    {
        return [
            'above the minimum' => [7, 6, 'ok'],
            'at the minimum' => [6, 6, 'low'],
            'out of stock' => [0, 6, 'out'],
            'out of stock without a minimum' => [0, 0, 'out'],
        ];
    }

    #[DataProvider('statuses')]
    public function test_the_status_follows_the_balance_and_the_minimum(int $quantity, int $min, string $status): void
    {
        $this->putStock('BAR-HEI', $this->payload(quantity: $quantity, min: $min))
            ->assertOk()
            ->assertJsonPath('data.status', $status);
    }

    public function test_a_newer_update_replaces_the_stock(): void
    {
        $this->putStock('BAR-HEI', $this->payload(quantity: 10, at: '2026-10-13T20:00:00Z'))->assertOk();

        $this->putStock('BAR-HEI', $this->payload(quantity: 9, at: '2026-10-13T20:05:00Z'))
            ->assertOk()
            ->assertHeaderMissing('Idempotent-Replayed')
            ->assertJsonPath('data.quantity', 9);

        $this->assertSame(9, $this->product->refresh()->stock_quantity);
    }

    public function test_a_retry_of_the_same_update_is_replayed(): void
    {
        $payload = $this->payload(quantity: 10, at: '2026-10-13T20:00:00Z');

        $this->putStock('BAR-HEI', $payload)->assertOk();

        $this->putStock('BAR-HEI', $payload)
            ->assertOk()
            ->assertHeader('Idempotent-Replayed', 'true')
            ->assertJsonPath('data.quantity', 10);
    }

    public function test_an_update_older_than_the_stored_one_changes_nothing(): void
    {
        $this->putStock('BAR-HEI', $this->payload(quantity: 8, at: '2026-10-13T20:05:00Z'))->assertOk();

        $this->putStock('BAR-HEI', $this->payload(quantity: 10, at: '2026-10-13T20:00:00Z'))
            ->assertOk()
            ->assertHeader('Idempotent-Replayed', 'true')
            ->assertJsonPath('data.quantity', 8)
            ->assertJsonPath('data.updated_at', '2026-10-13T20:05:00.000000Z');

        $this->assertSame(8, $this->product->refresh()->stock_quantity);
    }

    /**
     * Two sales in the same second: the order still comes from the microseconds.
     */
    public function test_microseconds_order_updates_within_the_same_second(): void
    {
        $this->putStock('BAR-HEI', $this->payload(quantity: 8, at: '2026-10-13T20:00:00.500000Z'))->assertOk();

        $this->putStock('BAR-HEI', $this->payload(quantity: 9, at: '2026-10-13T20:00:00.400000Z'))
            ->assertHeader('Idempotent-Replayed', 'true');
        $this->putStock('BAR-HEI', $this->payload(quantity: 7, at: '2026-10-13T20:00:00.600000Z'))
            ->assertHeaderMissing('Idempotent-Replayed');

        $this->assertSame(7, $this->product->refresh()->stock_quantity);
    }

    public function test_turning_stock_control_off_is_an_update_too(): void
    {
        $this->putStock('BAR-HEI', $this->payload(quantity: 3, at: '2026-10-13T20:00:00Z'))->assertOk();

        $this->putStock('BAR-HEI', [...$this->payload(quantity: 3, at: '2026-10-13T21:00:00Z'), 'tracked' => false])
            ->assertOk()
            ->assertJsonPath('data.tracked', false)
            ->assertJsonPath('data.status', null);

        $this->assertFalse($this->product->refresh()->stock_tracked);
    }

    public function test_an_unknown_sku_is_a_422(): void
    {
        $this->putStock('NOPE-1', $this->payload())
            ->assertUnprocessable()
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonPath('errors.sku.0', 'The selected product SKU is invalid.');
    }

    public function test_a_deleted_product_is_unknown_and_keeps_its_stock(): void
    {
        $this->product->delete();

        $this->putStock('BAR-HEI', $this->payload(quantity: 5))
            ->assertUnprocessable()
            ->assertJsonPath('errors.sku.0', 'The selected product SKU is invalid.');

        $this->assertFalse(Product::withTrashed()->findOrFail($this->product->id)->stock_tracked);
    }

    public function test_a_key_only_reaches_products_of_its_own_organization(): void
    {
        $other = Organization::factory()->create();
        $foreign = Product::factory()->for($other)->create(['sku' => 'ONLY-THERE']);
        $twin = Product::factory()->for($other)->create(['sku' => 'BAR-HEI']);

        $this->putStock('ONLY-THERE', $this->payload())->assertUnprocessable();
        $this->putStock('BAR-HEI', $this->payload(quantity: 4))->assertOk()->assertJsonPath('data.product_id', $this->product->id);

        $this->assertFalse($foreign->refresh()->stock_tracked);
        $this->assertFalse($twin->refresh()->stock_tracked);
        $this->assertSame(4, $this->product->refresh()->stock_quantity);
    }

    public function test_requires_an_api_key(): void
    {
        $this->asIntegration(null)
            ->putJson('/api/v1/ingest/products/BAR-HEI/stock', $this->payload())
            ->assertUnauthorized();
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidPayloads(): array
    {
        $valid = ['tracked' => true, 'quantity' => 3, 'min_quantity' => 1, 'occurred_at' => '2026-10-13T20:00:00Z'];

        return [
            'missing tracked' => [array_diff_key($valid, ['tracked' => true]), 'tracked'],
            'tracked as a string' => [[...$valid, 'tracked' => 'true'], 'tracked'],
            'negative quantity' => [[...$valid, 'quantity' => -1], 'quantity'],
            'fractional quantity' => [[...$valid, 'quantity' => 1.5], 'quantity'],
            'missing minimum' => [array_diff_key($valid, ['min_quantity' => 1]), 'min_quantity'],
            'negative minimum' => [[...$valid, 'min_quantity' => -1], 'min_quantity'],
            'occurred at without offset' => [[...$valid, 'occurred_at' => '2026-10-13T20:00:00'], 'occurred_at'],
            'occurred at in the future' => [[...$valid, 'occurred_at' => '2099-01-01T00:00:00Z'], 'occurred_at'],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('invalidPayloads')]
    public function test_an_invalid_payload_is_a_422_that_changes_nothing(array $payload, string $field): void
    {
        $this->putStock('BAR-HEI', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonValidationErrors([$field]);

        $this->assertFalse($this->product->refresh()->stock_tracked);
    }

    public function test_every_outcome_is_logged(): void
    {
        Log::spy();

        $this->putStock('BAR-HEI', $this->payload(quantity: 3, at: '2026-10-13T20:00:00Z'));
        $this->putStock('BAR-HEI', $this->payload(quantity: 3, at: '2026-10-13T20:00:00Z'));
        $this->putStock('NOPE-1', $this->payload());

        foreach (['updated', 'replayed', 'rejected'] as $outcome) {
            Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context) => $context['event'] === 'ingest.product_stock'
                && $context['outcome'] === $outcome)->once();
        }
    }

    public function test_the_product_api_shows_the_stock_read_only(): void
    {
        $this->putStock('BAR-HEI', $this->payload(quantity: 3, min: 6))->assertOk();

        $owner = $this->memberOf($this->organization);

        $this->actingInOrganization($owner, $this->organization)
            ->getJson("/api/v1/products/{$this->product->id}")
            ->assertOk()
            ->assertJsonPath('data.stock.quantity', 3)
            ->assertJsonPath('data.stock.min_quantity', 6)
            ->assertJsonPath('data.stock.status', 'low');

        $this->actingInOrganization($owner, $this->organization)
            ->putJson("/api/v1/products/{$this->product->id}", [
                'name' => $this->product->name,
                'price' => '12.00',
                'stock_quantity' => 999,
            ])
            ->assertOk()
            ->assertJsonPath('data.stock.quantity', 3);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(int $quantity = 3, int $min = 0, string $at = '2026-10-13T20:00:00Z'): array
    {
        return ['tracked' => true, 'quantity' => $quantity, 'min_quantity' => $min, 'occurred_at' => $at];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function putStock(string $sku, array $payload): TestResponse
    {
        return $this->asIntegration($this->plainTextKey)->putJson("/api/v1/ingest/products/{$sku}/stock", $payload);
    }
}
