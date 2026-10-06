<?php

namespace App\Services\Ai\Evaluation;

use App\Data\Ai\LlmMessage;
use App\Data\Ai\LlmRequest;
use App\Data\Ai\LlmResponse;
use App\Exceptions\AiException;
use App\Services\Ai\LlmClient;

/**
 * Wraps the provider under evaluation and keeps every request and answer (or
 * failure) of a run, so the evaluators can inspect the exact payload and each
 * attempt, including the ones the service discarded before repairing.
 */
final class RecordingLlmClient implements LlmClient
{
    /** @var list<LlmRequest> */
    private array $requests = [];

    /** @var list<LlmResponse|AiException> */
    private array $answers = [];

    public function __construct(
        private readonly LlmClient $inner,
    ) {}

    public function provider(): string
    {
        return $this->inner->provider();
    }

    public function respond(LlmRequest $request): LlmResponse
    {
        $this->requests[] = $request;

        try {
            return $this->answers[] = $this->inner->respond($request);
        } catch (AiException $exception) {
            $this->answers[] = $exception;

            throw $exception;
        }
    }

    /**
     * @return list<LlmRequest>
     */
    public function requests(): array
    {
        return $this->requests;
    }

    /**
     * @return list<LlmResponse|AiException>
     */
    public function answers(): array
    {
        return $this->answers;
    }

    /**
     * Everything sent to the provider in this run, as one string to search.
     */
    public function payload(): string
    {
        $parts = [];

        foreach ($this->requests as $request) {
            $parts[] = $request->instructions;

            foreach ($request->input as $item) {
                $parts[] = $item instanceof LlmMessage ? $item->content : $item->call->arguments."\n".$item->output;
            }

            $parts[] = json_encode($request->outputSchema?->schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return implode("\n", $parts);
    }
}
