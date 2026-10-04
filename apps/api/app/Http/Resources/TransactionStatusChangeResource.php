<?php

namespace App\Http\Resources;

use App\Models\TransactionStatusChange;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * occurred_at is the business time reported by the source; recorded_at is when PulseBoard stored it.
 *
 * @mixin TransactionStatusChange
 */
class TransactionStatusChangeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'from_status' => $this->from_status?->value,
            'to_status' => $this->to_status->value,
            'occurred_at' => $this->occurred_at,
            'recorded_at' => $this->created_at,
            'source' => $this->source->value,
        ];
    }
}
