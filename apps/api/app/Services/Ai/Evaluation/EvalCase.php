<?php

namespace App\Services\Ai\Evaluation;

use App\Exceptions\AiException;
use InvalidArgumentException;

/**
 * One versioned evaluation case (tests/AiEval/cases/<feature>/*.json).
 *
 * Unknown keys are rejected, so a typo in an expectation fails loudly instead
 * of silently passing. `scripted` is what the scripted provider answers; it is
 * ignored with a real model, and cases that only make sense with forced answers
 * (malformed output, provider failure) list only "scripted" in `providers`.
 */
final readonly class EvalCase
{
    public const CATEGORY_QUALITY = 'quality';

    public const CATEGORY_SAFETY = 'safety';

    public const CATEGORY_ROBUSTNESS = 'robustness';

    public const OUTCOME_SUCCEEDED = 'succeeded';

    public const SETUP_INJECTION_PRODUCT = 'injection_product';

    public const SETUP_CROSS_TENANT_PRODUCT = 'cross_tenant_product';

    public const SETUP_RETROACTIVE_REFUND = 'retroactive_refund';

    private const KEYS = ['id', 'description', 'category', 'providers', 'organization', 'period', 'setup', 'scripted', 'expect', 'rubric'];

    private const EXPECT_KEYS = ['outcome', 'run_status', 'attempts', 'caveats_include', 'caveats_exclude', 'forbidden_kinds', 'forbidden_output', 'injection_marker', 'repair_requested', 'refund_reflected'];

    private const SCRIPTED_KINDS = ['output', 'raw', 'incomplete', 'refusal', 'failure'];

    private const OUTCOMES = [
        self::OUTCOME_SUCCEEDED,
        AiException::INVALID_OUTPUT,
        AiException::PROVIDER_UNAVAILABLE,
        AiException::TIMEOUT,
    ];

    /**
     * @param  list<string>  $providers
     * @param  array{from: string, to: string}  $period
     * @param  list<array{step: string, count?: int}>  $setup
     * @param  list<array<string, mixed>>  $scripted
     * @param  array<string, mixed>  $expect
     */
    public function __construct(
        public string $id,
        public string $description,
        public string $category,
        public array $providers,
        public string $organization,
        public array $period,
        public array $setup,
        public array $scripted,
        public array $expect,
        public ?string $rubric,
    ) {}

    public static function fromFile(string $path): self
    {
        $data = json_decode((string) file_get_contents($path), true);
        $name = basename($path);

        if (! is_array($data) || array_is_list($data)) {
            throw new InvalidArgumentException("{$name}: not a JSON object.");
        }

        self::onlyKeys($data, self::KEYS, $name);
        self::onlyKeys($data['expect'] ?? [], self::EXPECT_KEYS, "{$name} expect");

        foreach (['id', 'description', 'category', 'organization', 'period', 'expect'] as $required) {
            if (! isset($data[$required])) {
                throw new InvalidArgumentException("{$name}: missing \"{$required}\".");
            }
        }

        if (! in_array($data['category'], [self::CATEGORY_QUALITY, self::CATEGORY_SAFETY, self::CATEGORY_ROBUSTNESS], true)) {
            throw new InvalidArgumentException("{$name}: unknown category \"{$data['category']}\".");
        }

        if (! in_array($data['organization'], EvalEnvironment::ORGANIZATIONS, true)) {
            throw new InvalidArgumentException("{$name}: unknown organization \"{$data['organization']}\".");
        }

        if (! in_array($data['expect']['outcome'] ?? null, self::OUTCOMES, true)) {
            throw new InvalidArgumentException("{$name}: expect.outcome must be one of ".implode(', ', self::OUTCOMES).'.');
        }

        foreach ($data['scripted'] ?? [] as $index => $step) {
            if (! is_array($step) || count($step) !== 1 || ! in_array(array_key_first($step), self::SCRIPTED_KINDS, true)) {
                throw new InvalidArgumentException("{$name}: scripted[{$index}] must have exactly one of ".implode(', ', self::SCRIPTED_KINDS).'.');
            }
        }

        foreach ($data['setup'] ?? [] as $index => $step) {
            if (! in_array($step['step'] ?? null, [self::SETUP_INJECTION_PRODUCT, self::SETUP_CROSS_TENANT_PRODUCT, self::SETUP_RETROACTIVE_REFUND], true)) {
                throw new InvalidArgumentException("{$name}: setup[{$index}] has an unknown step.");
            }
        }

        return new self(
            id: (string) $data['id'],
            description: (string) $data['description'],
            category: (string) $data['category'],
            providers: $data['providers'] ?? ['scripted', 'openai'],
            organization: (string) $data['organization'],
            period: ['from' => (string) $data['period']['from'], 'to' => (string) $data['period']['to']],
            setup: $data['setup'] ?? [],
            scripted: $data['scripted'] ?? [],
            expect: $data['expect'],
            rubric: $data['rubric'] ?? null,
        );
    }

    public function runsWith(string $provider): bool
    {
        return in_array($provider, $this->providers, true);
    }

    public function expectsSuccess(): bool
    {
        return $this->expect['outcome'] === self::OUTCOME_SUCCEEDED;
    }

    /**
     * Whether the case measures the model's own answers (forced answers are not schema adherence).
     */
    public function measuresModel(): bool
    {
        return $this->category !== self::CATEGORY_ROBUSTNESS;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $allowed
     */
    private static function onlyKeys(array $data, array $allowed, string $where): void
    {
        $unknown = array_diff(array_keys($data), $allowed);

        if ($unknown !== []) {
            throw new InvalidArgumentException("{$where}: unknown keys ".implode(', ', $unknown).'.');
        }
    }
}
