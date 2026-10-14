<?php

namespace Tests\Unit\Push;

use App\Data\Push\PushMessage;
use App\Services\Push\ExpoPushClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class ExpoPushClientTest extends TestCase
{
    private const URL = 'https://exp.host/--/api/v2/push/send';

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
    }

    public function test_messages_go_in_batches_of_one_hundred_with_one_ticket_each(): void
    {
        Http::fake([self::URL => fn (Request $request) => Http::response([
            'data' => array_map(fn (): array => ['status' => 'ok', 'id' => 'ticket'], $request->data()),
        ])]);

        $delivery = $this->client()->send($this->messages(250));

        $this->assertSame(250, $delivery->accepted);
        $this->assertSame(0, $delivery->failed);
        Http::assertSentCount(3);
        $sizes = Http::recorded()->map(fn (array $pair): int => count($pair[0]->data()))->all();
        $this->assertSame([100, 100, 50], $sizes);
    }

    public function test_the_payload_targets_the_alerts_channel(): void
    {
        Http::fake([self::URL => Http::response(['data' => [['status' => 'ok', 'id' => 'ticket']]])]);

        $this->client()->send([new PushMessage('ExponentPushToken[a]', 'Estoque baixo', 'Heineken (3 un.)', ['url' => '/'])]);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->hasHeader('Accept', 'application/json')
            && ! $request->hasHeader('Authorization')
            && json_decode($request->body(), true) === [[
                'to' => 'ExponentPushToken[a]',
                'title' => 'Estoque baixo',
                'body' => 'Heineken (3 un.)',
                'data' => ['url' => '/'],
                'sound' => 'default',
                'priority' => 'high',
                'channelId' => 'alerts',
            ]]);
    }

    public function test_empty_data_is_sent_as_an_object(): void
    {
        Http::fake([self::URL => Http::response(['data' => [['status' => 'ok', 'id' => 'ticket']]])]);

        $this->client()->send($this->messages(1));

        Http::assertSent(fn (Request $request): bool => str_contains($request->body(), '"data":{}'));
    }

    public function test_the_access_token_is_sent_when_configured(): void
    {
        Http::fake([self::URL => Http::response(['data' => [['status' => 'ok', 'id' => 'ticket']]])]);

        $this->client('expo-secret')->send($this->messages(1));

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer expo-secret'));
    }

    public function test_dead_tokens_are_reported(): void
    {
        Http::fake([self::URL => Http::response(['data' => [
            ['status' => 'ok', 'id' => 'ticket'],
            ['status' => 'error', 'message' => 'not registered', 'details' => ['error' => 'DeviceNotRegistered']],
            ['status' => 'error', 'message' => 'too many', 'details' => ['error' => 'MessageRateExceeded']],
        ]])]);

        $delivery = $this->client()->send($this->messages(3));

        $this->assertSame(1, $delivery->accepted);
        $this->assertSame(2, $delivery->failed);
        $this->assertSame(['ExponentPushToken[device-1]'], $delivery->unregisteredTokens);
    }

    public function test_a_server_error_is_retried(): void
    {
        Http::fakeSequence(self::URL)
            ->push('unavailable', 503)
            ->push(['data' => [['status' => 'ok', 'id' => 'ticket']]]);

        $delivery = $this->client()->send($this->messages(1));

        $this->assertSame(1, $delivery->accepted);
        Http::assertSentCount(2);
    }

    public function test_a_failed_request_fails_its_batch_without_throwing(): void
    {
        Log::spy();
        Http::fake([self::URL => Http::response('unavailable', 503)]);

        $delivery = $this->client()->send($this->messages(2));

        $this->assertSame(0, $delivery->accepted);
        $this->assertSame(2, $delivery->failed);
        $this->assertSame([], $delivery->unregisteredTokens);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => $context['event'] === 'push.request'
            && $context['outcome'] === 'failed'
            && $context['messages'] === 2
            && $context['http_status'] === 503)->once();
    }

    public function test_a_request_level_error_without_tickets_fails_the_batch(): void
    {
        Http::fake([self::URL => Http::response(['errors' => [['code' => 'PUSH_TOO_MANY_EXPERIENCE_IDS']]])]);

        $delivery = $this->client()->send($this->messages(2));

        $this->assertSame(0, $delivery->accepted);
        $this->assertSame(2, $delivery->failed);
    }

    public function test_a_client_error_is_not_retried(): void
    {
        Http::fake([self::URL => Http::response(['errors' => [['code' => 'UNAUTHORIZED']]], 401)]);

        $this->client()->send($this->messages(1));

        Http::assertSentCount(1);
    }

    private function client(?string $accessToken = null): ExpoPushClient
    {
        return new ExpoPushClient(self::URL, $accessToken, 'alerts', 10, 5);
    }

    /**
     * @return list<PushMessage>
     */
    private function messages(int $count): array
    {
        return array_map(
            fn (int $index): PushMessage => new PushMessage("ExponentPushToken[device-{$index}]", 'Título', 'Corpo'),
            range(0, $count - 1),
        );
    }
}
