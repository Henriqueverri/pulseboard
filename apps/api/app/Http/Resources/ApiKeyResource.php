<?php

namespace App\Http\Resources;

use App\Models\ApiKey;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The secret (and its hash) is never rendered, except plain_text_key in the
 * single response that creates the key.
 *
 * @mixin ApiKey
 */
class ApiKeyResource extends JsonResource
{
    private ?string $plainTextKey = null;

    public function withPlainTextKey(string $plainTextKey): static
    {
        $this->plainTextKey = $plainTextKey;

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
            'prefix' => $this->prefix,
            'status' => $this->status(),
            'created_by' => $this->whenLoaded('createdBy', fn () => $this->createdBy === null ? null : [
                'id' => $this->createdBy->id,
                'name' => $this->createdBy->name,
            ]),
            'last_used_at' => $this->last_used_at,
            'expires_at' => $this->expires_at,
            'revoked_at' => $this->revoked_at,
            'created_at' => $this->created_at,
            $this->mergeWhen($this->plainTextKey !== null, fn () => ['plain_text_key' => $this->plainTextKey]),
        ];
    }
}
