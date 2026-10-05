<?php

namespace Tests\Feature\Ai;

use App\Console\Commands\DemoCommand;
use App\Enums\TransactionStatus;
use App\Exceptions\AiException;
use App\Models\AiInsight;
use App\Models\AiRun;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use App\Services\Ai\Fake\ScriptedLlmClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Sleep;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Ai\Concerns\InteractsWithInsights;
use Tests\Feature\Api\Concerns\InteractsWithOrganizationApi;
use Tests\TestCase;

class PeriodSummaryApiTest extends TestCase
{
    use InteractsWithInsights, InteractsWithOrganizationApi, RefreshDatabase;

    private const URI = '/api/v1/insights/period-summary';

    private const PERIOD = ['from' => '2026-09-01', 'to' => '2026-09-30'];

    private Organization $organization;

    private User $owner;

    private Customer $customer;

    private Product $product;

    private ScriptedLlmClient $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-05 15:00:00');
        config(['ai.enabled' => true]);

        $this->organization = Organization::factory()->create();
        $this->owner = $this->memberOf($this->organization);
        $this->organization->enableInsights($this->owner);
        $this->provider = $this->scriptedProvider();

        $this->customer = Customer::factory()->for($this->organization)->create();
        $this->product = Product::factory()->for($this->organization)->create(['name' => 'Camiseta Básica']);
        $other = Product::factory()->for($this->organization)->create(['name' => 'Boné']);

