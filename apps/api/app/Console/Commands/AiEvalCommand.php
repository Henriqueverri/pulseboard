<?php

namespace App\Console\Commands;

use App\Services\Ai\Evaluation\EvalCase;
use App\Services\Ai\Evaluation\EvalEnvironment;
use App\Services\Ai\Evaluation\EvalReport;
use App\Services\Ai\Evaluation\EvalRunner;
use App\Services\Ai\Fake\ScriptedLlmClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;

/**
 * AI evaluation suite (outside PHPUnit): runs the versioned cases of
 * tests/AiEval/cases against the real PeriodSummaryService in a throwaway
 * in-memory database, scores them with deterministic evaluators and writes a
 * markdown report. Exits non-zero when a case fails or a metric is below its
 * threshold, so a prompt or model change can be gated on it.
 *
 * The scripted provider is the default: deterministic, offline and free.
 * --provider=openai measures the real model and costs money.
 */
class AiEvalCommand extends Command
{
    public const CASES_PATH = 'tests/AiEval/cases/period_summary';

    public const FIXTURES_PATH = 'tests/Fixtures/Ai/period_summary';

    protected $signature = 'pulseboard:ai-eval
        {--provider=scripted : scripted (deterministic, default) or openai (real model, costs money)}
        {--model= : Model for --provider=openai (defaults to AI_MODEL)}
        {--repeat=1 : Runs per case, to measure variance}
        {--case=* : Only these case ids}
        {--output= : Report path (default storage/ai-eval/reports/<date>-<prompt>-<model>.md)}
        {--record : Save each case\'s provider answers as parsing fixtures in tests/Fixtures/Ai/period_summary}';

    protected $description = 'Evaluate PulseBoard Insights against the versioned AI cases and write a markdown report';

    public function handle(): int
    {
        if ($this->laravel->isProduction()) {
            $this->components->error('The AI evaluation never runs in production.');

            return self::FAILURE;
        }

        $provider = (string) $this->option('provider');
        $repeat = (int) $this->option('repeat');

        if (! in_array($provider, ['scripted', 'openai'], true) || $repeat < 1) {
            $this->components->error('Use --provider=scripted|openai and --repeat of at least 1.');

            return self::FAILURE;
        }

        if ($provider === 'openai') {
            if (blank(config('services.openai.key'))) {
                $this->components->error('Set OPENAI_API_KEY to evaluate the real model.');

                return self::FAILURE;
            }

            if (filled($this->option('model'))) {
                config(['ai.model' => (string) $this->option('model')]);
            }
        }

        try {
            $cases = $this->cases();
        } catch (InvalidArgumentException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($cases === []) {
            $this->components->error('No case matches.');

            return self::FAILURE;
        }

        $model = $provider === 'openai' ? (string) config('ai.model') : ScriptedLlmClient::MODEL;
        $this->components->info("Evaluating {$model} on ".count($cases).' cases × '.$repeat.' run(s).');

        $runner = new EvalRunner(EvalEnvironment::boot(), $provider, (bool) $this->option('record'));
        $runs = [];
        $skipped = [];

        foreach ($cases as $case) {
            if (! $case->runsWith($provider)) {
                $skipped[$case->id] = 'só roda com os provedores '.implode(', ', $case->providers).' (respostas forçadas).';

                continue;
            }

            for ($repetition = 1; $repetition <= $repeat; $repetition++) {
                $run = $runner->run($case, $repetition);
                $runs[] = $run;

                if ($run->fixture !== null) {
                    $this->writeFixture($run->fixture);
                }
            }
        }

        $report = new EvalReport($runs, $skipped, $provider, $model, $repeat);
        $path = (string) ($this->option('output') ?: $report->defaultPath());
        File::ensureDirectoryExists(dirname($path));
        File::put($path, $report->markdown());

        $this->table(
            ['Case', 'Category', 'Runs', 'Result', 'Schema 1st try', 'Attempts'],
            array_map(fn (array $row) => [$row['case'], $row['category'], $row['runs'], $row['passed'] ? 'ok' : 'FAILED', $row['schema'], $row['attempts']], $report->caseRows()),
        );

        foreach ($report->metrics() as $name => $metric) {
            $this->components->twoColumnDetail($name, EvalReport::percent($metric).' (min '.EvalReport::THRESHOLDS[$name] * 100 .'%)');
        }

        foreach ($runs as $run) {
            foreach ($run->failures() as $failure) {
                $this->components->warn("{$run->case->id} #{$run->repetition} [{$failure['group']}] {$failure['name']}: {$failure['detail']}");
            }
        }

        $this->components->info("Report: {$path}");

        if (! $report->passed()) {
            $this->components->error('The AI evaluation failed.');

            return self::FAILURE;
        }

        $this->components->info('The AI evaluation passed.');

        return self::SUCCESS;
    }

    /**
     * @return list<EvalCase>
     */
    private function cases(): array
    {
        $only = (array) $this->option('case');
        $cases = [];

        foreach (File::glob(base_path(self::CASES_PATH).'/*.json') as $path) {
            $case = EvalCase::fromFile($path);

            if (isset($cases[$case->id])) {
                throw new InvalidArgumentException("Duplicate case id: {$case->id}.");
            }

            $cases[$case->id] = $case;
        }

        $unknown = array_diff($only, array_keys($cases));

        if ($unknown !== []) {
            throw new InvalidArgumentException('Unknown case: '.implode(', ', $unknown).'.');
        }

        return array_values($only === [] ? $cases : array_intersect_key($cases, array_flip($only)));
    }

    /**
     * @param  array<string, mixed>  $fixture
     */
    private function writeFixture(array $fixture): void
    {
        $path = base_path(self::FIXTURES_PATH)."/{$fixture['source']}-{$fixture['case']}.json";
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($fixture, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)."\n");
    }
}
