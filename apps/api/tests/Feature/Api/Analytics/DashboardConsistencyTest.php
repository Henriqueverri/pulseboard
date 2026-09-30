<?php

namespace Tests\Feature\Api\Analytics;

use App\Models\Organization;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Analytics\ReportingPeriod;
use App\Support\Money;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Checks the dashboard against the demo dataset using independent, row-level calculations.
 */
class DashboardConsistencyTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();

        $this->organization = Organization::query()->sole();
        $this->owner = User::query()->where('email', 'test@example.com')->sole();
    }

    public function test_kpis_match_the_paid_transactions_of_each_period(): void
    {
        $period = ReportingPeriod::lastDays(30, $this->organization->timezone);

        $response = $this->dashboard("from={$period->from()}&to={$period->to()}")->assertOk();

        foreach (['value' => $period, 'previous' => $period->previous()] as $key => $expectedPeriod) {
            $expected = $this->expectedKpis($expectedPeriod);

            $this->assertGreaterThan(0, $expected['orders'], "{$key} period should have seeded sales");
            $this->assertSame($expected['revenue'], $response->json("data.revenue.{$key}"), "revenue.{$key}");
            $this->assertSame($expected['orders'], $response->json("data.orders.{$key}"), "orders.{$key}");
            $this->assertSame($expected['average_order_value'], $response->json("data.average_order_value.{$key}"), "average_order_value.{$key}");
            $this->assertSame($expected['customers'], $response->json("data.customers.{$key}"), "customers.{$key}");
        }
    }

    public function test_orders_match_the_paid_transactions_listing_for_the_same_period(): void
    {
        $period = ReportingPeriod::lastDays(30, $this->organization->timezone);
        $query = "from={$period->from()}&to={$period->to()}";

        $orders = $this->dashboard($query)->json('data.orders.value');

        $this->actingAs($this->owner)
            ->withHeader('X-Organization-Id', $this->organization->id)
            ->getJson("/api/v1/transactions?status=paid&{$query}")
            ->assertOk()
            ->assertJsonPath('meta.total', $orders);
    }

    public function test_recent_revenue_reflects_the_seeded_growth_trend(): void
    {
        $recent = ReportingPeriod::lastDays(30, $this->organization->timezone);
        $historyStart = $recent->toDate()->subDays(DemoDataSeeder::HISTORY_DAYS - 1);
        $earliest = ReportingPeriod::fromDates(
            $historyStart->toDateString(),
            $historyStart->addDays(29)->toDateString(),
            $this->organization->timezone,
        );

        $recentRevenue = Money::toCents($this->dashboard("from={$recent->from()}&to={$recent->to()}")->json('data.revenue.value'));
        $earliestRevenue = Money::toCents($this->dashboard("from={$earliest->from()}&to={$earliest->to()}")->json('data.revenue.value'));

        $this->assertGreaterThan($earliestRevenue, $recentRevenue);
    }

    /**
     * @return array{revenue: string, orders: int, average_order_value: string|null, customers: int}
     */
    private function expectedKpis(ReportingPeriod $period): array
    {
        $paid = Transaction::query()
            ->where('organization_id', $this->organization->id)
            ->where('status', 'paid')
            ->where('occurred_at', '>=', $period->startUtc())
            ->where('occurred_at', '<', $period->endUtc())
            ->get(['customer_id', 'total_amount']);

        $cents = $paid->sum(fn (Transaction $transaction): int => Money::toCents($transaction->total_amount));
        $orders = $paid->count();

        return [
            'revenue' => Money::fromCents($cents),
            'orders' => $orders,
            'average_order_value' => $orders === 0 ? null : Money::fromCents((int) round($cents / $orders)),
            'customers' => $paid->pluck('customer_id')->unique()->count(),
        ];
    }

    private function dashboard(string $query): TestResponse
    {
        return $this->actingAs($this->owner)
            ->withHeader('X-Organization-Id', $this->organization->id)
            ->getJson("/api/v1/dashboard?{$query}");
    }
}
