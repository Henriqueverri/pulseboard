<?php

namespace Database\Seeders;

use App\Enums\ProductStatus;
use App\Enums\TransactionStatus;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\TransactionItem;
use Carbon\CarbonImmutable;
use Database\Factories\ProductFactory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Deterministic ~90-day sales history for the dashboard demo.
 *
 * Shape of the data (so KPIs and charts have something to show):
 * - revenue grows ~50% across the period, weekends are weaker;
 * - a promo week (~5 weeks ago) spikes volume, a short dip happens ~2 months ago;
 * - products and customers follow long-tail popularity (top sellers, VIP buyers);
 * - some products were deactivated 30 days ago and only appear in older sales;
 * - some prices rose 45 days ago, so older items keep the old unit_price snapshot;
 * - most transactions are paid, recent ones may be pending, a few are refunded/canceled.
 *
 * It also runs in production (`pulseboard:demo`), where Faker and model factories
 * (require-dev) must not be needed: every value comes from the lists below and mt_rand().
 */
class DemoDataSeeder extends Seeder
{
    public const HISTORY_DAYS = 90;

    private const RANDOM_SEED = 20260928;

    private const FIRST_NAMES = [
        'Ana', 'Bruno', 'Camila', 'Diego', 'Eduarda', 'Felipe', 'Gabriela', 'Henrique', 'Isabela', 'João',
        'Larissa', 'Marcos', 'Natália', 'Otávio', 'Patrícia', 'Rafael', 'Sofia', 'Thiago', 'Vanessa', 'Yuri',
    ];

    private const LAST_NAMES = [
        'Almeida', 'Barbosa', 'Cardoso', 'Costa', 'Ferreira', 'Gomes', 'Lima', 'Martins', 'Mendes', 'Moreira',
        'Nascimento', 'Oliveira', 'Pereira', 'Ribeiro', 'Rocha', 'Santos', 'Silva', 'Souza', 'Teixeira', 'Vieira',
    ];

    private const DEACTIVATED_DAYS_AGO = 30;

    private const PRICE_CHANGE_DAYS_AGO = 45;

    private const PRICE_INCREASE = 1.08;

    public function run(Organization $organization): void
    {
        mt_srand(self::RANDOM_SEED);

        $today = CarbonImmutable::today();

        DB::transaction(function () use ($organization, $today): void {
            $products = $this->seedProducts($organization, $today);
            $customers = $this->seedCustomers($organization, $today);

            $this->seedTransactions($organization, $today, $products, $customers);
        });
    }

    /**
     * @return Collection<int, array{model: Product, weight: float, raised: bool}>
     */
    private function seedProducts(Organization $organization, CarbonImmutable $today): Collection
    {
        $products = collect();
        $createdAt = $today->subDays(self::HISTORY_DAYS + 30);

        $popularityRanks = range(1, count(ProductFactory::CATALOG) * 2);
        shuffle($popularityRanks);

        foreach (ProductFactory::CATALOG as $index => [$name, $min, $max]) {
            foreach (['Essential' => $min, 'Pro' => ($min + $max) / 2] as $variant => $price) {
                $position = $products->count();
                $inactive = in_array($position, [7, 15, 26, 33], true);

                $product = new Product;
                $product->forceFill([
                    'organization_id' => $organization->id,
                    'name' => "{$name} {$variant}",
                    'sku' => sprintf('PB-%03d-%s', $index + 1, strtoupper(substr($variant, 0, 3))),
                    'price' => $this->retailPrice($price),
                    'status' => $inactive ? ProductStatus::Inactive : ProductStatus::Active,
                    'created_at' => $createdAt,
                    'updated_at' => $inactive ? $today->subDays(self::DEACTIVATED_DAYS_AGO) : $createdAt,
                ])->save();

                $products->push([
                    'model' => $product,
                    'weight' => 1 / $popularityRanks[$position] ** 0.9,
                    'raised' => $position % 3 === 0,
                ]);
            }
        }

        return $products;
    }

    /**
     * @return Collection<int, array{model: Customer, weight: float}>
     */
    private function seedCustomers(Organization $organization, CarbonImmutable $today): Collection
    {
        $tiers = [
            ['count' => 8, 'weight' => 12.0],
            ['count' => 22, 'weight' => 4.0],
            ['count' => 40, 'weight' => 1.0],
        ];

        $customers = collect();

        foreach ($tiers as $tier) {
            for ($i = 0; $i < $tier['count']; $i++) {
                $createdAt = $today->subDays(mt_rand(self::HISTORY_DAYS + 5, self::HISTORY_DAYS + 200));
                $first = self::FIRST_NAMES[mt_rand(0, count(self::FIRST_NAMES) - 1)];
                $last = self::LAST_NAMES[mt_rand(0, count(self::LAST_NAMES) - 1)];

                $customer = new Customer;
                $customer->forceFill([
                    'organization_id' => $organization->id,
                    'name' => "{$first} {$last}",
                    'email' => sprintf('%s.%s.%02d@example.com', Str::slug($first), Str::slug($last), $customers->count() + 1),
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ])->save();

                $customers->push(['model' => $customer, 'weight' => $tier['weight']]);
            }
        }

        return $customers;
    }

