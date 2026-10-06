<?php

namespace App\Services\Ai\Insights;

use App\Data\Ai\AiContext;
use App\Data\Ai\LlmMessage;
use App\Data\Ai\LlmRequest;
use App\Data\Ai\LlmResponse;
use App\Data\Ai\LlmUsage;
use App\Data\Ai\PeriodSummaryContext;
use App\Data\Ai\PeriodSummaryResult;
use App\Exceptions\AiException;
use App\Models\AiInsight;
use App\Models\AiRun;
use App\Services\Ai\AiRunRecorder;
use App\Services\Ai\AiUsageGuard;
use App\Services\Ai\LlmClient;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/**
 * "Smart summary of the period": guard -> context -> fingerprint -> cache ->
 * quota -> lock -> model -> validation (one repair attempt) -> cache -> telemetry.
 *
 * The context comes from the analytics services, so the numbers next to the
 * text are the dashboard's. A cached answer is served without reaching the
 * provider (and without consuming quota); an invalid answer is never stored.
 */
final class PeriodSummaryService
{
    public const PROMPT_VERSION = 'period_summary.v1';

    private const MAX_ATTEMPTS = 2;

    private const LOCK_PREFIX = 'ai:period-summary:';

    private ?string $instructions = null;

    public function __construct(
        private readonly LlmClient $client,
        private readonly PeriodSummaryContextBuilder $builder,
        private readonly PeriodSummaryValidator $validator,
        private readonly AiUsageGuard $guard,
        private readonly AiRunRecorder $recorder,
    ) {}

    /**
     * The summary already generated for this exact data, if any. Never calls the provider.
     *
     * @throws AiException ai_disabled or ai_not_enabled
     */
    public function cached(AiContext $context): PeriodSummaryResult
    {
        $this->guard->ensureAvailable($context);
        $summary = $this->builder->build($context);

        return new PeriodSummaryResult($summary, $this->find($context, $summary), true);
    }

    /**
     * @throws AiException
     */
    public function generate(AiContext $context): PeriodSummaryResult
    {
        $startedAt = microtime(true);
        $deadlineAt = $startedAt + (int) config('ai.deadline_seconds');

        $this->guard->ensureAvailable($context);
        $summary = $this->builder->build($context);

        if (($insight = $this->find($context, $summary)) !== null) {
            return $this->cacheHit($context, $summary, $insight, $startedAt);
        }

        $this->guard->authorize($context, AiRun::FEATURE_PERIOD_SUMMARY);

        // One generation per (organization, data): a double click waits for the first one.
        $lock = Cache::lock(
            self::LOCK_PREFIX.$context->organization->id.':'.$this->fingerprint($summary),
            (int) config('ai.deadline_seconds') + 5,
        );

        try {
            $lock->block(max(0, (int) floor($deadlineAt - microtime(true))));
        } catch (LockTimeoutException) {
            throw AiException::timeout();
        }

        try {
            if (($insight = $this->find($context, $summary)) !== null) {
                return $this->cacheHit($context, $summary, $insight, $startedAt);
            }

            return new PeriodSummaryResult($summary, $this->ask($context, $summary, $startedAt, $deadlineAt), false);
        } finally {
            $lock->release();
        }
    }

