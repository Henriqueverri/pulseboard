<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A validated AI answer cached by fingerprint (see PeriodSummaryService).
 * `content` is the model output only; numbers are resolved from the evidence
 * catalog when it is served.
 */
class AiInsight extends Model
{
    use BelongsToOrganization, HasUuids;

    public const UPDATED_AT = null;

    public const KIND_PERIOD_SUMMARY = 'period_summary';

    /**
     * Written by PeriodSummaryService only, never from request input.
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
            'content' => 'array',
        ];
    }
}
