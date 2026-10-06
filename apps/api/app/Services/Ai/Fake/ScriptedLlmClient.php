<?php

namespace App\Services\Ai\Fake;

use App\Data\Ai\LlmRequest;
use App\Data\Ai\LlmResponse;
use App\Data\Ai\LlmUsage;
use App\Data\Ai\ToolCall;
use App\Data\Ai\ToolDefinition;
use App\Data\Ai\ToolExchange;
use App\Exceptions\AiException;
use App\Services\Ai\LlmClient;
use Closure;
use LogicException;

/**
 * Deterministic LlmClient for tests, local development without a key and the
 * E2E job (AI_PROVIDER=scripted). It never touches the network.
 *
 * Tests queue steps (outputs, tool calls, refusals, failures) and inspect the
 * requests that were sent. With an empty queue it answers by itself: when tools
 * are offered it first calls one of them, then returns a deterministic instance
 * of the output schema. The schema-derived answer has no digits and prefers the
 * "neutral" value of any enum, so it passes the semantic validators.
 */
final class ScriptedLlmClient implements LlmClient
{
    public const MODEL = 'scripted';

    public const PLACEHOLDER_TEXT = 'Resposta de demonstração gerada pelo provedor roteirizado, sem modelo de linguagem.';

    /** @var list<LlmResponse|AiException|Closure(LlmRequest): LlmResponse> */
    private array $script = [];

    /** @var list<LlmRequest> */
    private array $requests = [];

    private int $callSequence = 0;

    public function __construct(bool $production = false)
    {
        if ($production) {
            throw new LogicException('AI_PROVIDER=scripted is not allowed in production.');
        }
    }

    public function provider(): string
    {
        return 'scripted';
    }

    /**
     * @param  LlmResponse|AiException|Closure(LlmRequest): LlmResponse  $step
     */
    public function push(LlmResponse|AiException|Closure $step): self
    {
        $this->script[] = $step;

        return $this;
    }

    /**
     * @param  array<string, mixed>|string  $output  a JSON object, or raw text (for malformed output)
     */
    public function pushOutput(array|string $output, ?LlmUsage $usage = null): self
    {
        return $this->push(new LlmResponse(
            model: self::MODEL,
            usage: $usage ?? new LlmUsage(1000, 200),
            outputText: is_string($output) ? $output : json_encode($output, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ));
    }

    /**
     * @param  list<array{0: string, 1: array<string, mixed>|string}>  $calls  tool name and arguments (array or raw JSON)
     */
    public function pushToolCalls(array $calls, ?LlmUsage $usage = null): self
    {
        return $this->push(new LlmResponse(
            model: self::MODEL,
            usage: $usage ?? new LlmUsage(800, 50),
            toolCalls: array_map(fn (array $call): ToolCall => new ToolCall(
                'call_'.(++$this->callSequence),
                $call[0],
                is_string($call[1]) ? $call[1] : json_encode((object) $call[1], JSON_THROW_ON_ERROR),
            ), $calls),
        ));
    }

    public function pushRefusal(string $message = 'I cannot help with that.'): self
    {
        return $this->push(new LlmResponse(self::MODEL, new LlmUsage(500, 10), refusal: $message));
    }

    public function pushIncomplete(string $reason = 'max_output_tokens'): self
    {
        return $this->push(new LlmResponse(self::MODEL, new LlmUsage(1000, 800), outputText: '{"headline":', incompleteReason: $reason));
    }

    public function pushFailure(AiException $exception): self
    {
        return $this->push($exception);
    }

    public function respond(LlmRequest $request): LlmResponse
    {
        $this->requests[] = $request;
        $step = array_shift($this->script);

        if ($step instanceof AiException) {
            throw $step;
        }

        if ($step instanceof Closure) {
            return $step($request);
        }

        return $step ?? $this->defaultResponse($request);
    }

    /**
     * @return list<LlmRequest>
     */
    public function requests(): array
    {
        return $this->requests;
    }

    public function pendingSteps(): int
    {
        return count($this->script);
    }

    private function defaultResponse(LlmRequest $request): LlmResponse
    {
        $called = [];

        foreach ($request->input as $item) {
            if ($item instanceof ToolExchange) {
                $called[$item->call->name] = true;
            }
        }

        $pending = array_values(array_filter($request->tools, fn (ToolDefinition $tool) => ! isset($called[$tool->name])));

        if ($pending !== [] && $called === []) {
            return new LlmResponse(
                model: self::MODEL,
                usage: new LlmUsage(800, 40),
                toolCalls: [new ToolCall(
                    'call_'.(++$this->callSequence),
                    $pending[0]->name,
                    json_encode((object) self::exampleOf($pending[0]->parameters), JSON_THROW_ON_ERROR),
                )],
            );
        }

        $output = $request->outputSchema === null ? [] : self::exampleOf($request->outputSchema->schema);

        return new LlmResponse(
            model: self::MODEL,
            usage: new LlmUsage(1200, 250),
            outputText: json_encode((object) $output, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        );
    }

    /**
     * A deterministic instance of a (strict-mode subset) JSON Schema.
     *
     * @param  array<string, mixed>  $schema
     */
    public static function exampleOf(array $schema): mixed
    {
        $types = (array) ($schema['type'] ?? 'object');
        $nonNull = array_values(array_diff($types, ['null']));

        if (isset($schema['enum'])) {
            $values = array_values(array_filter($schema['enum'], fn ($value) => $value !== null));

            return in_array('neutral', $values, true) ? 'neutral' : ($values[0] ?? null);
        }

        // Optional values (nullable) stay null: "no filter", "screen period".
        if ($nonNull !== $types) {
            return null;
        }

        return match ($nonNull[0] ?? 'object') {
            'object' => array_map(fn (array $property) => self::exampleOf($property), $schema['properties'] ?? []),
            'array' => array_fill(0, max(0, (int) ($schema['minItems'] ?? 0)), self::exampleOf($schema['items'] ?? ['type' => 'string'])),
            'string' => self::PLACEHOLDER_TEXT,
            'integer', 'number' => (int) ($schema['minimum'] ?? 0),
            'boolean' => false,
            default => null,
        };
    }
}
