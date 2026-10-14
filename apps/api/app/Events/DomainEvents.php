<?php

namespace App\Events;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Dispatches domain events after the response is sent (`defer()`), so the ingestion that caused
 * them is already committed and answered. Broadcasting (and any listener) runs in the same PHP
 * process, without a queue worker; a failure is logged and never reaches the integration.
 */
final class DomainEvents
{
    public function dispatch(OrganizationEvent $event): void
    {
        defer(static function () use ($event): void {
            try {
                event($event);
            } catch (Throwable $exception) {
                Log::warning('Domain event dispatch failed.', [
                    'event' => 'realtime.dispatch',
                    'outcome' => 'failed',
                    'domain_event' => $event->broadcastAs(),
                    'organization_id' => $event->organizationId,
                    'error_type' => $exception::class,
                ]);
            }
        });
    }
}
