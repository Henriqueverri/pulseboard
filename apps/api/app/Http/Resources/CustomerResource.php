<?php

namespace App\Http\Resources;

use App\Models\Customer;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Customer
 */
class CustomerResource extends JsonResource
{
    /**
     * @var array{orders_count: int, total_spent: string, recent_transactions: Collection<int, Transaction>}|null
     */
    private ?array $metrics = null;

    /**
     * @param  array{orders_count: int, total_spent: string, recent_transactions: Collection<int, Transaction>}  $metrics
     */
    public function withMetrics(array $metrics): static
    {
        $this->metrics = $metrics;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            $this->mergeWhen($this->metrics !== null, fn () => [
                'orders_count' => $this->metrics['orders_count'],
                'total_spent' => $this->metrics['total_spent'],
                'recent_transactions' => TransactionResource::collection($this->metrics['recent_transactions']),
            ]),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
