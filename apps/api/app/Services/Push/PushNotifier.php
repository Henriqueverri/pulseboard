<?php

namespace App\Services\Push;

use App\Data\Push\PushMessage;
use App\Models\NotificationEvent;
use App\Models\Organization;
use App\Models\PushDevice;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Sends one alert to the devices of every member of an organization, at most once per
 * (kind, dedupe key): the notification_events row is claimed before sending, so concurrent
 * events and repeated triggers cannot send it twice. The flip side is that an alert lost to a
 * push service outage is not retried.
 */
final class PushNotifier
{
    public function __construct(private readonly PushClient $client) {}

    public function alreadySent(Organization $organization, string $kind, string $dedupeKey): bool
    {
        return NotificationEvent::query()
            ->forOrganization($organization)
            ->where('kind', $kind)
            ->where('dedupe_key', $dedupeKey)
            ->exists();
    }

    /**
     * @param  array<string, string>  $data  delivered to the app (e.g. the screen to open)
     */
    public function notify(
        Organization $organization,
        string $kind,
        string $dedupeKey,
        string $title,
        string $body,
        array $data = [],
    ): void {
        $started = hrtime(true);

        $claimed = NotificationEvent::query()->insertOrIgnore([
            'id' => (string) Str::uuid7(),
            'organization_id' => $organization->id,
            'kind' => $kind,
            'dedupe_key' => $dedupeKey,
            'recipients' => 0,
            'created_at' => now(),
        ]) > 0;

        if (! $claimed) {
            return;
        }

        $tokens = PushDevice::query()->ofMembersOf($organization)->pluck('token')->all();

        if ($tokens === []) {
            $this->log($organization, $kind, 'no_devices', 0, 0, 0, 0, $started);

            return;
        }

        $data = [...$data, 'organization_id' => $organization->id, 'kind' => $kind];
        $delivery = $this->client->send(array_map(
            fn (string $token): PushMessage => new PushMessage($token, $title, $body, $data),
            $tokens,
        ));

        if ($delivery->unregisteredTokens !== []) {
            PushDevice::query()->whereIn('token', $delivery->unregisteredTokens)->delete();
        }

        NotificationEvent::query()
            ->forOrganization($organization)
            ->where('kind', $kind)
            ->where('dedupe_key', $dedupeKey)
            ->update(['recipients' => $delivery->accepted]);

        $this->log(
            $organization,
            $kind,
            $delivery->accepted > 0 ? 'sent' : 'failed',
            count($tokens),
            $delivery->accepted,
            $delivery->failed,
            count($delivery->unregisteredTokens),
            $started,
        );
    }

    private function log(
        Organization $organization,
        string $kind,
        string $outcome,
        int $devices,
        int $accepted,
        int $failed,
        int $unregistered,
        int $started,
    ): void {
        Log::info('Push notification processed.', [
            'event' => 'push.notify',
            'outcome' => $outcome,
            'kind' => $kind,
            'organization_id' => $organization->id,
            'devices' => $devices,
            'accepted' => $accepted,
            'failed' => $failed,
            'unregistered' => $unregistered,
            'duration_ms' => (int) round((hrtime(true) - $started) / 1_000_000),
        ]);
    }
}
