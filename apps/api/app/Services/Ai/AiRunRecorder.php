<?php

namespace App\Services\Ai;

use App\Data\Ai\AiContext;
use App\Data\Ai\LlmUsage;
use App\Models\AiRun;
use Illuminate\Support\Facades\Log;

/**
 * Writes one ai_runs row and one `event=ai.run` log line per run, following the
 * ingestion log pattern. Neither ever contains the prompt, the question, the
 * context or the answer.
 */
final class AiRunRecorder
{
    public function __construct(
        private readonly AiCostEstimator $costs,
    ) {}

    public function record(
        AiContext $context,
        string $feature,
        string $status,
        string $provider,
        string $model,
        ?string $promptVersion = null,
        ?LlmUsage $usage = null,
        int $latencyMs = 0,
        int $toolCalls = 0,
        int $attempts = 0,
        ?string $errorCode = null,
    ): AiRun {
        $usage ??= new LlmUsage;

        $run = new AiRun([
            'organization_id' => $context->organization->id,
            'user_id' => $context->user?->id,
            'feature' => $feature,
            'provider' => $provider,
            'model' => $model,
            'prompt_version' => $promptVersion,
            'status' => $status,
            'input_tokens' => $usage->inputTokens,
            'cached_input_tokens' => $usage->cachedInputTokens,
            'output_tokens' => $usage->outputTokens,
            'cost_micros' => $this->costs->costMicros($model, $usage),
            'latency_ms' => max(0, $latencyMs),
            'tool_calls' => $toolCalls,
            'attempts' => $attempts,
            'error_code' => $errorCode,
            'request_id' => $context->requestId,
        ]);
        $run->save();

        Log::info('AI run completed.', [
            'event' => 'ai.run',
            'ai_run_id' => $run->id,
            'organization_id' => $run->organization_id,
            'user_id' => $run->user_id,
            'feature' => $feature,
            'status' => $status,
            'provider' => $provider,
            'model' => $model,
            'prompt_version' => $promptVersion,
            'input_tokens' => $usage->inputTokens,
            'cached_input_tokens' => $usage->cachedInputTokens,
            'output_tokens' => $usage->outputTokens,
            'cost_micros' => $run->cost_micros,
            'latency_ms' => $run->latency_ms,
            'tool_calls' => $toolCalls,
            'attempts' => $attempts,
            'error_code' => $errorCode,
        ]);

        return $run;
    }
}