    /**
     * @param  Collection<int, array{model: Product, weight: float, raised: bool}>  $products
     * @param  Collection<int, array{model: Customer, weight: float}>  $customers
     */
    private function seedTransactions(
        Organization $organization,
        CarbonImmutable $today,
        Collection $products,
        Collection $customers,
    ): void {
        for ($daysAgo = self::HISTORY_DAYS - 1; $daysAgo >= 0; $daysAgo--) {
            $day = $today->subDays($daysAgo);

            for ($n = 0; $n < $this->transactionsForDay($day, $daysAgo); $n++) {
                $occurredAt = $day->setTime($this->pickHour(), mt_rand(0, 59), mt_rand(0, 59));

                if ($occurredAt->isFuture()) {
                    $occurredAt = $today->setTime(0, mt_rand(0, 59));
                }

                $customer = $this->pickWeighted($customers)['model'];

                $transaction = new Transaction;
                $transaction->forceFill([
                    'organization_id' => $organization->id,
                    'customer_id' => $customer->id,
                    'status' => $this->pickStatus($daysAgo),
                    'occurred_at' => $occurredAt,
                    'created_at' => $occurredAt,
                    'updated_at' => $occurredAt,
                ])->save();

                $this->seedItems($transaction, $products, $daysAgo);
            }
        }
    }

    /**
     * @param  Collection<int, array{model: Product, weight: float, raised: bool}>  $products
     */
    private function seedItems(Transaction $transaction, Collection $products, int $daysAgo): void
    {
        $available = $products->filter(
            fn (array $product) => $product['model']->status === ProductStatus::Active
                || $daysAgo > self::DEACTIVATED_DAYS_AGO
        );

        $lines = $this->pickFrom([1 => 55, 2 => 30, 3 => 15]);
        $chosen = [];

        while (count($chosen) < $lines) {
            $candidate = $this->pickWeighted($available);
            $chosen[$candidate['model']->id] = $candidate;
        }

        foreach ($chosen as $product) {
            $unitPrice = $product['model']->price;

            if ($product['raised'] && $daysAgo > self::PRICE_CHANGE_DAYS_AGO) {
                $unitPrice = $this->retailPrice((float) $unitPrice / self::PRICE_INCREASE);
            }

            TransactionItem::query()->forceCreate([
                'transaction_id' => $transaction->id,
                'product_id' => $product['model']->id,
                'quantity' => $this->pickFrom([1 => 65, 2 => 25, 3 => 10]),
                'unit_price' => $unitPrice,
                'created_at' => $transaction->occurred_at,
                'updated_at' => $transaction->occurred_at,
            ]);
        }
    }

    private function transactionsForDay(CarbonImmutable $day, int $daysAgo): int
    {
        $trend = 1 + 0.5 * (self::HISTORY_DAYS - 1 - $daysAgo) / (self::HISTORY_DAYS - 1);

        $weekday = match (true) {
            $day->isSunday() => 0.6,
            $day->isSaturday() => 0.8,
            default => 1.0,
        };

        $event = match (true) {
            $daysAgo >= 32 && $daysAgo <= 38 => 2.2,
            $daysAgo >= 56 && $daysAgo <= 60 => 0.5,
            default => 1.0,
        };

        return max(0, (int) round(4 * $trend * $weekday * $event + mt_rand(-10, 10) / 10));
    }

    private function retailPrice(float $amount): string
    {
        return number_format(floor($amount) + 0.9, 2, '.', '');
    }

    private function pickStatus(int $daysAgo): TransactionStatus
    {
        $weights = $daysAgo <= 3
            ? ['paid' => 70, 'pending' => 25, 'canceled' => 5]
            : ['paid' => 88, 'pending' => 1, 'refunded' => 6, 'canceled' => 5];

        return TransactionStatus::from($this->pickFrom($weights));
    }

    private function pickHour(): int
    {
        return (int) $this->pickFrom([
            8 => 2, 9 => 4, 10 => 6, 11 => 7, 12 => 5, 13 => 5, 14 => 7,
            15 => 7, 16 => 6, 17 => 5, 18 => 5, 19 => 6, 20 => 7, 21 => 5, 22 => 3,
        ]);
    }

    /**
     * @param  array<int|string, int>  $weights
     */
    private function pickFrom(array $weights): int|string
    {
        $roll = mt_rand(1, array_sum($weights));

        foreach ($weights as $value => $weight) {
            $roll -= $weight;

            if ($roll <= 0) {
                return $value;
            }
        }

        return array_key_last($weights);
    }

    /**
     * @template T of array{weight: float}
     *
     * @param  Collection<int, T>  $items
     * @return T
     */
    private function pickWeighted(Collection $items): array
    {
        $roll = mt_rand() / mt_getrandmax() * $items->sum('weight');

        foreach ($items as $item) {
            $roll -= $item['weight'];

            if ($roll <= 0) {
                return $item;
            }
        }

        return $items->last();
    }
}
