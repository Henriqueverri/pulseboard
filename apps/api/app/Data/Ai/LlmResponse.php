<?php

namespace App\Data\Ai;

/**
 * The model's answer: either tool calls, a final output (JSON text when a
 * schema was requested), a refusal or an incomplete output.
 */
final readonly class LlmResponse
{
    /**
     * @param  list<ToolCall>  $toolCalls
     */
    public function __construct(
        public string $model,
        public LlmUsage $usage,
        public ?string $outputText = null,
        public array $toolCalls = [],
        public ?string $refusal = null,
        public ?string $incompleteReason = null,
    ) {}

    public function hasToolCalls(): bool
    {
        return $this->toolCalls !== [];
    }

    public function isRefusal(): bool
    {
        return $this->refusal !== null;
    }

    public function isIncomplete(): bool
    {
        return $this->incompleteReason !== null;
    }

    /**
     * The output decoded as a JSON object, or null when it is missing or not an object.
     *
     * @return array<string, mixed>|null
     */
    public function json(): ?array
    {
        if ($this->outputText === null) {
            return null;
        }

        $decoded = json_decode($this->outputText, true);

        return is_array($decoded) && ! array_is_list($decoded) ? $decoded : null;
    }
}
