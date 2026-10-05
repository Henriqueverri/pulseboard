<?php

namespace Tests\Feature\Ai;

use Tests\TestCase;

/**
 * Only the guards of pulseboard:ai-eval: the evaluation itself runs outside
 * PHPUnit (`php artisan pulseboard:ai-eval`), never as part of this suite.
 */
class AiEvalCommandTest extends TestCase
{
    public function test_it_never_runs_in_production(): void
    {
        $this->app['env'] = 'production';

        $this->artisan('pulseboard:ai-eval')
            ->expectsOutputToContain('never runs in production')
            ->assertFailed();
    }

    public function test_it_rejects_an_unknown_provider_or_repeat(): void
    {
        $this->artisan('pulseboard:ai-eval', ['--provider' => 'anthropic'])->assertFailed();
        $this->artisan('pulseboard:ai-eval', ['--repeat' => 0])->assertFailed();
    }

    public function test_the_real_model_needs_an_api_key(): void
    {
        config(['services.openai.key' => '']);

        $this->artisan('pulseboard:ai-eval', ['--provider' => 'openai'])
            ->expectsOutputToContain('OPENAI_API_KEY')
            ->assertFailed();
    }

    public function test_an_unknown_case_is_rejected_before_anything_runs(): void
    {
        $this->artisan('pulseboard:ai-eval', ['--case' => ['does_not_exist']])
            ->expectsOutputToContain('Unknown case: does_not_exist')
            ->assertFailed();
    }
}