    private function ask(AiContext $context, PeriodSummaryContext $summary, float $startedAt, float $deadlineAt): AiInsight
    {
        $request = new LlmRequest(
            instructions: $this->instructions(),
            input: [LlmMessage::user($summary->promptInput())],
            outputSchema: PeriodSummarySchema::for($summary->catalog),
            maxOutputTokens: (int) config('ai.max_output_tokens.period_summary'),
            deadlineAt: $deadlineAt,
        );

        $usage = new LlmUsage;
        $model = (string) config('ai.model');
        $attempts = 0;

        while (true) {
            $attempts++;
            $response = $this->respond($request, $context, $usage, $attempts, $startedAt);
            $usage = $usage->add($response->usage);
            $model = $response->model;

            if ($response->isRefusal()) {
                $this->record($context, AiRun::STATUS_REFUSED, $model, $usage, $attempts, $startedAt, AiException::INVALID_OUTPUT);

                throw AiException::invalidOutput();
            }

            $output = $response->isIncomplete() ? null : $response->json();
            $errors = $output === null
                ? ['The answer must be one complete JSON object following the schema.']
                : $this->validator->validate($output, $summary->catalog);

            if ($errors === []) {
                break;
            }

            if ($attempts >= self::MAX_ATTEMPTS) {
                $this->record($context, AiRun::STATUS_INVALID_OUTPUT, $model, $usage, $attempts, $startedAt, AiException::INVALID_OUTPUT);

                throw AiException::invalidOutput();
            }

            $request = $request->withAppendedInput([
                LlmMessage::assistant($response->outputText ?? ''),
                LlmMessage::user($this->repairMessage($errors)),
            ]);
        }

        $insight = AiInsight::query()->createOrFirst(
            [
                'organization_id' => $context->organization->id,
                'kind' => AiInsight::KIND_PERIOD_SUMMARY,
                'fingerprint' => $this->fingerprint($summary),
            ],
            [
                'model' => $model,
                'prompt_version' => self::PROMPT_VERSION,
                'content' => $output,
            ],
        );

        $this->record($context, AiRun::STATUS_SUCCEEDED, $model, $usage, $attempts, $startedAt);

        return $insight;
    }

    /**
     * Provider failures are recorded with the usage of earlier attempts, then rethrown.
     */
    private function respond(LlmRequest $request, AiContext $context, LlmUsage $usage, int $attempt, float $startedAt): LlmResponse
    {
        try {
            return $this->client->respond($request);
        } catch (AiException $exception) {
            $status = $exception->errorCode === AiException::TIMEOUT ? AiRun::STATUS_TIMEOUT : AiRun::STATUS_PROVIDER_ERROR;
            $this->record($context, $status, (string) config('ai.model'), $usage, $attempt, $startedAt, $exception->errorCode);

            throw $exception;
        }
    }

    private function cacheHit(AiContext $context, PeriodSummaryContext $summary, AiInsight $insight, float $startedAt): PeriodSummaryResult
    {
        $this->record($context, AiRun::STATUS_CACHE_HIT, $insight->model, new LlmUsage, 0, $startedAt);

        return new PeriodSummaryResult($summary, $insight, true);
    }

    private function find(AiContext $context, PeriodSummaryContext $summary): ?AiInsight
    {
        return AiInsight::query()
            ->forOrganization($context->organization)
            ->where('kind', AiInsight::KIND_PERIOD_SUMMARY)
            ->where('fingerprint', $this->fingerprint($summary))
            ->first();
    }

    /**
     * The provider and configured model are part of the key, so switching either regenerates.
     */
    private function fingerprint(PeriodSummaryContext $summary): string
    {
        return $summary->fingerprint(self::PROMPT_VERSION, $this->client->provider().'/'.config('ai.model'));
    }

    private function record(AiContext $context, string $status, string $model, LlmUsage $usage, int $attempts, float $startedAt, ?string $errorCode = null): void
    {
        $this->recorder->record(
            $context,
            AiRun::FEATURE_PERIOD_SUMMARY,
            $status,
            $this->client->provider(),
            $model,
            self::PROMPT_VERSION,
            $usage,
            latencyMs: (int) round((microtime(true) - $startedAt) * 1000),
            attempts: $attempts,
            errorCode: $errorCode,
        );
    }

    /**
     * @param  list<string>  $errors
     */
    private function repairMessage(array $errors): string
    {
        return "Sua resposta anterior não passou na validação:\n- ".implode("\n- ", $errors)
            ."\nCorrija e responda novamente apenas com o JSON do schema, sem algarismos nos textos e citando só referências disponíveis.";
    }

    private function instructions(): string
    {
        return $this->instructions ??= (string) file_get_contents(resource_path('ai/prompts/'.self::PROMPT_VERSION.'.md'));
    }
}
