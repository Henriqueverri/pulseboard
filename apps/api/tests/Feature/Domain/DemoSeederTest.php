<?php

namespace Tests\Feature\Domain;

use App\Enums\ProductStatus;
use App\Enums\TransactionStatus;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\User;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
    }

    public function test_seeds_users_and_a_single_demo_organization(): void
    {
        $organization = Organization::query()->sole();

        $this->assertSame(2, User::query()->count());
        $this->assertSame(Organization::ROLE_OWNER, User::query()->where('email', 'test@example.com')->sole()->roleIn($organization));
        $this->assertSame(Organization::ROLE_MEMBER, User::query()->where('email', 'member@example.com')->sole()->roleIn($organization));
    }

    public function test_seeds_a_realistically_sized_catalog_and_history(): void
    {
        $this->assertSame(40, Product::query()->count());
        $this->assertSame(4, Product::query()->where('status', ProductStatus::Inactive)->count());
        $this->assertSame(70, Customer::query()->count());

        $transactions = Transaction::query()->count();
        $this->assertGreaterThanOrEqual(300, $transactions);
        $this->assertLessThanOrEqual(800, $transactions);
        $this->assertGreaterThan($transactions, TransactionItem::query()->count());

        $oldest = Transaction::query()->min('occurred_at');
        $this->assertGreaterThanOrEqual(now()->subDays(DemoDataSeeder::HISTORY_DAYS)->startOfDay(), $oldest);
        $this->assertLessThanOrEqual(now(), Transaction::query()->max('occurred_at'));
    }

    public function test_status_mix_is_mostly_paid_with_every_status_present(): void
    {
        $counts = Transaction::query()
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        foreach (TransactionStatus::cases() as $status) {
            $this->assertGreaterThan(0, $counts[$status->value] ?? 0, "Missing {$status->value} transactions");
        }

        $this->assertGreaterThan(0.8, $counts[TransactionStatus::Paid->value] / $counts->sum());
    }

    public function test_totals_are_consistent_with_items(): void
    {
        $mismatches = Transaction::query()
            ->withSum('items', 'line_total')
            ->get()
            ->filter(fn (Transaction $t) => abs((float) $t->total_amount - (float) $t->items_sum_line_total) > 0.001);

        $this->assertCount(0, $mismatches);
    }

    public function test_inactive_products_are_only_sold_before_deactivation(): void
    {
        $recentInactiveSales = TransactionItem::query()
            ->whereHas('product', fn ($q) => $q->where('status', ProductStatus::Inactive))
            ->whereHas('transaction', fn ($q) => $q->where('occurred_at', '>=', now()->subDays(30)->startOfDay()))
            ->count();

        $this->assertSame(0, $recentInactiveSales);
        $this->assertGreaterThan(0, TransactionItem::query()
            ->whereHas('product', fn ($q) => $q->where('status', ProductStatus::Inactive))
            ->count());
    }

    public function test_older_items_keep_price_snapshots_after_price_changes(): void
    {
        $snapshotsBelowCurrentPrice = TransactionItem::query()
            ->join('products', 'products.id', '=', 'transaction_items.product_id')
            ->whereColumn('transaction_items.unit_price', '<', 'products.price')
            ->count();

        $this->assertGreaterThan(0, $snapshotsBelowCurrentPrice);
    }

    public function test_customers_have_different_purchase_volumes(): void
    {
        $perCustomer = Transaction::query()
            ->select('customer_id', DB::raw('count(*) as total'))
            ->groupBy('customer_id')
            ->pluck('total');

        $this->assertGreaterThanOrEqual(3 * $perCustomer->min(), $perCustomer->max());
    }
}
