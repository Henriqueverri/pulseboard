<?php

namespace Tests\Feature;

use App\Console\Commands\DemoCommand;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReleaseCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_runs_the_migrations_and_seeds_the_demo_organization(): void
    {
        config(['demo.password' => 'demo-password-123']);

        $this->artisan('pulseboard:release')->assertSuccessful();
        $this->artisan('pulseboard:release')->assertSuccessful();

        $organization = Organization::query()->where('slug', DemoCommand::ORGANIZATION_SLUG)->sole();

        $this->assertSame(2, $organization->users()->count());
        $this->assertSame(40, $organization->products()->count());
    }

    public function test_fails_when_the_demo_seed_fails(): void
    {
        config(['demo.password' => 'short']);

        $this->artisan('pulseboard:release')->assertFailed();

        $this->assertSame(0, Organization::query()->count());
        $this->assertSame(0, User::query()->count());
    }
}
