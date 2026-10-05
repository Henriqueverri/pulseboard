<?php

namespace App\Console\Commands;

use App\Models\AiInsight;
use App\Models\AiRun;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Applies the AI retention policy (config/ai.php) to every organization: cached
 * insights older than AI_INSIGHTS_RETENTION_DAYS and run telemetry older than
 * AI_RUNS_RETENTION_DAYS. Runs on every container start (pulseboard:release),
 * since Render Free has no cron. Idempotent; touches no other table.
 */
class AiPruneCommand extends Command
{
    // Runs feed the daily quotas and the monthly budget, which look back up to a calendar month.
    public const MIN_RUNS_RETENTION_DAYS = 32;

    protected $signature = 'pulseboard:ai-prune
        {--dry-run : Count what would be deleted without deleting anything}';

    protected $description = 'Delete cached AI insights and AI run telemetry older than their retention';

    public function handle(): int
    {
        $insightsDays = (int) config('ai.retention.insights_days');
        $runsDays = (int) config('ai.retention.runs_days');

        if ($insightsDays < 1) {
            $this->components->error('AI_INSIGHTS_RETENTION_DAYS must be at least 1.');

            return self::FAILURE;
        }

        if ($runsDays < self::MIN_RUNS_RETENTION_DAYS) {
            $this->components->error('AI_RUNS_RETENTION_DAYS must be at least '.self::MIN_RUNS_RETENTION_DAYS.' (runs feed the quotas and the monthly budget).');

            return self::FAILURE;
        }

        $now = CarbonImmutable::now();
        $insights = AiInsight::query()->where('created_at', '<', $now->subDays($insightsDays));
        $runs = AiRun::query()->where('created_at', '<', $now->subDays($runsDays));

        if ($this->option('dry-run')) {
            $this->components->info(sprintf(
                'Would prune %d AI insights older than %d days and %d AI runs older than %d days.',
                $insights->count(), $insightsDays, $runs->count(), $runsDays,
            ));

            return self::SUCCESS;
        }

        $this->components->info(sprintf(
            'Pruned %d AI insights older than %d days and %d AI runs older than %d days.',
            $insights->delete(), $insightsDays, $runs->delete(), $runsDays,
        ));

        return self::SUCCESS;
    }
}
