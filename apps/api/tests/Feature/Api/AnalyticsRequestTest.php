<?php

namespace Tests\Feature\Api;

use App\Http\Requests\Analytics\AnalyticsRequest;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Api\Concerns\InteractsWithOrganizationApi;
use Tests\TestCase;

/**
 * Exercises the shared period request through a test-only route until the analytics endpoints exist.
 */
class AnalyticsRequestTest extends TestCase
{
    use InteractsWithOrganizationApi, RefreshDatabase;

    private Organization $organization;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['api', 'auth:sanctum', 'organization'])
            ->get('/api/testing/analytics-period', function (AnalyticsRequest $request) {
                $period = $request->period();

                return [
                    'from' => $period->from(),
                    'to' => $period->to(),
                    'timezone' => $period->timezone,
                    'start_utc' => $period->startUtc()->toIso8601String(),
                    'end_utc' => $period->endUtc()->toIso8601String(),
                ];
            });

        $this->organization = Organization::factory()->create();
        $this->owner = $this->memberOf($this->organization);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_dates_are_interpreted_in_the_organization_timezone(): void
    {
        $this->period('from=2026-09-01&to=2026-09-30')
            ->assertOk()
            ->assertExactJson([
                'from' => '2026-09-01',
                'to' => '2026-09-30',
                'timezone' => 'America/Sao_Paulo',
                'start_utc' => '2026-09-01T03:00:00+00:00',
                'end_utc' => '2026-10-01T03:00:00+00:00',
            ]);
    }

    public function test_defaults_to_the_last_30_days_ending_today_in_the_organization_timezone(): void
    {
        // Still Sep 30th in São Paulo.
        Carbon::setTestNow('2026-10-01 01:30:00');

        $this->period()
            ->assertOk()
            ->assertJsonPath('from', '2026-09-01')
            ->assertJsonPath('to', '2026-09-30');
    }

    public function test_timezone_query_parameter_is_ignored(): void
    {
        $this->period('from=2026-09-01&to=2026-09-01&timezone=UTC')
            ->assertOk()
            ->assertJsonPath('timezone', 'America/Sao_Paulo')
            ->assertJsonPath('start_utc', '2026-09-01T03:00:00+00:00');
    }

    public function test_organization_id_is_prohibited(): void
    {
        $this->period('organization_id='.Organization::factory()->create()->id)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['organization_id']);
    }

    public function test_from_and_to_must_be_given_together(): void
    {
        $this->period('from=2026-09-01')->assertUnprocessable()->assertJsonValidationErrors(['to']);
        $this->period('to=2026-09-01')->assertUnprocessable()->assertJsonValidationErrors(['from']);
    }

    public function test_rejects_invalid_or_reversed_dates(): void
    {
        $this->period('from=01/09/2026&to=2026-02-30')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['from', 'to']);

        $this->period('from=2026-09-10&to=2026-09-01')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['to' => 'The to date must be on or after the from date.']);
    }

    public function test_period_may_span_at_most_366_days(): void
    {
        $this->period('from=2024-01-01&to=2024-12-31')->assertOk();

        $this->period('from=2025-01-01&to=2026-01-02')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['to' => 'The period may not be longer than 366 days.']);
    }

    public function test_requires_authentication_and_membership(): void
    {
        $this->withHeader('X-Organization-Id', $this->organization->id)
            ->getJson('/api/testing/analytics-period')
            ->assertUnauthorized();

        $this->actingInOrganization($this->owner, Organization::factory()->create())
            ->getJson('/api/testing/analytics-period')
            ->assertForbidden();
    }

    private function period(string $query = ''): TestResponse
    {
        return $this->actingInOrganization($this->owner, $this->organization)
            ->getJson('/api/testing/analytics-period'.($query === '' ? '' : "?{$query}"));
    }
}
