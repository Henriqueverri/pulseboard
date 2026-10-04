<?php

namespace Database\Factories;

use App\Models\ApiKey;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * The secret is random and discarded: factory keys exist as rows, not as usable credentials.
 *
 * @extends Factory<ApiKey>
 */
class ApiKeyFactory extends Factory
{
    protected $model = ApiKey::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'name' => 'Integration '.fake()->word(),
            'prefix' => Str::random(12),
            'secret_hash' => hash('sha256', Str::random(40)),
        ];
    }
}
