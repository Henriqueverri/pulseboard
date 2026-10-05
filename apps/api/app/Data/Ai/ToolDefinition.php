<?php

namespace App\Data\Ai;

/**
 * A function the model may call. Parameters are a strict JSON Schema object
 * (every property required, additionalProperties false).
 */
final readonly class ToolDefinition
{
    /**
     * @param  array<string, mixed>  $parameters
     */
    public function __construct(
        public string $name,
        public string $description,
        public array $parameters,
    ) {}
}
