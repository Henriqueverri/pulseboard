<?php

namespace App\Services\Ai\OpenAi;

use App\Data\Ai\LlmMessage;
use App\Data\Ai\LlmRequest;
use App\Data\Ai\LlmResponse;
use App\Data\Ai\LlmUsage;
use App\Data\Ai\ToolCall;
use App\Data\Ai\ToolDefinition;
use App\Data\Ai\ToolExchange;
use App\Exceptions\AiException;
use App\Services\Ai\LlmClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

/**
 * OpenAI Responses API (POST /responses) over Laravel's HTTP client:
 * structured outputs via text.format json_schema (strict), strict function
 * tools, and function_call / function_call_output items for tool rounds.
 *
 * Requests are sent with store=false, so OpenAI does not keep the response for
 * later retrieval. Only connection failures, 429 and 5xx are retried; a read
 * timeout is not, because the deadline would not allow a second full attempt.
 */
final class OpenAiResponsesClient implements LlmClient
{
    public function __construct(
        private readonly ?string $apiKey,
        private readonly string $baseUrl,
        private readonly string $model,
        private readonly int $timeoutSeconds,
        private readonly int $connectTimeoutSeconds,
        private readonly int $retries,
        private readonly int $retryDelayMs,
    ) {}

    public function provider(): string
    {
        return 'openai';
    }

    public function respond(LlmRequest $request): LlmResponse
    {
        if ($this->apiKey === null || $this->apiKey === '') {
            Log::error('OpenAI API key is not configured.', ['event' => 'ai.provider_misconfigured']);

            throw AiException::providerUnavailable();
        }

        $payload = $this->payload($request);
        $attempt = 0;

        while (true) {
            $attempt++;
            $timeout = $this->attemptTimeout($request);

            try {
                $response = Http::baseUrl($this->baseUrl)
                    ->withToken($this->apiKey)
                    ->acceptJson()
                    ->asJson()
                    ->timeout($timeout)
                    ->connectTimeout(min($this->connectTimeoutSeconds, $timeout))
                    ->post('/responses', $payload);
            } catch (ConnectionException $exception) {
                if ($this->isTimeout($exception)) {
                    throw AiException::timeout();
                }

                if ($attempt <= $this->retries) {
                    Sleep::for($this->retryDelayMs)->milliseconds();

                    continue;
                }

                throw AiException::providerUnavailable();
            }

            if ($response->successful()) {
                return $this->parse($response);
            }

            $retryable = $response->status() === 429 || $response->serverError();

            if ($retryable && $attempt <= $this->retries) {
                Sleep::for($this->retryDelayMs)->milliseconds();

                continue;
            }

            // Status and error type only: an error body may echo request content.
            Log::warning('OpenAI request failed.', [
                'event' => 'ai.provider_error',
                'http_status' => $response->status(),
                'error_type' => $response->json('error.type'),
                'error_code' => $response->json('error.code'),
                'attempts' => $attempt,
            ]);

            throw AiException::providerUnavailable();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(LlmRequest $request): array
    {
        $payload = [
            'model' => $this->model,
            'instructions' => $request->instructions,
            'input' => $this->input($request->input),
            'max_output_tokens' => $request->maxOutputTokens,
            'store' => false,
        ];

        if ($request->outputSchema !== null) {
            $payload['text'] = [
                'format' => [
                    'type' => 'json_schema',
                    'name' => $request->outputSchema->name,
                    'schema' => $request->outputSchema->schema,
                    'strict' => true,
                ],
            ];
        }

        if ($request->tools !== []) {
            $payload['tools'] = array_map(fn (ToolDefinition $tool): array => [
                'type' => 'function',
                'name' => $tool->name,
                'description' => $tool->description,
                'parameters' => $tool->parameters,
                'strict' => true,
            ], $request->tools);
        }

        return $payload;
    }

    /**
     * @param  list<LlmMessage|ToolExchange>  $items
     * @return list<array<string, string>>
     */
    private function input(array $items): array
    {
        $input = [];

        foreach ($items as $item) {
            if ($item instanceof LlmMessage) {
                $input[] = ['role' => $item->role, 'content' => $item->content];

                continue;
            }

            $input[] = [
                'type' => 'function_call',
                'call_id' => $item->call->id,
                'name' => $item->call->name,
                'arguments' => $item->call->arguments,
            ];
            $input[] = [
                'type' => 'function_call_output',
                'call_id' => $item->call->id,
                'output' => $item->output,
            ];
        }

        return $input;
    }

    private function parse(Response $response): LlmResponse
    {
        $status = $response->json('status');

        if ($status === 'failed' || ! is_array($response->json('output'))) {
            Log::warning('OpenAI response failed.', [
                'event' => 'ai.provider_error',
                'http_status' => $response->status(),
                'error_code' => $response->json('error.code'),
            ]);

            throw AiException::providerUnavailable();
        }

        $text = null;
        $refusal = null;
        $toolCalls = [];

        foreach ($response->json('output') as $item) {
            $type = $item['type'] ?? null;

            if ($type === 'function_call') {
                $toolCalls[] = new ToolCall(
                    (string) ($item['call_id'] ?? ''),
                    (string) ($item['name'] ?? ''),
                    (string) ($item['arguments'] ?? ''),
                );

                continue;
            }

            if ($type !== 'message') {
                continue;
            }

            foreach ($item['content'] ?? [] as $content) {
                match ($content['type'] ?? null) {
                    'output_text' => $text = ($text ?? '').($content['text'] ?? ''),
                    'refusal' => $refusal = (string) ($content['refusal'] ?? ''),
                    default => null,
                };
            }
        }

        return new LlmResponse(
            model: (string) ($response->json('model') ?? $this->model),
            usage: new LlmUsage(
                (int) $response->json('usage.input_tokens', 0),
                (int) $response->json('usage.output_tokens', 0),
                (int) $response->json('usage.input_tokens_details.cached_tokens', 0),
            ),
            outputText: $text,
            toolCalls: $toolCalls,
            refusal: $refusal,
            incompleteReason: $status === 'incomplete'
                ? (string) ($response->json('incomplete_details.reason') ?? 'unknown')
                : null,
        );
    }

    private function attemptTimeout(LlmRequest $request): int
    {
        if ($request->deadlineAt === null) {
            return $this->timeoutSeconds;
        }

        $remaining = (int) floor($request->deadlineAt - microtime(true));

        if ($remaining < 1) {
            throw AiException::timeout();
        }

        return min($this->timeoutSeconds, $remaining);
    }

    /**
     * cURL error 28 covers both phases: "Connection timed out" (retryable, like any
     * connection failure) and "Operation timed out" (the provider is slow to answer).
     */
    private function isTimeout(ConnectionException $exception): bool
    {
        return str_contains(strtolower($exception->getMessage()), 'operation timed out');
    }
}
