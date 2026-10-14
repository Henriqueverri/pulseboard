<?php

namespace App\Listeners;

use App\Enums\StockStatus;
use App\Enums\TransactionStatus;
use App\Events\StockChanged;
use App\Events\TransactionRecorded;
use App\Events\TransactionStatusChanged;
use App\Models\NotificationEvent;
use App\Models\Organization;
use App\Services\Analytics\DashboardService;
use App\Services\Push\PushNotifier;
use App\Support\Analytics\ReportingPeriod;
use App\Support\CurrentOrganization;
use App\Support\Money;

/**
 * Turns the realtime domain events into push alerts for the mobile app. Runs inside the same
 * defer() as the broadcast, so nothing here delays or fails an ingestion. Each alert is
 * deduplicated per local day: a product that keeps crossing its minimum, or a goal that stays
 * beaten, is announced once.
 */
final class SendPushNotifications
{
    /** Screens of the mobile app (expo-router paths). */
    private const SCREEN_HOME = '/';

    private const SCREEN_TRANSACTIONS = '/transactions';

    public function __construct(
        private readonly PushNotifier $notifier,
        private readonly DashboardService $dashboard,
    ) {}

    /**
     * Only a crossing counts: ok → low, or anything tracked → out. Turning stock control on
     * (no previous status) is configuration, not news.
     */
    public function handleStockChanged(StockChanged $event): void
    {
        $kind = match (true) {
            $event->status === StockStatus::Out && $event->previousStatus !== null && $event->previousStatus !== StockStatus::Out => NotificationEvent::KIND_STOCK_OUT,
            $event->status === StockStatus::Low && $event->previousStatus === StockStatus::Ok => NotificationEvent::KIND_STOCK_LOW,
            default => null,
        };

        $organization = $kind === null ? null : $this->organization($event->organizationId);

        if ($organization === null) {
            return;
        }

        [$title, $body] = $kind === NotificationEvent::KIND_STOCK_OUT
            ? ['Estoque zerado', "{$event->name} está sem estoque."]
            : ['Estoque baixo', "{$event->name} ({$event->quantity} un., mínimo {$event->minQuantity})"];

        $this->notifier->notify(
            $organization,
            $kind,
            $event->productId.':'.$this->today($organization)->from(),
            $title,
            $body,
            ['url' => self::SCREEN_HOME, 'product_id' => $event->productId],
        );
    }

    public function handleTransactionRecorded(TransactionRecorded $event): void
    {
        if ($event->status === TransactionStatus::Paid) {
            $this->checkDailyGoal($event->organizationId);
        }
    }

    /**
     * A pending sale that gets paid adds to the revenue of the day it happened.
     */
    public function handleTransactionStatusChanged(TransactionStatusChanged $event): void
    {
        if ($event->to === TransactionStatus::Paid) {
            $this->checkDailyGoal($event->organizationId);
        }
    }

    /**
     * Today's paid revenue comes from the dashboard KPIs, the same number the owner sees.
     */
    private function checkDailyGoal(string $organizationId): void
    {
        $organization = $this->organization($organizationId);
        $goal = $organization?->daily_revenue_goal;

        if ($organization === null || $goal === null) {
            return;
        }

        $today = $this->today($organization);

        if ($this->notifier->alreadySent($organization, NotificationEvent::KIND_DAILY_GOAL, $today->from())) {
            return;
        }

        $revenue = (string) $this->dashboard->kpis($organization, $today)['revenue']->value;

        if (Money::parseToCents($revenue) < Money::parseToCents((string) $goal)) {
            return;
        }

        $this->notifier->notify(
            $organization,
            NotificationEvent::KIND_DAILY_GOAL,
            $today->from(),
            'Meta do dia batida',
            sprintf(
                '%s em vendas pagas hoje (meta de %s).',
                $this->money($revenue, $organization->currency),
                $this->money((string) $goal, $organization->currency),
            ),
            ['url' => self::SCREEN_TRANSACTIONS],
        );
    }

    /**
     * Deferred work runs in the request that caused the event, where the API key middleware
     * already loaded the organization: reusing it keeps every sale free of an extra query.
     */
    private function organization(string $organizationId): ?Organization
    {
        $current = CurrentOrganization::resolved();

        return $current?->id === $organizationId ? $current : Organization::query()->find($organizationId);
    }

    private function today(Organization $organization): ReportingPeriod
    {
        return ReportingPeriod::lastDays(1, $organization->timezone);
    }

    /**
     * pt-BR, like the app ("R$ 1.523,40"); the production image has no intl extension.
     */
    private function money(string $amount, string $currency): string
    {
        $formatted = number_format(Money::parseToCents($amount) / 100, 2, ',', '.');

        return ($currency === 'BRL' ? 'R$' : $currency).' '.$formatted;
    }
}
