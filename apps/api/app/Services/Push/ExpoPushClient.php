<?php

namespace App\Services\Push;

use App\Data\Push\PushDelivery;
use App\Data\Push\PushMessage;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Expo Push API: up to 100 messages per request, answered with one ticket per message in the
 * same order. A ticket with `DeviceNotRegistered` means the token is dead. Receipts (checked
 * about 15 minutes later) are not read: there is no worker or cron to come back for them.
 */
final class ExpoPushClient implements PushClient
{
    public const BATCH_SIZE = 100;

    private const RETRIES = 2;

    private const RETRY_DELAY_MS = 250;

    public function __construct(
        private readonly string $url,
        private readonly ?string $accessToken,
        private readonly string $androidChannelId,
        private readonly int $timeoutSeconds,
        private readonly int $connectTimeoutSeconds,
    ) {}

    public function send(array $messages): PushDelivery
    {
        $accepted = 0;
        $failed = 0;
        $unregistered = [];

        foreach (array_chunk($messages, self::BATCH_SIZE) as $batch) {
            $tickets = $this->post($batch);

            foreach ($batch as $index => $message) {
                $ticket = $tickets[$index] ?? null;

                if (is_array($ticket) && ($ticket['status'] ?? null) === 'ok') {
                    $accepted++;

                    continue;
                }

                $failed++;

                if (is_array($ticket) && ($ticket['details']['error'] ?? null) === 'DeviceNotRegistered') {
                    $unregistered[] = $message->token;
                }
            }
        }

        return new PushDelivery($accepted, $failed, $unregistered);
    }

    /**
     * @param  list<PushMessage>  $batch
     * @return list<mixed> the tickets, or none when the whole request failed
     */
    private function post(array $batch): array
    {
        try {
            $response = Http::acceptJson()
                ->asJson()
                ->timeout($this->timeoutSeconds)
                ->connectTimeout($this->connectTimeoutSeconds)
                ->when($this->accessToken, fn ($request, string $token) => $request->withToken($token))
                ->retry(self::RETRIES, self::RETRY_DELAY_MS, fn (Throwable $exception): bool => $exception instanceof ConnectionException
                    || ($exception instanceof RequestException && ($exception->response->serverError() || $exception->response->status() === 429)))
                ->post($this->url, array_map($this->payload(...), $batch));
        } catch (ConnectionException|RequestException $exception) {
            $this->logRequestFailure(count($batch), $exception instanceof RequestException ? $exception->response->status() : null);

            return [];
        }

        $tickets = $response->json('data');

        if (! is_array($tickets) || ! array_is_list($tickets)) {
            // A request-level error (e.g. PUSH_TOO_MANY_EXPERIENCE_IDS) comes in `errors`, without tickets.
            $this->logRequestFailure(count($batch), $response->status());

            return [];
        }

        return $tickets;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(PushMessage $message): array
    {
        return [
            'to' => $message->token,
            'title' => $message->title,
            'body' => $message->body,
            'data' => (object) $message->data,
            'sound' => 'default',
            'priority' => 'high',
            'channelId' => $this->androidChannelId,
        ];
    }

    private function logRequestFailure(int $messages, ?int $status): void
    {
        Log::warning('Expo push request failed.', [
            'event' => 'push.request',
            'outcome' => 'failed',
            'messages' => $messages,
            'http_status' => $status,
        ]);
    }
}
