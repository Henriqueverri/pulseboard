<?php

namespace App\Data\Ai;

/**
 * The JSON Schema the final answer must follow (structured outputs, strict mode).
 */
final readonly class OutputSchema
{
    /**
     * @param  array<string, mixed>  $schema
     */
    public function __construct(
        public string $name,
        public array $schema,
    ) {}
}
