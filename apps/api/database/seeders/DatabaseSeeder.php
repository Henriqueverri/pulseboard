<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $user = User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $organization = Organization::query()->create([
            'name' => "Test User's Organization",
            'slug' => Organization::uniqueSlugFrom("Test User's Organization"),
            'currency' => 'BRL',
        ]);

        $organization->users()->attach($user->id, [
            'role' => Organization::ROLE_OWNER,
        ]);
    }
}
