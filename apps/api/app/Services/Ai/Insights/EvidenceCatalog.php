<?php

namespace App\Services\Ai\Insights;

use App\Data\Ai\Evidence;
use InvalidArgumentException;

/**
 * The metrics an insight may cite, keyed by ref (kpi.revenue, product.1...).
 * Built from the analytics services' own results, it is the only source of
 * the numbers shown next to the model's text.
 */
final class EvidenceCatalog
{
    /** @var array<string, Evidence> */
    private array $entries = [];

    public function add(Evidence $evidence): self
    {
        if (isset($this->entries[$evidence->ref])) {
            throw new InvalidArgumentException("Duplicate evidence ref: {$evidence->ref}.");
        }

        $this->entries[$evidence->ref] = $evidence;

        return $this;
    }

    public function has(string $ref): bool
    {
        return isset($this->entries[$ref]);
    }

    public function get(string $ref): Evidence
    {
        return $this->entries[$ref] ?? throw new InvalidArgumentException("Unknown evidence ref: {$ref}.");
    }

    /**
     * @return list<string>
     */
    public function refs(): array
    {
        return array_keys($this->entries);
    }

    /**
     * @return array<string, Evidence>
     */
    public function all(): array
    {
        return $this->entries;
    }
}
