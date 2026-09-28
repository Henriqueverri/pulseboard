<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Expects an empty database: use `php artisan migrate:fresh --seed`.
 *
 * Model events must stay enabled: domain models derive totals and enforce
 * same-organization references in saving/saved hooks.
 */
class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $owner = User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $member = User::factory()->create([
            'name' => 'Demo Member',
            'email' => 'member@example.com',
        ]);

        $organization = Organization::query()->create([
            'name' => 'PulseBoard Demo Store',
            'slug' => Organization::uniqueSlugFrom('PulseBoard Demo Store'),
            'currency' => 'BRL',
        ]);

        $organization->users()->attach($owner->id, ['role' => Organization::ROLE_OWNER]);
        $organization->users()->attach($member->id, ['role' => Organization::ROLE_MEMBER]);

        $this->call(DemoDataSeeder::class, parameters: ['organization' => $organization]);
    }
}
