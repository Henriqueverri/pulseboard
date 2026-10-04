<?php

namespace Database\Seeders;

use App\Enums\ProductStatus;
use App\Enums\TransactionSource;
use App\Enums\TransactionStatus;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\TransactionStatusChange;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Closure;
use Database\Factories\ProductFactory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Deterministic ~6-month sales history for the dashboard demo, in the organization's timezone.
 *
 * Shape of the data (so KPIs and charts have something to show):
 * - revenue grows ~50% across the period, weekends are weaker;
 * - a promo week (~5 weeks ago) spikes volume, a short dip happens ~2 months ago;
 * - products and customers follow long-tail popularity (top sellers, VIP buyers);
 * - some products were deactivated 30 days ago and only appear in older sales;
 * - some prices rose 45 days ago, so older items keep the old unit_price snapshot;
 * - most transactions are paid, recent ones may be pending, a few are refunded/canceled;
 * - every transaction has source=seed and the status history that leads to its status;
 * - catalog and customers carry stable external ids (demo-prd-001-ESS, demo-cus-001).
 *
 * No API key is ever created here: the demo never ships a ready-made credential.
 *
 * extend() later appends the days between the latest sale and today, so the default
 * dashboard period keeps showing data long after the full seed.
 *
 * It also runs in production (`pulseboard:demo`), where Faker and model factories
 * (require-dev) must not be needed: every value comes from the lists below and mt_rand().
 * Days, hours and "today" are local to the organization; timestamps are stored in UTC.
 */
class DemoDataSeeder extends Seeder
{
    public const HISTORY_DAYS = 180;

    public const CATALOG_SKU_PREFIX = 'PB-';

    public const PRODUCT_EXTERNAL_ID_PREFIX = 'demo-prd-';

    public const CUSTOMER_EXTERNAL_ID_PREFIX = 'demo-cus-';

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

    private const PENDING_DAYS = 3;

    private const BASE_DAILY_ORDERS = 4;

    private const FINAL_TREND = 1.5;

    private const INSERT_CHUNK = 500;

    private const CUSTOMER_TIERS = [
        ['count' => 8, 'weight' => 12.0],
        ['count' => 22, 'weight' => 4.0],
        ['count' => 40, 'weight' => 1.0],
    ];

    private const PRODUCT_VARIANT_CODES = ['ESS', 'PRO'];

    /** @var list<array<string, mixed>> */
    private array $transactionRows = [];

    /** @var list<array<string, mixed>> */
    private array $itemRows = [];

    /** @var list<array<string, mixed>> */
    private array $statusChangeRows = [];

    public function run(Organization $organization): void
    {
        mt_srand(self::RANDOM_SEED);

        $today = self::today($organization);

        DB::transaction(function () use ($organization, $today): void {
            $products = $this->seedProducts($organization, $today);
            $customers = $this->seedCustomers($organization, $today);

            $this->seedTransactions($organization, $today, $products, $customers);
            $this->flush();
        });
    }

    /**
     * Appends sales for the days after the organization's latest transaction, up to today, and
     * returns how many transactions were created. Days that already have sales are never touched.
     *
     * Each day is generated from its own seed (slug + date), so the same starting point always
     * yields the same sales. Only the active, non-deleted demo catalog and non-deleted customers
     * sell, weighted by their past popularity, at today's prices and with settled statuses.
     * At most HISTORY_DAYS are appended; older gaps are left for `pulseboard:demo --refresh`.
     */
    public function extend(Organization $organization): int
    {
        $latest = Transaction::query()->forOrganization($organization)->max('occurred_at');

        if ($latest === null) {
            return 0;
        }

        $today = self::today($organization);
        $lastDay = CarbonImmutable::parse($latest, 'UTC')->setTimezone($organization->timezone)->startOfDay();
        $missingDays = min(self::HISTORY_DAYS, (int) round($lastDay->diffInDays($today)));

        if ($missingDays <= 0) {
            return 0;
        }

        $products = $this->popularProducts($organization);
        $customers = $this->popularCustomers($organization);

        if ($products->isEmpty() || $customers->isEmpty()) {
            return 0;
        }

        $created = 0;

        DB::transaction(function () use ($organization, $today, $missingDays, $products, $customers, &$created): void {
            for ($daysAgo = $missingDays - 1; $daysAgo >= 0; $daysAgo--) {
                $day = $today->subDays($daysAgo);

                mt_srand(crc32($organization->slug.'|'.$day->toDateString()));

                for ($n = 0; $n < $this->dailyOrders($day, self::FINAL_TREND); $n++) {
                    $this->createTransaction(
                        $organization,
                        $this->occurredAt($day),
                        $this->pickWeighted($customers)['model'],
                        $this->pickStatus(self::PENDING_DAYS + 1),
                        $products,
                        fn (array $product) => $product['model']->price,
                    );

                    $created++;
                }
            }

            $this->flush();
        });

        return $created;
    }

