<?php

namespace App\Services\Ai\Evaluation;

/**
 * The outcome of one execution of a case: the checks of every evaluator, the
 * first attempt (schema adherence before any repair), telemetry from ai_runs
 * and, when recording, the fixture of the provider's answers.
 */
final class EvalRun
{
    public const GROUP_EXPECTATION = 'expectation';

    public const GROUP_SCHEMA = 'schema';

    public const GROUP_FACTUALITY = 'factuality';

    public const GROUP_HALLUCINATION = 'hallucination';

    public const GROUP_SAFETY = 'safety';

    /** @var list<array{group: string, name: string, passed: bool, detail: string}> */
    public array $checks = [];

    /**
     * @param  array{valid: bool, errors: list<string>}|null  $firstAttempt  null when the provider was not reached or the case forces its answers
     * @param  array<string, mixed>|null  $displayed  the answer shown to the user (stored insight + server-side evidence and caveats)
     * @param  array<string, mixed>|null  $fixture
     */
    public function __construct(
        public readonly EvalCase $case,
        public readonly int $repetition,
        public readonly string $outcome,
        public readonly ?array $firstAttempt = null,
        public readonly ?array $displayed = null,
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
        public readonly int $costMicros = 0,
        public readonly int $latencyMs = 0,
        public readonly int $attempts = 0,
        public readonly ?array $fixture = null,
    ) {}

    public function check(string $group, string $name, bool $passed, string $detail = ''): void
    {
        $this->checks[] = ['group' => $group, 'name' => $name, 'passed' => $passed, 'detail' => $passed ? '' : $detail];
    }

    public function passed(?string $group = null): bool
    {
        foreach ($this->checks as $check) {
            if (($group === null || $check['group'] === $group) && ! $check['passed']) {
                return false;
            }
        }

        return true;
    }

    public function has(string $group): bool
    {
        foreach ($this->checks as $check) {
            if ($check['group'] === $group) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{group: string, name: string, passed: bool, detail: string}>
     */
    public function failures(): array
    {
        return array_values(array_filter($this->checks, fn (array $check) => ! $check['passed']));
    }
}
