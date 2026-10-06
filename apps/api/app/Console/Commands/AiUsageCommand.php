<?php

namespace App\Console\Commands;

use App\Models\AiRun;
use App\Models\Organization;
use App\Services\Ai\AiUsageGuard;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * AI usage from ai_runs, per day (UTC), organization and model: runs, provider
 * calls, cache hits, failures, rejections, tokens, estimated cost and latency
 * percentiles of provider calls. Ends with the month-to-date spend against the
 * global monthly budget. Costs are estimates; the provider's billing is the
 * source of truth.
 */
class AiUsageCommand extends Command
{
    public const MAX_DAYS = 90;

    private const FAILED_STATUSES = [
        AiRun::STATUS_INVALID_OUTPUT,
        AiRun::STATUS_PROVIDER_ERROR,
        AiRun::STATUS_TIMEOUT,
        AiRun::STATUS_REFUSED,
    ];

    protected $signature = 'pulseboard:ai-usage
        {--days=7 : Days to show, today (UTC) included}
        {--organization= : Only the organization with this slug}';

    protected $description = 'Show AI usage, estimated cost and latency per day, organization and model';

    public function handle(AiUsageGuard $guard): int
    {
        $days = filter_var($this->option('days'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => self::MAX_DAYS]]);

        if ($days === false) {
            $this->components->error('--days must be an integer between 1 and '.self::MAX_DAYS.'.');

            return self::FAILURE;
        }

        $organization = null;

        if (($slug = $this->option('organization')) !== null) {
            $organization = Organization::query()->where('slug', $slug)->first();

            if ($organization === null) {
                $this->components->error("No organization with the slug \"{$slug}\".");

                return self::FAILURE;
            }
        }

        $since = CarbonImmutable::now('UTC')->startOfDay()->subDays($days - 1);
        $runs = AiRun::query()
            ->with('organization:id,slug')
            ->where('created_at', '>=', $since)
            ->when($organization, fn ($query) => $query->forOrganization($organization))
            ->orderBy('created_at')
            ->get(['organization_id', 'model', 'status', 'input_tokens', 'output_tokens', 'cost_micros', 'latency_ms', 'created_at']);

        if ($runs->isEmpty()) {
            $this->components->info("No AI runs since {$since->toDateString()} (UTC).");
        } else {
            $groups = $runs->groupBy(fn (AiRun $run): string => implode('|', [
                $run->created_at->copy()->utc()->toDateString(),
                $run->organization->slug,
                $run->model,
            ]));

            $rows = $groups->map(fn (Collection $group, string $key): array => [...explode('|', $key), ...$this->summarize($group)])->values();

            $this->table(
                ['Day (UTC)', 'Organization', 'Model', 'Runs', 'Provider calls', 'Cache hits', 'Failed', 'Rejected', 'Input tokens', 'Output tokens', 'Cost (USD)', 'p50 ms', 'p95 ms'],
                [...$rows->all(), ['Total', '', '', ...$this->summarize($runs)]],
            );
        }

        $spent = $guard->monthlySpendMicros();
        $budget = $guard->monthlyBudgetMicros();

        $this->line(sprintf(
            'Month to date (UTC, all organizations): US$ %s of the US$ %s budget (%s).',
            $this->usd($spent),
            $this->usd($budget),
            $budget > 0 ? sprintf('%.1f%%', $spent / $budget * 100) : 'new generations blocked',
        ));

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, AiRun>  $runs
     * @return list<int|string>
     */
    private function summarize(Collection $runs): array
    {
        $calls = $runs->filter(fn (AiRun $run): bool => in_array($run->status, AiRun::BILLABLE_STATUSES, true));
        $latencies = $calls->pluck('latency_ms')->sort()->values()->all();

        return [
            $runs->count(),
            $calls->count(),
            $runs->where('status', AiRun::STATUS_CACHE_HIT)->count(),
            $runs->whereIn('status', self::FAILED_STATUSES)->count(),
            $runs->where('status', AiRun::STATUS_QUOTA_EXCEEDED)->count(),
            $runs->sum('input_tokens'),
            $runs->sum('output_tokens'),
            $this->usd((int) $runs->sum('cost_micros')),
            $this->percentile($latencies, 0.50),
            $this->percentile($latencies, 0.95),
        ];
    }

    /**
     * Nearest-rank percentile; "-" when no run reached the provider.
     *
     * @param  list<int>  $sorted
     */
    private function percentile(array $sorted, float $percentile): int|string
    {
        if ($sorted === []) {
            return '-';
        }

        return $sorted[max(0, (int) ceil($percentile * count($sorted)) - 1)];
    }

    private function usd(int $micros): string
    {
        return number_format($micros / 1_000_000, 4, '.', '');
    }
}