        $this->createTransaction($this->customer, [[$this->product, 2, '100.00']], TransactionStatus::Paid, '2026-09-10 15:00:00');
        $this->createTransaction($this->customer, [[$other, 1, '50.00']], TransactionStatus::Paid, '2026-09-20 15:00:00');
        $this->createTransaction($this->customer, [[$other, 1, '50.00']], TransactionStatus::Refunded, '2026-09-15 15:00:00');
        $this->createTransaction($this->customer, [[$other, 1, '50.00']], TransactionStatus::Pending, '2026-09-25 15:00:00');
        $this->createTransaction($this->customer, [[$this->product, 1, '100.00']], TransactionStatus::Paid, '2026-08-15 15:00:00');
    }

    public function test_get_without_a_summary_returns_null_data_and_never_calls_the_provider(): void
    {
        $this->summary()
            ->assertOk()
            ->assertExactJson([
                'data' => null,
                'meta' => [
                    'period' => ['from' => '2026-09-01', 'to' => '2026-09-30', 'days' => 30],
                    'previous_period' => ['from' => '2026-08-02', 'to' => '2026-08-31', 'days' => 30],
                    'timezone' => 'America/Sao_Paulo',
                    'currency' => 'BRL',
                    'insight' => null,
                ],
            ]);

        $this->assertSame([], $this->provider->requests());
        $this->assertSame(0, AiRun::query()->count());
    }

    public function test_post_generates_a_summary_whose_numbers_come_from_the_dashboard(): void
    {
        $this->provider->pushOutput($this->summaryOutput());

        $response = $this->generate()->assertOk();
        $dashboard = $this->actingInOrganization($this->owner, $this->organization)
            ->getJson('/api/v1/dashboard?'.http_build_query(self::PERIOD))
            ->json('data');

        $response
            ->assertJsonPath('data.headline', 'O período manteve as vendas em ritmo estável')
            ->assertJsonPath('data.findings.0.kind', 'neutral')
            ->assertJsonPath('data.findings.0.destination', 'analytics.revenue')
            ->assertJsonPath('data.findings.0.evidence.0', [
                'ref' => 'kpi.revenue',
                'label' => 'Receita',
                'format' => 'money',
                'polarity' => 'positive',
                'destination' => 'analytics.revenue',
                ...$dashboard['revenue'],
            ])
            ->assertJsonPath('data.findings.0.evidence.1.value', $dashboard['orders']['value'])
            ->assertJsonPath('data.attention_points.0.evidence.0.ref', 'status.pending')
            ->assertJsonPath('data.attention_points.0.evidence.0.value', 1)
            ->assertJsonPath('data.caveats', ['low_volume', 'status_is_current'])
            ->assertJsonPath('meta.period.from', '2026-09-01')
            ->assertJsonPath('meta.insight.model', 'scripted')
            ->assertJsonPath('meta.insight.prompt_version', 'period_summary.v1')
            ->assertJsonPath('meta.insight.cached', false);
        $this->assertSame('250.00', $dashboard['revenue']['value']);
        $this->assertNotNull($response->json('meta.insight.generated_at'));

        [$request] = $this->provider->requests();
        $this->assertSame('period_summary_v1', $request->outputSchema?->name);
        $this->assertStringContainsString('PulseBoard Insights', $request->instructions);
        $this->assertSame(800, $request->maxOutputTokens);
        $this->assertNotNull($request->deadlineAt);
        $this->assertCount(1, $request->input);
        $this->assertStringContainsString('<pulseboard_data>', $request->input[0]->content);

        $run = AiRun::query()->sole();
        $this->assertSame(
            ['feature' => 'period_summary', 'status' => 'succeeded', 'provider' => 'scripted', 'model' => 'scripted', 'prompt_version' => 'period_summary.v1', 'attempts' => 1, 'input_tokens' => 1000, 'output_tokens' => 200, 'user_id' => $this->owner->id],
            $run->only(['feature', 'status', 'provider', 'model', 'prompt_version', 'attempts', 'input_tokens', 'output_tokens', 'user_id']),
        );
        $this->assertNotNull($run->request_id);
        $this->assertSame(1, AiInsight::query()->forOrganization($this->organization)->count());
    }

    public function test_product_evidence_is_labelled_with_the_product_name(): void
    {
        $output = $this->summaryOutput();
        $output['findings'][0]['evidence'] = ['product.1'];
        $output['findings'][0]['destination'] = 'analytics.products';
        $this->provider->pushOutput($output);

        $this->generate()
            ->assertOk()
            ->assertJsonPath('data.findings.0.evidence.0.label', 'Camiseta Básica')
            ->assertJsonPath('data.findings.0.evidence.0.value', '200.00')
            ->assertJsonPath('data.findings.0.evidence.0.previous', '100.00')
            ->assertJsonPath('data.findings.0.evidence.0.change', 100.0);
    }

    public function test_the_summary_is_served_from_cache_until_the_data_changes(): void
    {
        $this->provider->pushOutput($this->summaryOutput());
        $this->generate()->assertOk();

        $this->summary()
            ->assertOk()
            ->assertJsonPath('data.headline', 'O período manteve as vendas em ritmo estável')
            ->assertJsonPath('meta.insight.cached', true);

        $this->generate()->assertOk()->assertJsonPath('meta.insight.cached', true);

        $this->assertCount(1, $this->provider->requests(), 'unchanged data never reaches the provider twice');
        $this->assertEqualsCanonicalizing(['succeeded', 'cache_hit'], AiRun::query()->pluck('status')->all(), 'reading the cache is not a run');

        $this->createTransaction($this->customer, [[$this->product, 1, '100.00']], TransactionStatus::Paid, '2026-09-28 15:00:00');

        $this->summary()->assertOk()->assertJsonPath('data', null);

        $this->provider->pushOutput($this->summaryOutput('Uma nova venda mudou o resumo do período'));
        $this->generate()
            ->assertOk()
            ->assertJsonPath('data.headline', 'Uma nova venda mudou o resumo do período')
            ->assertJsonPath('meta.insight.cached', false);
        $this->assertCount(2, $this->provider->requests());
    }

    public function test_a_member_can_read_and_generate(): void
    {
        $member = $this->memberOf($this->organization, Organization::ROLE_MEMBER);

        $this->actingInOrganization($member, $this->organization)->getJson(self::URI)->assertOk();
        $this->actingInOrganization($member, $this->organization)->postJson(self::URI, self::PERIOD)->assertOk();
    }

    public function test_the_default_period_is_the_last_thirty_days(): void
    {
        $this->actingInOrganization($this->owner, $this->organization)
            ->postJson(self::URI)
            ->assertOk()
            ->assertJsonPath('meta.period', ['from' => '2026-09-06', 'to' => '2026-10-05', 'days' => 30])
            ->assertJsonPath('data.caveats.0', 'partial_period');
    }

    public function test_an_invalid_answer_is_repaired_once(): void
    {
        $invalid = $this->summaryOutput('A receita chegou a duzentos e cinquenta reais, ou 250');
        $this->provider->pushOutput($invalid)->pushOutput($this->summaryOutput());

        $this->generate()->assertOk()->assertJsonPath('data.headline', 'O período manteve as vendas em ritmo estável');

        [, $repair] = $this->provider->requests();
        $this->assertCount(3, $repair->input);
        $this->assertSame('assistant', $repair->input[1]->role);
        $this->assertStringContainsString('headline must not contain digits', $repair->input[2]->content);

        $run = AiRun::query()->sole();
        $this->assertSame(['succeeded', 2, 2000, 400], [$run->status, $run->attempts, $run->input_tokens, $run->output_tokens]);
    }

    public function test_malformed_json_is_repaired_once(): void
    {
        $this->provider->pushOutput('Claro! Aqui está o resumo: {headline')->pushOutput($this->summaryOutput());

        $this->generate()->assertOk();

        $this->assertStringContainsString('one complete JSON object', $this->provider->requests()[1]->input[2]->content);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidTwice(): array
    {
        return ['semantic' => ['semantic'], 'malformed' => ['malformed'], 'incomplete' => ['incomplete']];
    }

    #[DataProvider('invalidTwice')]
    public function test_two_invalid_answers_are_a_502_and_nothing_is_cached(string $case): void
    {
        foreach ([1, 2] as $attempt) {
            match ($case) {
                'semantic' => $this->provider->pushOutput([...$this->summaryOutput(), 'findings' => []]),
                'malformed' => $this->provider->pushOutput('not json'),
                'incomplete' => $this->provider->pushIncomplete(),
            };
        }

        $this->generate()
            ->assertStatus(502)
            ->assertExactJson(['message' => 'The AI answer could not be validated.', 'code' => 'ai_invalid_output']);

        $this->assertCount(2, $this->provider->requests());
        $this->assertSame(0, AiInsight::query()->count());
        $run = AiRun::query()->sole();
        $this->assertSame(['invalid_output', 2, 'ai_invalid_output'], [$run->status, $run->attempts, $run->error_code]);

        $this->summary()->assertOk()->assertJsonPath('data', null);
    }

    public function test_a_refusal_is_a_502_without_repair(): void
    {
        $this->provider->pushRefusal();

        $this->generate()->assertStatus(502)->assertJsonPath('code', 'ai_invalid_output');

        $this->assertCount(1, $this->provider->requests());
        $this->assertSame(['refused', 1], [AiRun::query()->sole()->status, AiRun::query()->sole()->attempts]);
        $this->assertSame(0, AiInsight::query()->count());
    }

    /**
     * @return array<string, array{0: AiException, 1: int, 2: string}>
     */
    public static function providerFailures(): array
    {
        return [
            'provider unavailable' => [AiException::providerUnavailable(), 503, 'provider_error'],
            'timeout' => [AiException::timeout(), 504, 'timeout'],
        ];
    }

    #[DataProvider('providerFailures')]
    public function test_provider_failures_keep_their_code_and_are_recorded(AiException $failure, int $status, string $runStatus): void
    {
        $this->provider->pushFailure($failure);

        $this->generate()->assertStatus($status)->assertJsonPath('code', $failure->errorCode);

        $run = AiRun::query()->sole();
        $this->assertSame([$runStatus, $failure->errorCode, 1], [$run->status, $run->error_code, $run->attempts]);
        $this->assertSame(0, AiInsight::query()->count());
    }

    public function test_a_failure_during_the_repair_keeps_the_usage_of_the_first_attempt(): void
    {
        $this->provider->pushOutput('not json')->pushFailure(AiException::timeout());

        $this->generate()->assertStatus(504);

        $run = AiRun::query()->sole();
        $this->assertSame(['timeout', 2, 1000, 200], [$run->status, $run->attempts, $run->input_tokens, $run->output_tokens]);
    }

    public function test_the_kill_switch_disables_reading_and_generating(): void
    {
        config(['ai.enabled' => false]);

        $this->summary()->assertStatus(503)->assertExactJson(['message' => 'Insights are currently unavailable.', 'code' => 'ai_disabled']);
        $this->generate()->assertStatus(503)->assertJsonPath('code', 'ai_disabled');

        $this->assertSame([], $this->provider->requests());
        $this->assertSame(0, AiRun::query()->count());
    }

    public function test_an_organization_that_did_not_opt_in_gets_ai_not_enabled(): void
    {
        $this->organization->disableInsights();

        $this->summary()->assertForbidden()->assertJsonPath('code', 'ai_not_enabled');
        $this->generate()->assertForbidden()->assertJsonPath('code', 'ai_not_enabled');

        $this->assertSame([], $this->provider->requests());
    }

    public function test_the_quota_blocks_new_generations_but_not_cached_summaries(): void
    {
        config(['ai.limits.daily_per_organization' => 1]);
        $this->generate()->assertOk();

        $this->generate()->assertOk()->assertJsonPath('meta.insight.cached', true);

        $this->createTransaction($this->customer, [[$this->product, 1, '100.00']], TransactionStatus::Paid, '2026-09-28 15:00:00');

        $this->generate()
            ->assertStatus(429)
            ->assertJsonPath('code', 'ai_quota_exceeded')
            ->assertHeader('Retry-After');

        $this->assertCount(1, $this->provider->requests());
        $this->assertSame(1, AiRun::query()->where('status', AiRun::STATUS_QUOTA_EXCEEDED)->count());
    }

    public function test_an_exhausted_monthly_budget_disables_new_generations_but_not_cached_summaries(): void
    {
        config(['ai.monthly_budget_usd' => 0.001]);
        $this->generate()->assertOk();
        AiRun::query()->update(['cost_micros' => 1_000]);

        $this->generate()->assertOk()->assertJsonPath('meta.insight.cached', true);
        $this->summary()->assertOk()->assertJsonPath('meta.insight.cached', true);

        $this->createTransaction($this->customer, [[$this->product, 1, '100.00']], TransactionStatus::Paid, '2026-09-28 15:00:00');

        $this->generate()
            ->assertStatus(503)
            ->assertExactJson(['message' => 'Insights are currently unavailable.', 'code' => 'ai_disabled'])
            ->assertHeaderMissing('Retry-After');

        $this->assertCount(1, $this->provider->requests());
        $this->assertSame(1, AiRun::query()->where('error_code', AiException::DISABLED)->count());
    }

    public function test_demo_visitors_sharing_the_login_are_limited_per_ip(): void
    {
        config(['ai.limits.demo_daily_per_ip' => 1, 'ai.limits.demo_requests_per_minute_per_ip' => 10]);
        $this->organization->forceFill(['slug' => DemoCommand::ORGANIZATION_SLUG])->save();

        $this->generate()->assertOk();
        $this->generate()->assertOk()->assertJsonPath('meta.insight.cached', true);

        $this->createTransaction($this->customer, [[$this->product, 1, '100.00']], TransactionStatus::Paid, '2026-09-28 15:00:00');

        $this->generate()
            ->assertStatus(429)
            ->assertJsonPath('code', 'ai_quota_exceeded')
            ->assertHeader('Retry-After');

        $this->actingInOrganization($this->owner, $this->organization)
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->postJson(self::URI, self::PERIOD)
            ->assertOk()
            ->assertJsonPath('meta.insight.cached', false);

        $this->assertCount(2, $this->provider->requests());
    }

    public function test_a_generation_in_progress_for_the_same_data_is_never_duplicated(): void
    {
        Sleep::fake(syncWithCarbon: true);
        config(['ai.deadline_seconds' => 1]);
        $lock = Cache::lock($this->summaryLockKey($this->organization, self::PERIOD['from'], self::PERIOD['to']), 30);
        $this->assertTrue($lock->get());

        $this->generate()->assertStatus(504)->assertJsonPath('code', 'ai_timeout');
        $this->assertSame([], $this->provider->requests());

        $lock->release();

        $this->generate()->assertOk()->assertJsonPath('meta.insight.cached', false);
        $this->assertCount(1, $this->provider->requests());
    }

    public function test_the_lock_is_released_after_a_failure(): void
    {
        Sleep::fake(syncWithCarbon: true);
        config(['ai.deadline_seconds' => 1]);
        $this->provider->pushFailure(AiException::providerUnavailable());

        $this->generate()->assertStatus(503);
        $this->generate()->assertOk();

        $this->assertCount(2, $this->provider->requests());
    }

    public function test_only_generating_is_rate_limited(): void
    {
        config(['ai.limits.requests_per_minute' => 1]);

        $this->generate()->assertOk();
        $this->generate()->assertStatus(429)->assertJsonPath('code', 'rate_limited');

        foreach (range(1, 3) as $read) {
            $this->summary()->assertOk();
        }
    }

    public function test_the_period_is_validated_like_the_dashboard_and_never_takes_an_organization(): void
    {
        $other = Organization::factory()->create();

        $this->generate([...self::PERIOD, 'organization_id' => $other->id])->assertUnprocessable()->assertJsonValidationErrors('organization_id');
        $this->generate(['from' => '2026-09-01'])->assertUnprocessable()->assertJsonValidationErrors('to');
        $this->generate(['from' => '2026-09-30', 'to' => '2026-09-01'])->assertUnprocessable()->assertJsonValidationErrors('to');
        $this->generate(['from' => '2025-01-01', 'to' => '2026-09-30'])->assertUnprocessable()->assertJsonValidationErrors('to');
        $this->summary(['from' => 'yesterday', 'to' => '2026-09-30'])->assertUnprocessable()->assertJsonValidationErrors('from');

        $this->assertSame([], $this->provider->requests());
        $this->assertSame(0, AiRun::query()->count());
    }

    public function test_authentication_organization_header_and_membership_are_required(): void
    {
        $outsider = Organization::factory()->create();
        $outsider->enableInsights(null);

        $this->postJson(self::URI, self::PERIOD)->assertUnauthorized();
        $this->actingAs($this->owner)->postJson(self::URI, self::PERIOD)->assertStatus(400);
        $this->actingInOrganization($this->owner, $outsider)->postJson(self::URI, self::PERIOD)->assertForbidden();
        $this->actingInOrganization($this->owner, $outsider)->getJson(self::URI)->assertForbidden();

        $this->assertSame([], $this->provider->requests());
    }

    /**
     * @param  array<string, string>  $query
     */
    private function summary(array $query = self::PERIOD): TestResponse
    {
        return $this->actingInOrganization($this->owner, $this->organization)
            ->getJson(self::URI.'?'.http_build_query($query));
    }

    /**
     * @param  array<string, string>  $body
     */
    private function generate(array $body = self::PERIOD): TestResponse
    {
        return $this->actingInOrganization($this->owner, $this->organization)
            ->postJson(self::URI, $body);
    }
}
