<?php

namespace Tests\Feature\Domain;

use App\Models\Customer;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Transaction;
use App\Support\Analytics\Granularity;
use App\Support\Analytics\LocalDateBucket;
use App\Support\Analytics\ReportingPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Api\Concerns\InteractsWithOrganizationApi;
use Tests\TestCase;

class LocalDateBucketTest extends TestCase
{
    use InteractsWithOrganizationApi, RefreshDatabase;

    private Customer $customer;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $organization = Organization::factory()->create();
        $this->customer = Customer::factory()->for($organization)->create();
        $this->product = Product::factory()->for($organization)->create();
    }

    public function test_sao_paulo_daily_buckets_use_the_local_calendar_day(): void
    {
        $period = ReportingPeriod::fromDates('2026-09-01', '2026-09-30', 'America/Sao_Paulo');

        // 23:30 local on Sep 10th, 00:30 local on Sep 11th, 20:59 local on Sep 12th.
        $this->transactionsAt(['2026-09-11 02:30:00', '2026-09-11 03:30:00', '2026-09-12 23:59:00']);

        $this->assertSame(
            ['2026-09-10' => 1, '2026-09-11' => 1, '2026-09-12' => 1],
            $this->countsByBucket(Granularity::Day, $period),
        );
    }

    public function test_sao_paulo_weekly_buckets_start_on_monday(): void
    {
        $period = ReportingPeriod::fromDates('2026-09-01', '2026-09-30', 'America/Sao_Paulo');

        // Sunday 23:30 local (Monday 02:30 UTC) still belongs to the week of Monday Sep 7th.
        $this->transactionsAt(['2026-09-14 02:30:00', '2026-09-07 03:00:00', '2026-09-14 03:00:00']);

        $this->assertSame(
            ['2026-09-07' => 2, '2026-09-14' => 1],
            $this->countsByBucket(Granularity::Week, $period),
        );
    }

    public function test_sao_paulo_monthly_buckets_use_the_local_month(): void
    {
        $period = ReportingPeriod::fromDates('2026-08-01', '2026-09-30', 'America/Sao_Paulo');

        // 22:00 local on Aug 31st is Sep 1st in UTC.
        $this->transactionsAt(['2026-09-01 01:00:00', '2026-09-01 03:00:00', '2026-09-20 12:00:00']);

        $this->assertSame(
            ['2026-08-01' => 1, '2026-09-01' => 2],
            $this->countsByBucket(Granularity::Month, $period),
        );
    }

    public function test_bucket_keys_match_the_generated_buckets(): void
    {
        $period = ReportingPeriod::fromDates('2026-09-02', '2026-09-16', 'America/Sao_Paulo');
        $this->transactionsAt(['2026-09-02 12:00:00', '2026-09-09 12:00:00', '2026-09-16 12:00:00']);

        foreach (Granularity::cases() as $granularity) {
            $keys = array_column($granularity->buckets($period), 'period');

            $this->assertSame([], array_diff(array_keys($this->countsByBucket($granularity, $period)), $keys), $granularity->value);
        }
    }

    public function test_postgres_buckets_follow_daylight_saving_time(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Exact DST bucketing needs the PostgreSQL timezone database.');
        }

        $period = ReportingPeriod::fromDates('2026-03-28', '2026-03-30', 'Europe/Lisbon');

        // Lisbon switches from UTC+0 to UTC+1 on 2026-03-29 at 01:00 UTC.
        $this->transactionsAt([
            '2026-03-28 23:30:00', // Mar 28th 23:30 WET
            '2026-03-29 23:30:00', // Mar 30th 00:30 WEST
            '2026-03-30 22:30:00', // Mar 30th 23:30 WEST
        ]);

        $this->assertSame(
            ['2026-03-28' => 1, '2026-03-30' => 2],
            $this->countsByBucket(Granularity::Day, $period),
        );
    }

    /**
     * @param  list<string>  $instants  UTC timestamps
     */
    private function transactionsAt(array $instants): void
    {
        foreach ($instants as $occurredAt) {
            $this->createTransaction($this->customer, [[$this->product, 1, '10.00']], occurredAt: $occurredAt);
        }
    }

    /**
     * @return array<string, int>
     */
    private function countsByBucket(Granularity $granularity, ReportingPeriod $period): array
    {
        [$bucket, $bindings] = LocalDateBucket::expression(DB::connection(), 'transactions.occurred_at', $granularity, $period);

        return Transaction::query()
            ->occurredWithin($period)
            ->toBase()
            ->selectRaw("{$bucket} as bucket, count(*) as total", $bindings)
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->pluck('total', 'bucket')
            ->map(fn ($total): int => (int) $total)
            ->all();
    }
}
