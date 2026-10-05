<?php

return [

    /*
    |--------------------------------------------------------------------------
    | PulseBoard Insights (AI analytics)
    |--------------------------------------------------------------------------
    |
    | AI_ENABLED is the global kill switch: when false every insights endpoint
    | answers `ai_disabled` and nothing is sent to a provider. The rest of the
    | product never depends on it. Organizations still opt in individually.
    |
    | The API key lives in config/services.php (OPENAI_API_KEY), never here.
    |
    */

    'enabled' => (bool) env('AI_ENABLED', false),

    // openai | scripted (deterministic responses for local development and E2E; refused in production)
    'provider' => env('AI_PROVIDER', 'openai'),

    'model' => env('AI_MODEL', 'gpt-6-luna'),

    /*
    | Provider calls are synchronous (no queue on Render Free). Each attempt has
    | its own HTTP timeout, and a whole run (retries and the repair attempt
    | included) must finish before the deadline, which stays below PHP-FPM's
    | request_terminate_timeout (60 s).
    */
    'timeout_seconds' => (int) env('AI_TIMEOUT_SECONDS', 20),

    'connect_timeout_seconds' => (int) env('AI_CONNECT_TIMEOUT_SECONDS', 5),

    // Only connection errors, 429 and 5xx are retried, never other 4xx.
    'retries' => (int) env('AI_RETRIES', 1),

    'retry_delay_ms' => (int) env('AI_RETRY_DELAY_MS', 500),

    'deadline_seconds' => (int) env('AI_DEADLINE_SECONDS', 45),

    'max_output_tokens' => [
        'period_summary' => 800,
        'question' => 600,
    ],

    /*
    | Estimated cost: USD per 1M tokens, so tokens x price is micro-dollars.
    | An estimate only; the OpenAI dashboard (with its own spend limit) is the
    | source of truth. AI_PRICE_* override the prices of the configured model.
    */
    'pricing' => [
        'gpt-6-luna' => ['input' => 0.10, 'cached_input' => 0.01, 'output' => 0.50],
        'gpt-6.1-sol' => ['input' => 2.00, 'cached_input' => 0.10, 'output' => 10.00],
    ],

    'price_overrides' => [
        'input' => env('AI_PRICE_INPUT_PER_MTOK'),
        'cached_input' => env('AI_PRICE_CACHED_INPUT_PER_MTOK'),
        'output' => env('AI_PRICE_OUTPUT_PER_MTOK'),
    ],

    /*
    | Quotas count runs that reached the provider (cache hits are free), per
    | calendar day in the organization's timezone.
    */
    'limits' => [
        'daily_per_organization' => (int) env('AI_DAILY_ORG_LIMIT', 50),
        'daily_per_user' => (int) env('AI_DAILY_USER_LIMIT', 20),
        'requests_per_minute' => (int) env('AI_REQUESTS_PER_MINUTE', 10),
        // The demo login is shared by every visitor, so the demo is also limited per IP.
        'demo_requests_per_minute_per_ip' => (int) env('AI_DEMO_REQUESTS_PER_MINUTE_PER_IP', 3),
        'demo_daily_per_ip' => (int) env('AI_DEMO_DAILY_IP_LIMIT', 5),
    ],

    /*
    | Global ceiling on the estimated cost (ai_runs.cost_micros) of all
    | organizations in the calendar month (UTC). Once reached, new generations
    | answer `ai_disabled` until the next month; cached summaries are still
    | served. 0 blocks every new generation.
    */
    'monthly_budget_usd' => (float) env('AI_MONTHLY_BUDGET_USD', 5),

    /*
    | Applied by `pulseboard:ai-prune` on every container start. Runs feed the
    | daily quotas and the monthly budget, so they are kept for at least 32 days.
    */
    'retention' => [
        'insights_days' => (int) env('AI_INSIGHTS_RETENTION_DAYS', 30),
        'runs_days' => (int) env('AI_RUNS_RETENTION_DAYS', 90),
    ],

    'insights' => [
        // Below this many paid orders a period gets the low_volume caveat (no trend claims).
        'low_volume_orders' => (int) env('AI_LOW_VOLUME_ORDERS', 20),
    ],

];
