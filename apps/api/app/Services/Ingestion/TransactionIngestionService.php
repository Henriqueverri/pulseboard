<?php

namespace App\Services\Ingestion;

use App\Data\Ingestion\IngestionResult;
use App\Data\Ingestion\IngestTransactionData;
use App\Data\Ingestion\IngestTransactionItemData;
use App\Enums\TransactionSource;
use App\Exceptions\IngestionException;
use App\Models\ApiKey;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Transaction;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class TransactionIngestionService
{
    private const MAX_MONEY_CENTS = 999_999_999_999;

    public function __construct(private readonly TransactionFingerprint $fingerprint) {}

    public function ingest(
        Organization $organization,
        ApiKey $apiKey,
        IngestTransactionData $data,
    ): IngestionResult {
        $fingerprint = $this->fingerprint->make($data);

        $this->validateCurrencyAndTotal($organization, $data);

        try {
            $transactionId = DB::transaction(
                fn (): string => $this->create($organization, $apiKey, $data, $fingerprint),
            );
        } catch (UniqueConstraintViolationException) {
            $existing = $organization->transactions()
                ->where('external_id', $data->externalId)
                ->first();

            if ($existing === null || ! hash_equals((string) $existing->ingest_fingerprint, $fingerprint)) {
                throw IngestionException::conflict(
                    'A transaction with this external id already exists with different data.',
                    'transaction_conflict',
                );
            }

            return new IngestionResult($this->loadForResponse($existing, $organization), true);
        }

        $transaction = Transaction::query()->findOrFail($transactionId);

        return new IngestionResult($this->loadForResponse($transaction, $organization), false);
    }

    private function create(
        Organization $organization,
        ApiKey $apiKey,
        IngestTransactionData $data,
        string $fingerprint,
    ): string {
        $products = $this->resolveProducts($organization, $data);
        $customer = $this->resolveCustomer($organization, $data);
        $transactionId = (string) Str::uuid();
        $now = now();

        DB::table('transactions')->insert([
            'id' => $transactionId,
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'status' => $data->status->value,
            'total_amount' => Money::fromCents($data->totalCents()),
            'occurred_at' => $data->occurredAt,
            'source' => TransactionSource::Ingest->value,
            'external_id' => $data->externalId,
            'api_key_id' => $apiKey->id,
            'ingest_fingerprint' => $fingerprint,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('transaction_items')->insert(array_map(
            fn (IngestTransactionItemData $item): array => [
                'id' => (string) Str::uuid(),
                'transaction_id' => $transactionId,
                'product_id' => $products->get($item->sku)->id,
                'quantity' => $item->quantity,
                'unit_price' => Money::fromCents($item->unitPriceCents()),
                'line_total' => Money::fromCents($item->lineTotalCents()),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            $data->items,
        ));

        DB::table('transaction_status_changes')->insert([
            'id' => (string) Str::uuid(),
            'organization_id' => $organization->id,
            'transaction_id' => $transactionId,
            'from_status' => null,
            'to_status' => $data->status->value,
            'occurred_at' => $data->occurredAt,
            'source' => TransactionSource::Ingest->value,
            'api_key_id' => $apiKey->id,
            'created_at' => $now,
        ]);

        return $transactionId;
    }

    /**
     * @return Collection<string, Product>
     */
    private function resolveProducts(Organization $organization, IngestTransactionData $data): Collection
    {
        $skus = array_map(fn (IngestTransactionItemData $item): string => $item->sku, $data->items);
        $products = Product::withTrashed()
            ->where('organization_id', $organization->id)
            ->whereIn('sku', $skus)
            ->get()
            ->keyBy('sku');
        $errors = [];

        foreach ($data->items as $index => $item) {
            $product = $products->get($item->sku);

            if ($product === null || $product->trashed()) {
                $errors["items.{$index}.sku"] = ['The selected product SKU is invalid.'];
            }
        }

        if ($errors !== []) {
            throw IngestionException::validation('One or more products could not be resolved.', 'validation_failed', $errors);
        }

        return $products;
    }

    private function resolveCustomer(Organization $organization, IngestTransactionData $data): Customer
    {
        $customer = Customer::withTrashed()
            ->where('organization_id', $organization->id)
            ->where('external_id', $data->customerExternalId)
            ->first();

        if ($customer?->trashed()) {
            throw IngestionException::validation(
                'The customer is not available.',
                'validation_failed',
                ['customer.external_id' => ['The selected customer is deleted.']],
            );
        }

        if ($customer !== null) {
            return $customer;
        }

        try {
            return Customer::query()->createOrFirst(
                [
                    'organization_id' => $organization->id,
                    'external_id' => $data->customerExternalId,
                ],
                [
                    'name' => $data->customerName,
                    'email' => $data->customerEmail,
                ],
            );
        } catch (UniqueConstraintViolationException) {
            throw IngestionException::conflict(
                'The customer email is already associated with another customer.',
                'customer_email_conflict',
            );
        }
    }

    private function validateCurrencyAndTotal(Organization $organization, IngestTransactionData $data): void
    {
        if ($data->currency !== $organization->currency) {
            throw IngestionException::validation(
                'The transaction currency does not match the organization currency.',
                'currency_mismatch',
                ['currency' => ["The currency must be {$organization->currency}."]],
            );
        }

        $totalCents = $data->totalCents();

        if ($totalCents > self::MAX_MONEY_CENTS) {
            throw IngestionException::validation(
                'The calculated total is too large.',
                'validation_failed',
                ['items' => ['The calculated total may not exceed 9999999999.99.']],
            );
        }

        if ($data->declaredTotal !== null && Money::parseToCents($data->declaredTotal) !== $totalCents) {
            throw IngestionException::validation(
                'The declared total does not match the calculated total.',
                'total_mismatch',
                ['total_amount' => ['The total amount does not match the sum of the items.']],
            );
        }
    }

    private function loadForResponse(Transaction $transaction, Organization $organization): Transaction
    {
        $transaction->load([
            'customer',
            'items' => fn ($items) => $items->orderBy('created_at')->orderBy('id'),
            'items.product',
            'statusChanges',
        ]);
        $transaction->setRelation('organization', $organization);

        return $transaction;
    }
}
