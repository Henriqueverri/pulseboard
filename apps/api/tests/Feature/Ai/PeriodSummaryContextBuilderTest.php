<?php

namespace Tests\Feature\Ai;

use App\Data\Ai\AiContext;
use App\Data\Ai\PeriodSummaryContext;
use App\Enums\ProductStatus;
use App\Enums\TransactionStatus;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Product;
use App\Services\Ai\Insights\PeriodSummaryContextBuilder;
use App\Support\Analytics\Granularity;
use App\Support\Analytics\ReportingPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Api\Concerns\InteractsWithOrganizationApi;
use Tests\TestCase;

class PeriodSummaryContextBuilderTest extends TestCase
{
    use InteractsWithOrganizationApi, RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-05 15:00:00');
        $this->organization = Organization::factory()->create(['name' => 'Loja Secreta', 'currency' => 'BRL']);
    }

    public function test_only_aggregates_and_ranked_products_reach_the_model(): void
    {
        $customer = Customer::factory()->for($this->organization)->create(['name' => 'Maria Compradora', 'email' => 'maria@example.test']);

        foreach (range(1, 7) as $n) {
            $product = Product::factory()->for($this->organization)->create(['name' => "Produto {$n}", 'sku' => "SKU-{$n}"]);
            $this->createTransaction($customer, [[$product, 1, (string) (100 * $n).'.00']], TransactionStatus::Paid, '2026-09-10 15:00:00');
        }

        $context = $this->build('2026-09-01', '2026-09-30');
        $sent = $context->promptInput();

        $this->assertSame(['product.1', 'product.2', 'product.3', 'product.4', 'product.5'], array_column($context->untrustedProducts, 'ref'));
        $this->assertSame('Produto 7', $context->untrustedProducts[0]['name']);
        $this->assertSame('SKU-7', $context->untrustedProducts[0]['sku']);
        $this->assertStringNotContainsString('Produto 2"', $sent, 'products beyond the top five are not sent');

        foreach (['Maria Compradora', 'maria@example.test', 'Loja Secreta', $customer->id, $this->organization->id] as $secret) {
            $this->assertStringNotContainsString($secret, $sent);
        }

        foreach (Product::query()->pluck('id') as $id) {
            $this->assertStringNotContainsString($id, $sent, 'products are identified by rank, not by id');
        }

        $this->assertSame('BRL', $context->data['currency']);
        $this->assertSame(['from' => '2026-09-01', 'to' => '2026-09-30', 'days' => 30], $context->data['period']);
        $this->assertSame(['from' => '2026-08-02', 'to' => '2026-08-31', 'days' => 30], $context->data['previous_period']);
        $this->assertSame('2800.00', $context->data['metrics']['kpi.revenue']['value']);
        $this->assertSame($context->caveats, $context->data['caveats']);
    }

    public function test_product_names_and_skus_are_cleaned_and_names_truncated(): void
    {
        $customer = Customer::factory()->for($this->organization)->create();
        $long = Product::factory()->for($this->organization)->create([
            'name' => "Linha\u{0007}um\nlinha\tdois \u{200B}".str_repeat('x', 120),
            'sku' => "SKU\u{200E}\nLONGO",
            'status' => ProductStatus::Inactive,
        ]);
        $this->createTransaction($customer, [[$long, 1, '10.00']], TransactionStatus::Paid, '2026-09-10 15:00:00');
        $long->delete();

        $product = $this->build('2026-09-01', '2026-09-30')->untrustedProducts[0];

        $this->assertSame(PeriodSummaryContextBuilder::PRODUCT_NAME_MAX_LENGTH, mb_strlen($product['name']));
        $this->assertStringStartsWith('Linha um linha dois xxx', $product['name']);
        $this->assertStringEndsWith('…', $product['name']);
        $this->assertSame('SKU LONGO', $product['sku']);
        $this->assertSame('inactive', $product['status']);
        $this->assertTrue($product['is_deleted']);
    }

    public function test_another_organization_never_contributes_to_the_context(): void
    {
        $other = Organization::factory()->create();
        $otherCustomer = Customer::factory()->for($other)->create();
        $otherProduct = Product::factory()->for($other)->create(['name' => 'CANARY-ORG-B-Product']);
        $this->createTransaction($otherCustomer, [[$otherProduct, 3, '999.00']], TransactionStatus::Paid, '2026-09-10 15:00:00');

        $context = $this->build('2026-09-01', '2026-09-30');

        $this->assertSame('0.00', $context->catalog->get('kpi.revenue')->comparison->value);
        $this->assertSame([], $context->untrustedProducts);
        $this->assertFalse($context->catalog->has('product.1'));
        $this->assertStringNotContainsString('CANARY', $context->promptInput());
        $this->assertSame(['no_sales', 'no_previous_data'], $context->caveats);
    }

    /**
     * @return array<string, array{0: string, 1: Granularity, 2: int}>
     */
    public static function granularities(): array
    {
        return [
            'one day' => ['2026-09-30', Granularity::Day, 1],
            '31 days' => ['2026-08-31', Granularity::Day, 31],
            '32 days' => ['2026-08-30', Granularity::Week, 6],
            '182 days' => ['2026-04-02', Granularity::Week, 27],
            '183 days' => ['2026-04-01', Granularity::Month, 6],
            '366 days' => ['2025-09-30', Granularity::Month, 13],
        ];
    }

    #[DataProvider('granularities')]
    public function test_the_series_granularity_keeps_the_context_compact(string $from, Granularity $expected, int $buckets): void
    {
        $context = $this->build($from, '2026-09-30');

        $this->assertSame($expected->value, $context->data['revenue_series']['granularity']);
        $this->assertCount($buckets, $context->data['revenue_series']['buckets']);
    }

    private function build(string $from, string $to): PeriodSummaryContext
    {
        return app(PeriodSummaryContextBuilder::class)->build(AiContext::for(
            $this->organization,
            null,
            ReportingPeriod::fromDates($from, $to, $this->organization->timezone),
        ));
    }
}
