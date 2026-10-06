<?php

namespace App\Data\Ai;

/**
 * A tool call and the output the server returned for it, replayed to the model
 * on the next round.
 */
final readonly class ToolExchange
{
    public function __construct(
        public ToolCall $call,
        public string $output,
    ) {}
}
