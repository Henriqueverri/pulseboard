<?php

namespace Tests\Unit\Ai;

use App\Data\Ai\Evidence;
use App\Data\Ai\LlmMessage;
use App\Data\Ai\LlmRequest;
use App\Data\Ai\LlmResponse;
use App\Data\Ai\LlmUsage;
use App\Services\Ai\Evaluation\EvalRunner;
use App\Services\Ai\Insights\EvidenceCatalog;
use App\Services\Ai\Insights\PeriodSummaryValidator;
use App\Services\Ai\OpenAi\OpenAiResponsesClient;
use App\Support\Analytics\Comparison;
use App\Support\Money;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Replays the answers recorded by `pulseboard:ai-eval --record`
 * (tests/Fixtures/Ai/period_summary) through the parsing and validation the
 * summary relies on: the raw Responses API body (when recorded from OpenAI)
 * must parse to the same answer, and the validator must still reach the
 * recorded verdict for every attempt, valid or not.
 */
class RecordedPeriodSummaryAnswersTest extends TestCase
{
    private const FIXTURES = __DIR__.'/../../Fixtures/Ai/period_summary';

    private const URL = 'https://api.openai.test/v1/responses';

    /**
     * @return array<string, array{string}>
     */
    public static function fixtures(): array
    {
        $files = glob(self::FIXTURES.'/*.json') ?: [];

        return array_combine(array_map(fn (string $path) => basename($path, '.json'), $files), array_map(fn (string $path) => [$path], $files));
    }

    public function test_there_are_recorded_answers(): void
    {
        $this->assertNotEmpty(self::fixtures());
    }

    #[DataProvider('fixtures')]
    public function test_recorded_answers_reach_the_recorded_verdict(string $path): void
    {
        $fixture = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $catalog = $this->catalog($fixture['catalog']);

        $this->assertNotEmpty($fixture['attempts']);

        $bodies = Http::fakeSequence(self::URL);

        foreach ($fixture['attempts'] as $attempt) {
            if ($attempt['raw_response'] !== null) {
                $bodies->push($attempt['raw_response']);
            }
        }

        foreach ($fixture['attempts'] as $index => $attempt) {
            $response = $attempt['raw_response'] === null
                ? new LlmResponse((string) $fixture['model'], new LlmUsage, $attempt['output_text'], refusal: $attempt['refusal'], incompleteReason: $attempt['incomplete_reason'])
                : $this->parseNext();

            $this->assertSame($attempt['output_text'], $response->outputText, "attempt {$index} output");
            $this->assertSame($attempt['refusal'], $response->refusal, "attempt {$index} refusal");
            $this->assertSame($attempt['incomplete_reason'], $response->incompleteReason, "attempt {$index} incomplete reason");

            $output = $response->isIncomplete() ? null : $response->json();
            $errors = match (true) {
                $response->isRefusal() => [],
                $output === null => [EvalRunner::INVALID_JSON],
                default => (new PeriodSummaryValidator)->validate($output, $catalog),
            };

            $this->assertSame($attempt['errors'], $errors, "attempt {$index} verdict");
        }
    }

    /**
     * The catalog as recorded, rebuilt through Comparison so the values and
     * variations still mean what they meant when the answer was recorded.
     *
     * @param  list<array<string, mixed>>  $entries
     */
    private function catalog(array $entries): EvidenceCatalog
    {
        $catalog = new EvidenceCatalog;

        foreach ($entries as $entry) {
            $comparison = $entry['format'] === Evidence::FORMAT_MONEY
                ? Comparison::ofMoney(
                    $entry['value'] === null ? null : Money::toCents($entry['value']),
                    $entry['previous'] === null ? null : Money::toCents($entry['previous']),
                )
                : Comparison::ofCounts((int) $entry['value'], (int) $entry['previous']);

            $evidence = new Evidence($entry['ref'], $entry['label'], $entry['format'], $comparison, $entry['polarity'], $entry['destination']);
            $this->assertSame($entry, $evidence->toArray(), "{$entry['ref']} rebuilds as recorded");

            $catalog->add($evidence);
        }

        return $catalog;
    }

    /**
     * The next recorded Responses API body, through the real adapter.
     */
    private function parseNext(): LlmResponse
    {
        return (new OpenAiResponsesClient('sk-test-not-real', 'https://api.openai.test/v1', 'recorded', 20, 5, 0, 0))
            ->respond(new LlmRequest('Rules.', [LlmMessage::user('Context')]));
    }
}
