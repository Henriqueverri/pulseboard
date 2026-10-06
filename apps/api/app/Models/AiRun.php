<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Telemetry of one AI run (written by AiRunRecorder). Holds no prompt or
 * answer content. Append-only.
 */
class AiRun extends Model
{
    use BelongsToOrganization, HasUuids;

    public const UPDATED_AT = null;

    public const FEATURE_PERIOD_SUMMARY = 'period_summary';

    public const FEATURE_QUESTION = 'question';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_CACHE_HIT = 'cache_hit';

    public const STATUS_INVALID_OUTPUT = 'invalid_output';

    public const STATUS_PROVIDER_ERROR = 'provider_error';

    public const STATUS_TIMEOUT = 'timeout';

    public const STATUS_QUOTA_EXCEEDED = 'quota_exceeded';

    public const STATUS_REFUSED = 'refused';

    /**
     * Runs that reached the provider, so they count against quotas.
     */
    public const BILLABLE_STATUSES = [
        self::STATUS_SUCCEEDED,
        self::STATUS_INVALID_OUTPUT,
        self::STATUS_PROVIDER_ERROR,
        self::STATUS_TIMEOUT,
        self::STATUS_REFUSED,
    ];

    /**
     * Written by AiRunRecorder only, never from request input.
     *
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'input_tokens' => 'integer',
            'cached_input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cost_micros' => 'integer',
            'latency_ms' => 'integer',
            'tool_calls' => 'integer',
            'attempts' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
