<?php

namespace Tests\Feature\Ai;

use App\Data\Ai\AiContext;
use App\Data\Ai\LlmUsage;
use App\Models\AiRun;
use App\Models\Organization;
use App\Services\Ai\AiRunRecorder;
use App\Support\Analytics\ReportingPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\Feature\Api\Concerns\InteractsWithOrganizationApi;
use Tests\TestCase;

class AiRunRecorderTest extends TestCase
{
    use InteractsWithOrganizationApi, RefreshDatabase;

    public function test_a_run_is_stored_with_tokens_estimated_cost_and_request_id(): void
    {
        config(['ai.pricing' => ['gpt-6-luna' => ['input' => 0.10, 'cached_input' => 0.01, 'output' => 0.50]]]);
        $organization = Organization::factory()->create();
        $user = $this->memberOf($organization);
        $context = AiContext::for($organization, $user, ReportingPeriod::lastDays(30, $organization->timezone), 'req-12345678');

        $run = app(AiRunRecorder::class)->record(
            $context,
            AiRun::FEATURE_PERIOD_SUMMARY,
            AiRun::STATUS_SUCCEEDED,
            'openai',
            'gpt-6-luna-2026-07-01',
            'period_summary.v1',
            new LlmUsage(3000, 400),
            latencyMs: 1830,
            attempts: 1,
        );

        $this->assertDatabaseHas('ai_runs', [
            'id' => $run->id,
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'feature' => 'period_summary',
            'status' => 'succeeded',
            'provider' => 'openai',
            'model' => 'gpt-6-luna-2026-07-01',
            'prompt_version' => 'period_summary.v1',
            'input_tokens' => 3000,
            'output_tokens' => 400,
            'cost_micros' => 500,
            'latency_ms' => 1830,
            'attempts' => 1,
            'tool_calls' => 0,
            'request_id' => 'req-12345678',
        ]);
        $this->assertNotNull($run->created_at);
    }

    public function test_the_log_line_has_telemetry_but_no_content(): void
    {
        $organization = Organization::factory()->create(['name' => 'Secret Org Name']);
        $user = $this->memberOf($organization);
        $context = AiContext::for($organization, $user, ReportingPeriod::lastDays(30, $organization->timezone));
        Log::spy();

        app(AiRunRecorder::class)->record(
            $context,
            AiRun::FEATURE_QUESTION,
            AiRun::STATUS_INVALID_OUTPUT,
            'scripted',
            'scripted',
            'question_answer.v1',
            new LlmUsage(100, 10),
            latencyMs: 20,
            toolCalls: 2,
            attempts: 2,
            errorCode: 'ai_invalid_output',
        );

        Log::shouldHaveReceived('info')->once()->withArgs(
            function (string $message, array $context) use ($organization, $user): bool {
                $logged = $message.json_encode($context);

                return $context['event'] === 'ai.run'
                    && $context['organization_id'] === $organization->id
                    && $context['user_id'] === $user->id
                    && $context['feature'] === 'question'
                    && $context['status'] === 'invalid_output'
                    && $context['tool_calls'] === 2
                    && $context['attempts'] === 2
                    && $context['error_code'] === 'ai_invalid_output'
                    && array_keys($context) === [
                        'event', 'ai_run_id', 'organization_id', 'user_id', 'feature', 'status', 'provider', 'model',
                        'prompt_version', 'input_tokens', 'cached_input_tokens', 'output_tokens', 'cost_micros',
                        'latency_ms', 'tool_calls', 'attempts', 'error_code',
                    ]
                    && ! str_contains($logged, 'Secret Org Name')
                    && ! str_contains($logged, $user->email);
            },
        );
    }

    public function test_runs_are_scoped_to_their_organization(): void
    {
        [$first, $second] = Organization::factory()->count(2)->create();
        $recorder = app(AiRunRecorder::class);

        foreach ([$first, $first, $second] as $organization) {
            $recorder->record(
                AiContext::for($organization, null, ReportingPeriod::lastDays(30, $organization->timezone)),
                AiRun::FEATURE_PERIOD_SUMMARY,
                AiRun::STATUS_CACHE_HIT,
                'scripted',
                'scripted',
            );
        }

        $this->assertSame(2, AiRun::query()->forOrganization($first)->count());
        $this->assertSame(1, AiRun::query()->forOrganization($second)->count());
        $this->assertNull(AiRun::query()->forOrganization($second)->sole()->user_id);
    }
}
