<?php

namespace Tests\Unit\Ai;

use App\Data\Ai\LlmUsage;
use App\Services\Ai\AiCostEstimator;
use Tests\TestCase;

class AiCostEstimatorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ai.model' => 'gpt-6-luna',
            'ai.pricing' => [
                'gpt-6-luna' => ['input' => 0.10, 'cached_input' => 0.01, 'output' => 0.50],
                'gpt-6.1-sol' => ['input' => 2.00, 'cached_input' => 0.10, 'output' => 10.00],
            ],
            'ai.price_overrides' => ['input' => null, 'cached_input' => null, 'output' => null],
        ]);
    }

    public function test_tokens_times_the_price_per_million_is_micro_dollars(): void
    {
        $costs = new AiCostEstimator;

        // 3000 x 0.10 + 1000 x 0.01 (cached) + 500 x 0.50 = 300 + 10 + 250
        $this->assertSame(560, $costs->costMicros('gpt-6-luna', new LlmUsage(4000, 500, 1000)));
        // 4000 x 2.00 + 500 x 10.00
        $this->assertSame(13000, $costs->costMicros('gpt-6.1-sol', new LlmUsage(4000, 500)));
    }

    public function test_dated_snapshots_use_the_price_of_their_model(): void
    {
        $this->assertSame(
            (new AiCostEstimator)->costMicros('gpt-6-luna', new LlmUsage(1000, 100)),
            (new AiCostEstimator)->costMicros('gpt-6-luna-2026-07-01', new LlmUsage(1000, 100)),
        );
    }

    public function test_unknown_models_cost_zero_instead_of_failing(): void
    {
        $this->assertSame(0, (new AiCostEstimator)->costMicros('scripted', new LlmUsage(1000, 100)));
    }

    public function test_env_overrides_apply_to_the_configured_model_only(): void
    {
        config(['ai.price_overrides' => ['input' => '1.00', 'cached_input' => null, 'output' => '2.00']]);

        $this->assertSame(1200, (new AiCostEstimator)->costMicros('gpt-6-luna', new LlmUsage(1000, 100)));
        $this->assertSame(3000, (new AiCostEstimator)->costMicros('gpt-6.1-sol', new LlmUsage(1000, 100)));
    }
}
