<?php

namespace Tests\Feature;

use App\Console\Commands\DemoCommand;
use App\Models\AiRun;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReleaseCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_runs_the_migrations_and_seeds_the_demo_organization(): void
    {
        config(['demo.password' => 'demo-password-123']);

        $this->artisan('pulseboard:release')->assertSuccessful();
        $this->artisan('pulseboard:release')->assertSuccessful();

        $organization = Organization::query()->where('slug', DemoCommand::ORGANIZATION_SLUG)->sole();

        $this->assertSame(2, $organization->users()->count());
        $this->assertSame(40, $organization->products()->count());
    }

    public function test_applies_the_ai_retention_on_every_start(): void
    {
        config(['demo.password' => 'demo-password-123']);
        $this->travelTo('2026-10-05 12:00:00');
        $organization = Organization::factory()->create();

        foreach (['2026-01-01 00:00:00', '2026-10-01 00:00:00'] as $createdAt) {
            AiRun::query()->create([
                'organization_id' => $organization->id,
                'feature' => AiRun::FEATURE_PERIOD_SUMMARY,
                'provider' => 'scripted',
                'model' => 'scripted',
                'status' => AiRun::STATUS_SUCCEEDED,
                'created_at' => $createdAt,
            ]);
        }

        $this->artisan('pulseboard:release')->assertSuccessful();

        $this->assertSame(1, AiRun::query()->count());
    }

    public function test_fails_when_the_ai_retention_is_misconfigured(): void
    {
        config(['demo.password' => 'demo-password-123', 'ai.retention.runs_days' => 7]);

        $this->artisan('pulseboard:release')->assertFailed();
    }

    public function test_fails_when_the_demo_seed_fails(): void
    {
        config(['demo.password' => 'short']);

        $this->artisan('pulseboard:release')->assertFailed();

        $this->assertSame(0, Organization::query()->count());
        $this->assertSame(0, User::query()->count());
    }
}
