<?php

namespace Tests\Feature\Api\Concerns;

use App\Enums\TransactionSource;
use App\Models\ApiKey;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Support\ApiKeyGenerator;

trait InteractsWithIngestApi
{
    /**
     * A usable key: the row stores only the hash, the plain text key is returned once.
     *
     * @param  array<string, mixed>  $attributes
     * @return array{0: ApiKey, 1: string}
     */
    protected function issueApiKey(Organization $organization, array $attributes = []): array
    {
        $generated = app(ApiKeyGenerator::class)->generate();

        $apiKey = ApiKey::factory()->for($organization)->create([
            ...$attributes,
            'prefix' => $generated['prefix'],
            'secret_hash' => $generated['secret_hash'],
        ]);

        return [$apiKey, $generated['plain_text_key']];
    }

    /**
     * An external system: no SPA Origin/Referer (so no session or CSRF), only the bearer key.
     */
    protected function asIntegration(?string $plainTextKey): static
    {
        $this->withoutHeaders(['Origin', 'Referer', 'Authorization']);

        return $plainTextKey === null ? $this : $this->withToken($plainTextKey);
    }

    protected function createIngestedTransaction(Organization $organization, string $externalId, ?ApiKey $apiKey = null): Transaction
    {
        $customer = Customer::factory()->for($organization)->create(['external_id' => "cus-{$externalId}"]);
        $product = Product::factory()->for($organization)->create(['external_id' => "prd-{$externalId}"]);

        $transaction = Transaction::factory()
            ->for($organization)
            ->for($customer)
            ->has(TransactionItem::factory()->for($product)->state(['quantity' => 2, 'unit_price' => '49.90']), 'items')
            ->create([
                'external_id' => $externalId,
                'source' => TransactionSource::Ingest,
                'api_key_id' => $apiKey?->id,
            ]);

        return $transaction->refresh();
    }
}
