<?php

namespace Tests\Feature\Ai;

use App\Models\AiRun;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AiUsageCommandTest extends TestCase
{
    use RefreshDatabase;

    private const HEADERS = ['Day (UTC)', 'Organization', 'Model', 'Runs', 'Provider calls', 'Cache hits', 'Failed', 'Rejected', 'Input tokens', 'Output tokens', 'Cost (USD)', 'p50 ms', 'p95 ms'];

    private const MODEL = 'gpt-6-luna-2026-07-01';

    private Organization $alpha;

    private Organization $beta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-05 12:00:00');
        config(['ai.monthly_budget_usd' => 5]);

        $this->alpha = Organization::factory()->create(['slug' => 'alpha']);
        $this->beta = Organization::factory()->create(['slug' => 'beta']);
    }

    public function test_reports_usage_per_day_organization_and_model_with_the_monthly_budget(): void
    {
        $this->seedRuns();

        $this->artisan('pulseboard:ai-usage')
            ->expectsTable(self::HEADERS, [
                ['2026-10-04', 'alpha', self::MODEL, 5, 3, 1, 1, 1, 3000, 500, '0.0040', 3000, 20000],
                ['2026-10-05', 'alpha', self::MODEL, 1, 1, 0, 0, 0, 500, 100, '0.0010', 2000, 2000],
                ['2026-10-05', 'beta', 'scripted', 1, 0, 1, 0, 0, 0, 0, '0.0000', '-', '-'],
                ['Total', '', '', 7, 4, 2, 1, 1, 3500, 600, '0.0050', 2000, 20000],
            ])
            ->expectsOutput('Month to date (UTC, all organizations): US$ 0.0050 of the US$ 5.0000 budget (0.1%).')
            ->assertSuccessful();
    }

    public function test_filters_one_organization_but_keeps_the_global_budget(): void
    {
        $this->seedRuns();

        $this->artisan('pulseboard:ai-usage', ['--organization' => 'beta', '--days' => 1])
            ->expectsTable(self::HEADERS, [
                ['2026-10-05', 'beta', 'scripted', 1, 0, 1, 0, 0, 0, 0, '0.0000', '-', '-'],
                ['Total', '', '', 1, 0, 1, 0, 0, 0, 0, '0.0000', '-', '-'],
            ])
            ->expectsOutput('Month to date (UTC, all organizations): US$ 0.0050 of the US$ 5.0000 budget (0.1%).')
            ->assertSuccessful();
    }

    public function test_an_empty_window_still_shows_the_budget(): void
    {
        config(['ai.monthly_budget_usd' => 0]);

        $this->artisan('pulseboard:ai-usage')
            ->expectsOutputToContain('No AI runs since 2026-09-29 (UTC).')
            ->expectsOutput('Month to date (UTC, all organizations): US$ 0.0000 of the US$ 0.0000 budget (new generations blocked).')
            ->assertSuccessful();
    }

    /**
     * @return array<string, array{array<string, string>, string}>
     */
    public static function invalidOptions(): array
    {
        return [
            'zero days' => [['--days' => '0'], '--days must be an integer between 1 and 90.'],
            'too many days' => [['--days' => '91'], '--days must be an integer between 1 and 90.'],
            'not a number' => [['--days' => 'week'], '--days must be an integer between 1 and 90.'],
            'unknown organization' => [['--organization' => 'nope'], 'No organization with the slug "nope".'],
        ];
    }

    /**
     * @param  array<string, string>  $options
     */
    #[DataProvider('invalidOptions')]
    public function test_invalid_options_fail(array $options, string $message): void
    {
        $this->artisan('pulseboard:ai-usage', $options)
            ->expectsOutputToContain($message)
            ->assertFailed();
    }

    private function seedRuns(): void
    {
        $this->recordRun($this->alpha, '2026-10-04 10:00:00', AiRun::STATUS_SUCCEEDED, 1000, 1000, 200, 1500);
        $this->recordRun($this->alpha, '2026-10-04 10:01:00', AiRun::STATUS_SUCCEEDED, 3000, 2000, 300, 2500);
        $this->recordRun($this->alpha, '2026-10-04 10:02:00', AiRun::STATUS_TIMEOUT, 20000);
        $this->recordRun($this->alpha, '2026-10-04 10:03:00', AiRun::STATUS_CACHE_HIT, 5);
        $this->recordRun($this->alpha, '2026-10-04 10:04:00', AiRun::STATUS_QUOTA_EXCEEDED);
        $this->recordRun($this->alpha, '2026-10-05 09:00:00', AiRun::STATUS_SUCCEEDED, 2000, 500, 100, 1000);
        $this->recordRun($this->beta, '2026-10-05 10:00:00', AiRun::STATUS_CACHE_HIT, 4, model: 'scripted');

        // Outside the window and the month: neither in the table nor in the month-to-date spend.
        $this->recordRun($this->alpha, '2026-09-20 10:00:00', AiRun::STATUS_SUCCEEDED, 1000, 1000, 200, 7000);
    }

    private function recordRun(
        Organization $organization,
        string $createdAt,
        string $status,
        int $latencyMs = 0,
        int $inputTokens = 0,
        int $outputTokens = 0,
        int $costMicros = 0,
        string $model = self::MODEL,
    ): void {
        AiRun::query()->create([
            'organization_id' => $organization->id,
            'feature' => AiRun::FEATURE_PERIOD_SUMMARY,
            'provider' => $model === 'scripted' ? 'scripted' : 'openai',
            'model' => $model,
            'status' => $status,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'cost_micros' => $costMicros,
            'latency_ms' => $latencyMs,
            'created_at' => $createdAt,
        ]);
    }
}
