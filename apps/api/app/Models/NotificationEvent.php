<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A push the organization was sent, claimed by (kind, dedupe_key) before sending. Holds no
 * message content. Written by PushNotifier only.
 */
class NotificationEvent extends Model
{
    use BelongsToOrganization, HasUuids;

    public const UPDATED_AT = null;

    public const KIND_STOCK_LOW = 'stock_low';

    public const KIND_STOCK_OUT = 'stock_out';

    public const KIND_DAILY_GOAL = 'daily_goal_reached';

    /**
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'recipients' => 'integer',
        ];
    }
}
