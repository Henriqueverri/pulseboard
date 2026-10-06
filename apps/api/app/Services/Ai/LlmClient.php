<?php

namespace App\Services\Ai;

use App\Data\Ai\LlmRequest;
use App\Data\Ai\LlmResponse;
use App\Exceptions\AiException;

/**
 * The provider boundary. Insights services depend on this interface, so tests
 * and E2E use ScriptedLlmClient and never reach the network.
 */
interface LlmClient
{
    /**
     * @throws AiException ai_provider_unavailable or ai_timeout
     */
    public function respond(LlmRequest $request): LlmResponse;

    /**
     * Provider name recorded in ai_runs (openai, scripted).
     */
    public function provider(): string;
}
