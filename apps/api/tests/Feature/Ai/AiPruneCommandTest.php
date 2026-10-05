<?php

namespace Tests\Feature\Ai;

use App\Models\AiInsight;
use App\Models\AiRun;
use App\Models\Customer;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AiPruneCommandTest extends TestCase
{
    use RefreshDatabase;

    private Organization $first;

    private Organization $second;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-05 12:00:00');
        config(['ai.retention.insights_days' => 30, 'ai.retention.runs_days' => 90]);

        $this->first = Organization::factory()->create();
        $this->second = Organization::factory()->create();
    }

    public function test_deletes_only_what_is_older_than_the_retention_in_every_organization(): void
    {
        foreach ([$this->first, $this->second] as $organization) {
            $this->insight($organization, '2026-09-04 11:59:59', 'expired');
            $this->insight($organization, '2026-09-05 12:00:00', 'kept');
            $this->recordRun($organization, '2026-07-07 11:59:59', AiRun::STATUS_SUCCEEDED);
            $this->recordRun($organization, '2026-07-07 12:00:00', AiRun::STATUS_SUCCEEDED);
            $this->recordRun($organization, '2026-10-05 11:00:00', AiRun::STATUS_CACHE_HIT);
        }

        $this->artisan('pulseboard:ai-prune')
            ->expectsOutputToContain('Pruned 2 AI insights older than 30 days and 2 AI runs older than 90 days.')
            ->assertSuccessful();

        foreach ([$this->first, $this->second] as $organization) {
            $this->assertSame(['kept'], AiInsight::query()->forOrganization($organization)->pluck('model')->all());
            $this->assertSame(2, AiRun::query()->forOrganization($organization)->count());
        }

        $this->artisan('pulseboard:ai-prune')
            ->expectsOutputToContain('Pruned 0 AI insights older than 30 days and 0 AI runs older than 90 days.')
            ->assertSuccessful();
    }

    public function test_never_touches_tables_outside_ai(): void
    {
        $customer = Customer::factory()->for($this->first)->create();
        $customer->forceFill(['created_at' => '2020-01-01 00:00:00'])->save();
        $this->insight($this->first, '2020-01-01 00:00:00', 'expired');

        $this->artisan('pulseboard:ai-prune')->assertSuccessful();

        $this->assertSame(0, AiInsight::query()->count());
        $this->assertModelExists($customer);
        $this->assertSame(2, Organization::query()->count());
    }

    public function test_the_retention_follows_the_configuration(): void
    {
        config(['ai.retention.insights_days' => 1, 'ai.retention.runs_days' => 32]);
        $this->insight($this->first, '2026-10-03 12:00:00', 'expired');
        $this->recordRun($this->first, '2026-09-02 12:00:00', AiRun::STATUS_SUCCEEDED);
        $this->recordRun($this->first, '2026-09-04 12:00:00', AiRun::STATUS_SUCCEEDED);

        $this->artisan('pulseboard:ai-prune')->assertSuccessful();

        $this->assertSame(0, AiInsight::query()->count());
        $this->assertSame(1, AiRun::query()->count());
    }

    public function test_dry_run_counts_without_deleting(): void
    {
        $this->insight($this->first, '2026-01-01 00:00:00', 'expired');
        $this->recordRun($this->second, '2026-01-01 00:00:00', AiRun::STATUS_SUCCEEDED);

        $this->artisan('pulseboard:ai-prune', ['--dry-run' => true])
            ->expectsOutputToContain('Would prune 1 AI insights older than 30 days and 1 AI runs older than 90 days.')
            ->assertSuccessful();

        $this->assertSame(1, AiInsight::query()->count());
        $this->assertSame(1, AiRun::query()->count());
    }

    public function test_a_run_retention_shorter_than_the_budget_window_is_refused(): void
    {
        config(['ai.retention.runs_days' => 31]);
        $this->recordRun($this->first, '2026-01-01 00:00:00', AiRun::STATUS_SUCCEEDED);

        $this->artisan('pulseboard:ai-prune')
            ->expectsOutputToContain('AI_RUNS_RETENTION_DAYS must be at least 32')
            ->assertFailed();

        $this->assertSame(1, AiRun::query()->count());
    }

    public function test_an_insight_retention_below_one_day_is_refused(): void
    {
        config(['ai.retention.insights_days' => 0]);
        $this->insight($this->first, '2026-10-05 11:00:00', 'recent');

        $this->artisan('pulseboard:ai-prune')->assertFailed();

        $this->assertSame(1, AiInsight::query()->count());
    }

    private function insight(Organization $organization, string $createdAt, string $model): void
    {
        AiInsight::query()->create([
            'organization_id' => $organization->id,
            'kind' => AiInsight::KIND_PERIOD_SUMMARY,
            'fingerprint' => hash('sha256', Str::uuid()->toString()),
            'model' => $model,
            'prompt_version' => 'period_summary.v1',
            'content' => ['headline' => 'Resumo'],
            'created_at' => $createdAt,
        ]);
    }

    private function recordRun(Organization $organization, string $createdAt, string $status): void
    {
        AiRun::query()->create([
            'organization_id' => $organization->id,
            'feature' => AiRun::FEATURE_PERIOD_SUMMARY,
            'provider' => 'scripted',
            'model' => 'scripted',
            'status' => $status,
            'created_at' => $createdAt,
        ]);
    }
}
