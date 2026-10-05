<?php

namespace App\Data\Ai;

/**
 * One call to the model. Only what PulseBoard uses: instructions, an ordered
 * input of messages and tool exchanges, an optional output schema and tools.
 */
final readonly class LlmRequest
{
    /**
     * @param  list<LlmMessage|ToolExchange>  $input
     * @param  list<ToolDefinition>  $tools
     * @param  float|null  $deadlineAt  unix time (microtime) by which the call must finish
     */
    public function __construct(
        public string $instructions,
        public array $input,
        public ?OutputSchema $outputSchema = null,
        public array $tools = [],
        public int $maxOutputTokens = 800,
        public ?float $deadlineAt = null,
    ) {}

    /**
     * @param  list<LlmMessage|ToolExchange>  $items
     */
    public function withAppendedInput(array $items): self
    {
        return new self(
            $this->instructions,
            [...$this->input, ...$items],
            $this->outputSchema,
            $this->tools,
            $this->maxOutputTokens,
            $this->deadlineAt,
        );
    }
}
