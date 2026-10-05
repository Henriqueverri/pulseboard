<?php

namespace Tests\Unit\Ai;

use App\Data\Ai\LlmMessage;
use App\Data\Ai\LlmRequest;
use App\Data\Ai\OutputSchema;
use App\Data\Ai\ToolCall;
use App\Data\Ai\ToolDefinition;
use App\Data\Ai\ToolExchange;
use App\Exceptions\AiException;
use App\Services\Ai\OpenAi\OpenAiResponsesClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * The OpenAI adapter against faked HTTP. The response shapes follow the
 * Responses API reference (output items, usage, refusal, incomplete_details).
 */
class OpenAiResponsesClientTest extends TestCase
{
    private const URL = 'https://api.openai.test/v1/responses';

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
    }

    public function test_the_request_maps_to_the_responses_api_with_a_strict_schema_and_no_storage(): void
    {
        Http::fake([self::URL => Http::response($this->completed('{"ok":true}'))]);

        $this->client()->respond(new LlmRequest(
            instructions: 'System rules.',
            input: [LlmMessage::user('Context JSON')],
            outputSchema: new OutputSchema('period_summary_v1', ['type' => 'object', 'properties' => [], 'required' => [], 'additionalProperties' => false]),
            maxOutputTokens: 800,
        ));

        Http::assertSent(function (Request $request): bool {
            $body = $request->data();

            return $request->url() === self::URL
                && $request->method() === 'POST'
                && $request->hasHeader('Authorization', 'Bearer sk-test-secret')
                && $body['model'] === 'gpt-6-luna'
                && $body['instructions'] === 'System rules.'
                && $body['input'] === [['role' => 'user', 'content' => 'Context JSON']]
                && $body['max_output_tokens'] === 800
                && $body['store'] === false
                && $body['text']['format']['type'] === 'json_schema'
                && $body['text']['format']['name'] === 'period_summary_v1'
                && $body['text']['format']['strict'] === true
                && ! array_key_exists('tools', $body);
        });
    }

    public function test_tools_are_strict_functions_and_tool_exchanges_replay_as_call_and_output_items(): void
    {
        Http::fake([self::URL => Http::response($this->completed('{}'))]);

        $this->client()->respond(new LlmRequest(
            instructions: 'Rules.',
            input: [
                LlmMessage::user('Why did revenue drop?'),
                new ToolExchange(new ToolCall('call_1', 'get_period_kpis', '{"from":null,"to":null}'), '{"ref":"r1"}'),
            ],
            tools: [new ToolDefinition('get_period_kpis', 'KPIs.', ['type' => 'object', 'properties' => [], 'required' => [], 'additionalProperties' => false])],
        ));

        Http::assertSent(function (Request $request): bool {
            $body = $request->data();

            return $body['tools'] === [[
                'type' => 'function',
                'name' => 'get_period_kpis',
                'description' => 'KPIs.',
                'parameters' => ['type' => 'object', 'properties' => [], 'required' => [], 'additionalProperties' => false],
                'strict' => true,
            ]]
                && $body['input'] === [
                    ['role' => 'user', 'content' => 'Why did revenue drop?'],
                    ['type' => 'function_call', 'call_id' => 'call_1', 'name' => 'get_period_kpis', 'arguments' => '{"from":null,"to":null}'],
                    ['type' => 'function_call_output', 'call_id' => 'call_1', 'output' => '{"ref":"r1"}'],
                ];
        });
    }

    public function test_output_text_and_usage_are_parsed(): void
    {
        Http::fake([self::URL => Http::response($this->completed('{"headline":"Receita estável"}', [
            'input_tokens' => 1200,
            'input_tokens_details' => ['cached_tokens' => 200],
            'output_tokens' => 150,
            'total_tokens' => 1350,
        ]))]);

        $response = $this->client()->respond($this->request());

        $this->assertSame(['headline' => 'Receita estável'], $response->json());
        $this->assertSame('gpt-6-luna-2026-07-01', $response->model);
        $this->assertSame(1200, $response->usage->inputTokens);
        $this->assertSame(200, $response->usage->cachedInputTokens);
        $this->assertSame(150, $response->usage->outputTokens);
        $this->assertFalse($response->hasToolCalls());
        $this->assertFalse($response->isRefusal());
        $this->assertFalse($response->isIncomplete());
    }

    public function test_function_calls_are_parsed_with_raw_arguments(): void
    {
        Http::fake([self::URL => Http::response([
            'status' => 'completed',
            'model' => 'gpt-6-luna',
            'output' => [
                ['type' => 'reasoning', 'id' => 'rs_1', 'summary' => []],
                ['type' => 'function_call', 'id' => 'fc_1', 'call_id' => 'call_a', 'name' => 'get_period_kpis', 'arguments' => '{"from":"2026-09-01","to":"2026-09-30"}'],
                ['type' => 'function_call', 'id' => 'fc_2', 'call_id' => 'call_b', 'name' => 'get_status_breakdown', 'arguments' => '{"from":null,"to":null}'],
            ],
            'usage' => ['input_tokens' => 900, 'output_tokens' => 40],
        ])]);

        $response = $this->client()->respond($this->request());

        $this->assertTrue($response->hasToolCalls());
        $this->assertNull($response->outputText);
        $this->assertCount(2, $response->toolCalls);
        $this->assertSame('call_a', $response->toolCalls[0]->id);
        $this->assertSame('get_period_kpis', $response->toolCalls[0]->name);
        $this->assertSame('{"from":"2026-09-01","to":"2026-09-30"}', $response->toolCalls[0]->arguments);
        $this->assertSame('get_status_breakdown', $response->toolCalls[1]->name);
    }

    public function test_a_refusal_is_reported_without_output(): void
    {
        Http::fake([self::URL => Http::response([
            'status' => 'completed',
            'model' => 'gpt-6-luna',
            'output' => [[
                'type' => 'message',
                'role' => 'assistant',
                'content' => [['type' => 'refusal', 'refusal' => "I'm sorry, I cannot assist with that request."]],
            ]],
            'usage' => ['input_tokens' => 81, 'output_tokens' => 11],
        ])]);

        $response = $this->client()->respond($this->request());

        $this->assertTrue($response->isRefusal());
        $this->assertNull($response->json());
    }

    public function test_an_incomplete_response_carries_its_reason(): void
    {
        Http::fake([self::URL => Http::response([
            'status' => 'incomplete',
            'incomplete_details' => ['reason' => 'max_output_tokens'],
            'model' => 'gpt-6-luna',
            'output' => [[
                'type' => 'message',
                'content' => [['type' => 'output_text', 'text' => '{"headline":"Rece']],
            ]],
            'usage' => ['input_tokens' => 1000, 'output_tokens' => 800],
        ])]);

        $response = $this->client()->respond($this->request());

        $this->assertTrue($response->isIncomplete());
        $this->assertSame('max_output_tokens', $response->incompleteReason);
        $this->assertNull($response->json());
    }

    public function test_a_429_is_retried_once(): void
    {
        Http::fakeSequence(self::URL)
            ->push(['error' => ['type' => 'rate_limit_exceeded']], 429)
            ->push($this->completed('{"ok":true}'));

        $response = $this->client()->respond($this->request());

        $this->assertSame(['ok' => true], $response->json());
        Http::assertSentCount(2);
        Sleep::assertSleptTimes(1);
    }

    public function test_server_errors_fail_as_provider_unavailable_after_one_retry(): void
    {
        Http::fakeSequence(self::URL)
            ->push(['error' => ['type' => 'server_error']], 500)
            ->push(['error' => ['type' => 'server_error']], 503);

        $this->assertAiError(AiException::PROVIDER_UNAVAILABLE, 503);
        Http::assertSentCount(2);
    }

    public function test_other_client_errors_are_not_retried(): void
    {
        Http::fake([self::URL => Http::response(['error' => ['type' => 'invalid_request_error', 'code' => 'invalid_json_schema']], 400)]);

        $this->assertAiError(AiException::PROVIDER_UNAVAILABLE, 503);
        Http::assertSentCount(1);
    }

    public function test_a_read_timeout_is_an_ai_timeout_without_retry(): void
    {
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;

            throw new ConnectionException('cURL error 28: Operation timed out after 20001 milliseconds with 0 bytes received');
        });

        $this->assertAiError(AiException::TIMEOUT, 504);
        $this->assertSame(1, $attempts);
    }

    public function test_a_connection_failure_is_retried(): void
    {
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            if (++$attempts === 1) {
                throw new ConnectionException('cURL error 7: Failed to connect to api.openai.test');
            }

            return Http::response($this->completed('{"ok":true}'));
        });

        $this->assertSame(['ok' => true], $this->client()->respond($this->request())->json());
        $this->assertSame(2, $attempts);
    }

    public function test_a_failed_response_status_is_provider_unavailable(): void
    {
        Http::fake([self::URL => Http::response(['status' => 'failed', 'error' => ['code' => 'server_error'], 'output' => []])]);

        $this->assertAiError(AiException::PROVIDER_UNAVAILABLE, 503);
    }

    public function test_without_an_api_key_nothing_is_sent(): void
    {
        Http::fake();

        try {
            $this->client(apiKey: '')->respond($this->request());
            $this->fail('Expected an AiException.');
        } catch (AiException $exception) {
            $this->assertSame(AiException::PROVIDER_UNAVAILABLE, $exception->errorCode);
        }

        Http::assertNothingSent();
    }

    public function test_an_expired_deadline_is_a_timeout_before_any_request(): void
    {
        Http::fake();

        try {
            $this->client()->respond(new LlmRequest('Rules.', [LlmMessage::user('x')], deadlineAt: microtime(true) - 1));
            $this->fail('Expected an AiException.');
        } catch (AiException $exception) {
            $this->assertSame(AiException::TIMEOUT, $exception->errorCode);
        }

        Http::assertNothingSent();
    }

    public function test_the_attempt_timeout_never_exceeds_the_remaining_deadline(): void
    {
        $timeouts = [];
        Http::fake(function (Request $request, array $options) use (&$timeouts) {
            $timeouts[] = $options['timeout'] ?? null;

            return Http::response($this->completed('{}'));
        });

        $this->client()->respond(new LlmRequest('Rules.', [LlmMessage::user('x')], deadlineAt: microtime(true) + 7.5));

        $this->assertSame([7], $timeouts);
    }

    private function client(string $apiKey = 'sk-test-secret'): OpenAiResponsesClient
    {
        return new OpenAiResponsesClient(
            apiKey: $apiKey,
            baseUrl: 'https://api.openai.test/v1',
            model: 'gpt-6-luna',
            timeoutSeconds: 20,
            connectTimeoutSeconds: 5,
            retries: 1,
            retryDelayMs: 10,
        );
    }

    private function request(): LlmRequest
    {
        return new LlmRequest('Rules.', [LlmMessage::user('Context')]);
    }

    /**
     * @param  array<string, mixed>|null  $usage
     * @return array<string, mixed>
     */
    private function completed(string $text, ?array $usage = null): array
    {
        return [
            'id' => 'resp_123',
            'object' => 'response',
            'status' => 'completed',
            'incomplete_details' => null,
            'model' => 'gpt-6-luna-2026-07-01',
            'output' => [[
                'id' => 'msg_1',
                'type' => 'message',
                'role' => 'assistant',
                'content' => [['type' => 'output_text', 'text' => $text, 'annotations' => []]],
            ]],
            'usage' => $usage ?? ['input_tokens' => 100, 'output_tokens' => 20, 'total_tokens' => 120],
        ];
    }

    private function assertAiError(string $code, int $status): void
    {
        try {
            $this->client()->respond($this->request());
            $this->fail('Expected an AiException.');
        } catch (AiException $exception) {
            $this->assertSame($code, $exception->errorCode);
            $this->assertSame($status, $exception->httpStatus);
        }
    }
}
