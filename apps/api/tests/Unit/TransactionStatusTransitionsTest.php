<?php

namespace Tests\Unit;

use App\Enums\TransactionStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TransactionStatusTransitionsTest extends TestCase
{
    private const ALLOWED = [
        'pending' => ['paid', 'canceled'],
        'paid' => ['refunded'],
        'refunded' => [],
        'canceled' => [],
    ];

    /**
     * Every (from, to) pair, including staying in the same status.
     *
     * @return array<string, array{0: TransactionStatus, 1: TransactionStatus, 2: bool}>
     */
    public static function transitions(): array
    {
        $cases = [];

        foreach (TransactionStatus::cases() as $from) {
            foreach (TransactionStatus::cases() as $to) {
                $cases["{$from->value} -> {$to->value}"] = [$from, $to, in_array($to->value, self::ALLOWED[$from->value], true)];
            }
        }

        return $cases;
    }

    #[DataProvider('transitions')]
    public function test_transition_table(TransactionStatus $from, TransactionStatus $to, bool $allowed): void
    {
        $this->assertSame($allowed, $from->canTransitionTo($to));
    }

    public function test_the_table_covers_every_status(): void
    {
        $this->assertCount(16, self::transitions());
        $this->assertEqualsCanonicalizing(
            array_map(fn (TransactionStatus $status) => $status->value, TransactionStatus::cases()),
            array_keys(self::ALLOWED),
        );
    }

    public function test_only_refunded_and_canceled_are_final_and_final_statuses_have_no_way_out(): void
    {
        foreach (TransactionStatus::cases() as $status) {
            $this->assertSame(
                in_array($status, [TransactionStatus::Refunded, TransactionStatus::Canceled], true),
                $status->isFinal(),
                $status->value,
            );

            $hasWayOut = array_filter(TransactionStatus::cases(), fn (TransactionStatus $to) => $status->canTransitionTo($to)) !== [];
            $this->assertSame(! $status->isFinal(), $hasWayOut, $status->value);
        }
    }

    public function test_only_pending_and_paid_are_allowed_on_create(): void
    {
        foreach (TransactionStatus::cases() as $status) {
            $this->assertSame(
                in_array($status, [TransactionStatus::Pending, TransactionStatus::Paid], true),
                $status->allowedOnCreate(),
                $status->value,
            );
        }
    }

    public function test_no_status_can_be_revisited(): void
    {
        foreach (TransactionStatus::cases() as $status) {
            $this->assertFalse($status->canTransitionTo($status), $status->value);
        }

        $this->assertFalse(TransactionStatus::Paid->canTransitionTo(TransactionStatus::Pending));
        $this->assertFalse(TransactionStatus::Refunded->canTransitionTo(TransactionStatus::Paid));
        $this->assertFalse(TransactionStatus::Canceled->canTransitionTo(TransactionStatus::Pending));
    }

    public function test_path_from_creation_is_a_valid_walk_through_the_machine(): void
    {
        foreach (TransactionStatus::cases() as $status) {
            $path = $status->pathFromCreation();

            $this->assertTrue($path[0]->allowedOnCreate(), $status->value);
            $this->assertSame($status, $path[array_key_last($path)]);

            for ($i = 1; $i < count($path); $i++) {
                $this->assertTrue($path[$i - 1]->canTransitionTo($path[$i]), $status->value);
            }
        }

        $this->assertSame([TransactionStatus::Paid, TransactionStatus::Refunded], TransactionStatus::Refunded->pathFromCreation());
        $this->assertSame([TransactionStatus::Pending, TransactionStatus::Canceled], TransactionStatus::Canceled->pathFromCreation());
    }
}
