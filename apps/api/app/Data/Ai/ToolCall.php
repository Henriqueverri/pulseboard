<?php

namespace App\Data\Ai;

/**
 * A function call requested by the model. The arguments are the raw JSON the
 * model produced: untrusted until the tool registry validates them.
 */
final readonly class ToolCall
{
    public function __construct(
        public string $id,
        public string $name,
        public string $arguments,
    ) {}
}
