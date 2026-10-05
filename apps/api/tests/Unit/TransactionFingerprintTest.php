<?php

namespace Tests\Unit;

use App\Data\Ingestion\IngestTransactionData;
use App\Data\Ingestion\IngestTransactionItemData;
use App\Enums\TransactionStatus;
use App\Services\Ingestion\TransactionFingerprint;
use App\Support\Money;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class TransactionFingerprintTest extends TestCase
{
    public function test_the_fingerprint_is_order_independent_and_uses_normalized_money_and_time(): void
    {
        $first = $this->data(
            CarbonImmutable::parse('2026-10-04T15:30:00-03:00'),
            [
                new IngestTransactionItemData('SKU-B', 2, '5.00'),
                new IngestTransactionItemData('SKU-A', 1, '10'),
            ],
        );
        $second = $this->data(
            CarbonImmutable::parse('2026-10-04T18:30:00Z'),
            [
                new IngestTransactionItemData('SKU-A', 1, '10.00'),
                new IngestTransactionItemData('SKU-B', 2, '5'),
            ],
        );

        $fingerprint = new TransactionFingerprint;

        $this->assertSame($fingerprint->make($first), $fingerprint->make($second));
    }

    public function test_relevant_payload_changes_change_the_fingerprint(): void
    {
        $fingerprint = new TransactionFingerprint;
        $original = $this->data(
            CarbonImmutable::parse('2026-10-04T18:30:00Z'),
            [new IngestTransactionItemData('SKU-A', 1, '10.00')],
        );
        $changed = $this->data(
            CarbonImmutable::parse('2026-10-04T18:30:00Z'),
            [new IngestTransactionItemData('SKU-A', 2, '10.00')],
        );

        $this->assertNotSame($fingerprint->make($original), $fingerprint->make($changed));
    }

    public function test_decimal_strings_are_parsed_without_floats(): void
    {
        $this->assertSame(1, Money::parseToCents('0.01'));
        $this->assertSame(10, Money::parseToCents('0.1'));
        $this->assertSame(9_999_999_999, Money::parseToCents('99999999.99'));
    }

    /**
     * @param  list<IngestTransactionItemData>  $items
     */
    private function data(CarbonImmutable $occurredAt, array $items): IngestTransactionData
    {
        return new IngestTransactionData(
            externalId: 'order-1',
            status: TransactionStatus::Paid,
            occurredAt: $occurredAt->utc(),
            currency: 'BRL',
            customerExternalId: 'customer-1',
            customerName: 'Name not fingerprinted',
            customerEmail: 'email-not-fingerprinted@example.com',
            items: $items,
            declaredTotal: null,
        );
    }
}
