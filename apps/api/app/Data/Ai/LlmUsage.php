<?php

namespace App\Data\Ai;

final readonly class LlmUsage
{
    public function __construct(
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        public int $cachedInputTokens = 0,
    ) {}

    public function add(self $other): self
    {
        return new self(
            $this->inputTokens + $other->inputTokens,
            $this->outputTokens + $other->outputTokens,
            $this->cachedInputTokens + $other->cachedInputTokens,
        );
    }
}