    /**
     * Start of the current day in the organization's timezone.
     */
    public static function today(Organization $organization): CarbonImmutable
    {
        return CarbonImmutable::now($organization->timezone)->startOfDay();
    }

    /**
     * Gives a demo seeded before external ids existed the same ids a fresh seed would assign, and
     * returns how many records were updated. Each id is derived from what the seed itself wrote:
     * the catalog SKU (PB-001-ESS -> demo-prd-001-ESS) and the customer email
     * (ana.lima.05@example.com -> demo-cus-005, names from the seed lists only).
     *
     * Safe to run on every start: only records without an external id are touched, an id already
     * in use is never assigned again, and when two records map to the same id the oldest wins
     * (seeded customers predate any visitor's). Nothing else changes, not even updated_at.
     */
    public function backfillExternalIds(Organization $organization): int
    {
        $products = Product::withTrashed()
            ->forOrganization($organization)
            ->whereNull('external_id')
            ->where('sku', 'like', self::CATALOG_SKU_PREFIX.'%')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['id', 'sku'])
            ->map(fn (Product $product) => ['id' => $product->id, 'external_id' => self::seededProductExternalId((string) $product->sku)]);

        $customers = Customer::withTrashed()
            ->forOrganization($organization)
            ->whereNull('external_id')
            ->where('email', 'like', '%@example.com')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['id', 'email'])
            ->map(fn (Customer $customer) => ['id' => $customer->id, 'external_id' => self::seededCustomerExternalId($customer->email)]);

        return $this->assignExternalIds($organization, Product::class, $products)
            + $this->assignExternalIds($organization, Customer::class, $customers);
    }

    public static function productExternalId(int $catalogNumber, string $variantCode): string
    {
        return sprintf('%s%03d-%s', self::PRODUCT_EXTERNAL_ID_PREFIX, $catalogNumber, $variantCode);
    }

    public static function customerExternalId(int $number): string
    {
        return sprintf('%s%03d', self::CUSTOMER_EXTERNAL_ID_PREFIX, $number);
    }

    /**
     * The external id of a product with a demo catalog SKU, or null for any other SKU.
     */
    private static function seededProductExternalId(string $sku): ?string
    {
        $pattern = sprintf(
            '/^%s(\d{3})-(%s)$/',
            preg_quote(self::CATALOG_SKU_PREFIX, '/'),
            implode('|', self::PRODUCT_VARIANT_CODES),
        );

        if (preg_match($pattern, $sku, $match) !== 1) {
            return null;
        }

        $catalogNumber = (int) $match[1];

        return $catalogNumber >= 1 && $catalogNumber <= count(ProductFactory::CATALOG)
            ? self::productExternalId($catalogNumber, $match[2])
            : null;
    }

    /**
     * The external id of a customer with an email the seed generates, or null for any other email.
     */
    private static function seededCustomerExternalId(string $email): ?string
    {
        if (preg_match('/^([a-z0-9-]+)\.([a-z0-9-]+)\.(\d{2,3})@example\.com$/', $email, $match) !== 1) {
            return null;
        }

        $number = (int) $match[3];
        $isSeededName = in_array($match[1], array_map(Str::slug(...), self::FIRST_NAMES), true)
            && in_array($match[2], array_map(Str::slug(...), self::LAST_NAMES), true);

        return $isSeededName && $number >= 1 && $number <= array_sum(array_column(self::CUSTOMER_TIERS, 'count'))
            ? self::customerExternalId($number)
            : null;
    }

    /**
     * @param  class-string<Product|Customer>  $model
     * @param  Collection<int, array{id: string, external_id: string|null}>  $candidates  in order of preference
     */
    private function assignExternalIds(Organization $organization, string $model, Collection $candidates): int
    {
        $candidates = $candidates->whereNotNull('external_id');

        if ($candidates->isEmpty()) {
            return 0;
        }

        $taken = $model::withTrashed()
            ->forOrganization($organization)
            ->whereIn('external_id', $candidates->pluck('external_id')->unique()->values())
            ->pluck('external_id')
            ->flip();

        $assignments = $candidates
            ->reject(fn (array $candidate) => $taken->has($candidate['external_id']))
            ->unique('external_id');

        foreach ($assignments as $candidate) {
            $model::withTrashed()->whereKey($candidate['id'])->toBase()->update(['external_id' => $candidate['external_id']]);
        }

        return $assignments->count();
    }

    /**
     * @return Collection<int, array{model: Product, weight: float, raised: bool}>
     */
    private function seedProducts(Organization $organization, CarbonImmutable $today): Collection
    {
        $products = collect();
        $createdAt = $today->subDays(self::HISTORY_DAYS + 30)->utc();

        $popularityRanks = range(1, count(ProductFactory::CATALOG) * 2);
        shuffle($popularityRanks);

        foreach (ProductFactory::CATALOG as $index => [$name, $min, $max]) {
            foreach (['Essential' => $min, 'Pro' => ($min + $max) / 2] as $variant => $price) {
                $position = $products->count();
                $inactive = in_array($position, [7, 15, 26, 33], true);
                $variantCode = strtoupper(substr($variant, 0, 3));

                $product = new Product;
                $product->forceFill([
                    'organization_id' => $organization->id,
                    'name' => "{$name} {$variant}",
                    'sku' => sprintf('%s%03d-%s', self::CATALOG_SKU_PREFIX, $index + 1, $variantCode),
                    'external_id' => self::productExternalId($index + 1, $variantCode),
                    'price' => $this->retailPrice($price),
                    'status' => $inactive ? ProductStatus::Inactive : ProductStatus::Active,
                    'created_at' => $createdAt,
                    'updated_at' => $inactive ? $today->subDays(self::DEACTIVATED_DAYS_AGO)->utc() : $createdAt,
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
        $customers = collect();

        foreach (self::CUSTOMER_TIERS as $tier) {
            for ($i = 0; $i < $tier['count']; $i++) {
                $createdAt = $today->subDays(mt_rand(self::HISTORY_DAYS + 5, self::HISTORY_DAYS + 200))->utc();
                $first = self::FIRST_NAMES[mt_rand(0, count(self::FIRST_NAMES) - 1)];
                $last = self::LAST_NAMES[mt_rand(0, count(self::LAST_NAMES) - 1)];

                $customer = new Customer;
                $customer->forceFill([
                    'organization_id' => $organization->id,
                    'name' => "{$first} {$last}",
                    'email' => sprintf('%s.%s.%02d@example.com', Str::slug($first), Str::slug($last), $customers->count() + 1),
                    'external_id' => self::customerExternalId($customers->count() + 1),
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
            $trend = 1 + (self::FINAL_TREND - 1) * (self::HISTORY_DAYS - 1 - $daysAgo) / (self::HISTORY_DAYS - 1);

            $available = $products->filter(
                fn (array $product) => $product['model']->status === ProductStatus::Active
                    || $daysAgo > self::DEACTIVATED_DAYS_AGO
            );

            $unitPrice = fn (array $product) => $product['raised'] && $daysAgo > self::PRICE_CHANGE_DAYS_AGO
                ? $this->retailPrice((float) $product['model']->price / self::PRICE_INCREASE)
                : $product['model']->price;

            for ($n = 0; $n < $this->dailyOrders($day, $trend * $this->eventFactor($daysAgo)); $n++) {
                $this->createTransaction(
                    $organization,
                    $this->occurredAt($day),
                    $this->pickWeighted($customers)['model'],
                    $this->pickStatus($daysAgo),
                    $available,
                    $unitPrice,
                );
            }
        }
    }

    /**
     * Buffers a transaction and its items; flush() writes them in bulk. Products and customers
     * always come from $organization, and totals use the same Money arithmetic as the models.
     *
     * @param  Collection<int, array{model: Product, weight: float}>  $products
     * @param  Closure(array{model: Product, weight: float}): string  $unitPrice
     */
    private function createTransaction(
        Organization $organization,
        CarbonImmutable $occurredAt,
        Customer $customer,
        TransactionStatus $status,
        Collection $products,
        Closure $unitPrice,
    ): void {
        $transactionId = (new Transaction)->newUniqueId();
        $timestamp = $occurredAt->utc()->format('Y-m-d H:i:s');

        $lines = min($products->count(), $this->pickFrom([1 => 55, 2 => 30, 3 => 15]));
        $chosen = [];

        while (count($chosen) < $lines) {
            $candidate = $this->pickWeighted($products);
            $chosen[$candidate['model']->id] = $candidate;
        }

        $totalCents = 0;

        foreach ($chosen as $product) {
            $quantity = $this->pickFrom([1 => 65, 2 => 25, 3 => 10]);
            $price = $unitPrice($product);
            $lineTotal = Money::multiply($price, $quantity);
            $totalCents += Money::toCents($lineTotal);

            $this->itemRows[] = [
                'id' => (new TransactionItem)->newUniqueId(),
                'transaction_id' => $transactionId,
                'product_id' => $product['model']->id,
                'quantity' => $quantity,
                'unit_price' => $price,
                'line_total' => $lineTotal,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
        }

        $this->transactionRows[] = [
            'id' => $transactionId,
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'status' => $status->value,
            'source' => TransactionSource::Seed->value,
            'total_amount' => Money::fromCents($totalCents),
            'occurred_at' => $timestamp,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ];

        // The real date of a refund or cancellation is not modeled: every change happens at occurred_at.
        $from = null;

        foreach ($status->pathFromCreation() as $to) {
            $this->statusChangeRows[] = [
                'id' => (new TransactionStatusChange)->newUniqueId(),
                'organization_id' => $organization->id,
                'transaction_id' => $transactionId,
                'from_status' => $from?->value,
                'to_status' => $to->value,
                'occurred_at' => $timestamp,
                'source' => TransactionSource::Seed->value,
                'created_at' => $timestamp,
            ];

            $from = $to;
        }
    }

    private function flush(): void
    {
        foreach (array_chunk($this->transactionRows, self::INSERT_CHUNK) as $rows) {
            Transaction::query()->insert($rows);
        }

        foreach (array_chunk($this->itemRows, self::INSERT_CHUNK) as $rows) {
            TransactionItem::query()->insert($rows);
        }

        foreach (array_chunk($this->statusChangeRows, self::INSERT_CHUNK) as $rows) {
            TransactionStatusChange::query()->insert($rows);
        }

        $this->transactionRows = [];
        $this->itemRows = [];
        $this->statusChangeRows = [];
    }

    /**
     * @return Collection<int, array{model: Product, weight: float}>
     */
    private function popularProducts(Organization $organization): Collection
    {
        return Product::query()
            ->forOrganization($organization)
            ->where('status', ProductStatus::Active)
            ->where('sku', 'like', self::CATALOG_SKU_PREFIX.'%')
            ->withSum('transactionItems as units_sold', 'quantity')
            ->orderBy('sku')
            ->get()
            ->map(fn (Product $product) => ['model' => $product, 'weight' => 1.0 + (float) $product->units_sold])
            ->values();
    }

    /**
     * @return Collection<int, array{model: Customer, weight: float}>
     */
    private function popularCustomers(Organization $organization): Collection
    {
        return Customer::query()
            ->forOrganization($organization)
            ->withCount('transactions')
            ->orderBy('email')
            ->get()
            ->map(fn (Customer $customer) => ['model' => $customer, 'weight' => 1.0 + $customer->transactions_count])
            ->values();
    }

    /**
     * A local time during business hours of $day, stored in UTC. Today's sales never lie in the future.
     */
    private function occurredAt(CarbonImmutable $day): CarbonImmutable
    {
        $occurredAt = $day->setTime($this->pickHour(), mt_rand(0, 59), mt_rand(0, 59));
        $now = CarbonImmutable::now($day->getTimezone());

        if ($occurredAt->greaterThan($now)) {
            $occurredAt = $day->addSeconds(mt_rand(0, max(0, (int) $day->diffInSeconds($now))));
        }

        return $occurredAt->utc();
    }

    private function dailyOrders(CarbonImmutable $day, float $level): int
    {
        $weekday = match (true) {
            $day->isSunday() => 0.6,
            $day->isSaturday() => 0.8,
            default => 1.0,
        };

        return max(0, (int) round(self::BASE_DAILY_ORDERS * $level * $weekday + mt_rand(-10, 10) / 10));
    }

    private function eventFactor(int $daysAgo): float
    {
        return match (true) {
            $daysAgo >= 32 && $daysAgo <= 38 => 2.2,
            $daysAgo >= 56 && $daysAgo <= 60 => 0.5,
            default => 1.0,
        };
    }

    private function retailPrice(float $amount): string
    {
        return number_format(floor($amount) + 0.9, 2, '.', '');
    }

    private function pickStatus(int $daysAgo): TransactionStatus
    {
        $weights = $daysAgo <= self::PENDING_DAYS
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
