<?php

namespace App\Services\Ai;

use App\Data\Ai\LlmUsage;

/**
 * Estimated cost in micro-dollars from config/ai.php prices (USD per 1M tokens,
 * so tokens x price is already micro-dollars). An estimate: the provider's
 * billing is the source of truth.
 */
final class AiCostEstimator
{
    public function costMicros(string $model, LlmUsage $usage): int
    {
        $prices = $this->pricesFor($model);
        $uncachedInput = max(0, $usage->inputTokens - $usage->cachedInputTokens);

        return (int) round(
            $uncachedInput * $prices['input']
            + min($usage->cachedInputTokens, $usage->inputTokens) * $prices['cached_input']
            + $usage->outputTokens * $prices['output']
        );
    }

    /**
     * Providers answer with dated snapshots (gpt-6-luna-2026-07-01), so a price
     * applies to every model name that starts with its key. The AI_PRICE_*
     * overrides apply to the configured model.
     *
     * @return array{input: float, cached_input: float, output: float}
     */
    public function pricesFor(string $model): array
    {
        $prices = ['input' => 0.0, 'cached_input' => 0.0, 'output' => 0.0];
        $matched = '';

        foreach ((array) config('ai.pricing') as $name => $modelPrices) {
            if (str_starts_with($model, (string) $name) && strlen((string) $name) > strlen($matched)) {
                $matched = (string) $name;
                $prices = array_merge($prices, array_map('floatval', $modelPrices));
            }
        }

        $configured = (string) config('ai.model');

        if ($configured !== '' && str_starts_with($model, $configured)) {
            foreach ((array) config('ai.price_overrides') as $key => $value) {
                if ($value !== null && $value !== '' && array_key_exists($key, $prices)) {
                    $prices[$key] = (float) $value;
                }
            }
        }

        return $prices;
    }
}
