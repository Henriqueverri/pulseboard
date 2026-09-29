<?php

namespace Tests\Feature\Api\Analytics;

use App\Enums\TransactionStatus;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Api\Concerns\InteractsWithOrganizationApi;
use Tests\TestCase;

/**
 * Every analytics endpoint answers only for the organization of the request context:
 * data of another organization never shows up in, nor changes, its responses.
 */
class AnalyticsTenantIsolationTest extends TestCase
{
    use InteractsWithOrganizationApi, RefreshDatabase;

    private const PERIOD = 'from=2026-09-01&to=2026-09-30';

    private Organization $organizationA;

    private Organization $organizationB;

    private User $ownerA;

    private User $ownerB;

    /**
     * Identifiers of organization A's records, which must never reach organization B.
     *
     * @var list<string>
     */
    private array $markersA = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->organizationA = Organization::factory()->create();
        $this->organizationB = Organization::factory()->create(['timezone' => 'Europe/Lisbon']);
        $this->ownerA = $this->memberOf($this->organizationA);
        $this->ownerB = $this->memberOf($this->organizationB);

        $product = Product::factory()->for($this->organizationA)->create(['name' => 'Tenant A Product', 'sku' => 'A-ONLY', 'price' => '10.00']);
        $customer = Customer::factory()->for($this->organizationA)->create(['name' => 'Tenant A Customer', 'email' => 'a-only@example.com']);

        $transactions = [
            $this->createTransaction($customer, [[$product, 2, '10.00']], TransactionStatus::Paid, '2026-09-10 15:00:00'),
            $this->createTransaction($customer, [[$product, 1, '10.00']], TransactionStatus::Refunded, '2026-09-11 15:00:00'),
            $this->createTransaction($customer, [[$product, 1, '10.00']], TransactionStatus::Paid, '2026-08-10 15:00:00'),
        ];

        $this->markersA = [
            $this->organizationA->id, $product->id, $product->name, $product->sku,
            $customer->id, $customer->name, $customer->email,
            ...array_map(fn ($transaction) => $transaction->id, $transactions),
        ];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function endpoints(): array
    {
        return [
            'dashboard' => ['/api/v1/dashboard?'.self::PERIOD],
            'revenue' => ['/api/v1/analytics/revenue?'.self::PERIOD],
            'products' => ['/api/v1/analytics/products?'.self::PERIOD.'&limit=50'],
            'customers' => ['/api/v1/analytics/customers?'.self::PERIOD.'&limit=50'],
            'transaction status' => ['/api/v1/analytics/transactions?'.self::PERIOD],
        ];
    }

    #[DataProvider('endpoints')]
    public function test_another_organization_data_never_changes_nor_appears_in_the_response(string $uri): void
    {
        $before = $this->actingInOrganization($this->ownerA, $this->organizationA)->getJson($uri)->assertOk()->json();

        $markersB = $this->seedOrganizationB();

        $after = $this->actingInOrganization($this->ownerA, $this->organizationA)->getJson($uri)->assertOk();

        $this->assertSame($before, $after->json(), 'data added to B must not change A');
        $this->assertContainsNone($markersB, $after);

        $responseB = $this->actingInOrganization($this->ownerB, $this->organizationB)->getJson($uri)->assertOk();

        $this->assertNotSame($before, $responseB->json());
        $this->assertSame('Europe/Lisbon', $responseB->json('meta.timezone'));
        $this->assertContainsNone($this->markersA, $responseB);
    }

    public function test_each_organization_sees_only_its_own_figures(): void
    {
        $this->seedOrganizationB();

        $dashboardA = $this->actingInOrganization($this->ownerA, $this->organizationA)->getJson('/api/v1/dashboard?'.self::PERIOD)->assertOk();
        $dashboardB = $this->actingInOrganization($this->ownerB, $this->organizationB)->getJson('/api/v1/dashboard?'.self::PERIOD)->assertOk();

        $this->assertSame(['value' => '20.00', 'previous' => '10.00', 'change' => 100.0], $dashboardA->json('data.revenue'));
        $this->assertSame(['value' => '1975.30', 'previous' => '987.65', 'change' => 100.0], $dashboardB->json('data.revenue'));

        $statusA = $this->actingInOrganization($this->ownerA, $this->organizationA)->getJson('/api/v1/analytics/transactions?'.self::PERIOD)->assertOk();
        $statusB = $this->actingInOrganization($this->ownerB, $this->organizationB)->getJson('/api/v1/analytics/transactions?'.self::PERIOD)->assertOk();

        $this->assertSame([1, 1, 0, 0], $statusA->json('data.*.orders.value'));
        $this->assertSame([2, 0, 1, 1], $statusB->json('data.*.orders.value'));

        $this->assertSame(['Tenant A Product'], $this->actingInOrganization($this->ownerA, $this->organizationA)
            ->getJson('/api/v1/analytics/products?'.self::PERIOD.'&limit=50')->json('data.*.product.name'));
        $this->assertSame(['Tenant B Secret Customer'], $this->actingInOrganization($this->ownerB, $this->organizationB)
            ->getJson('/api/v1/analytics/customers?'.self::PERIOD.'&limit=50')->json('data.*.customer.name'));
    }

    #[DataProvider('endpoints')]
    public function test_organization_header_without_membership_is_forbidden(string $uri): void
    {
        $this->actingInOrganization($this->ownerA, $this->organizationB)->getJson($uri)->assertForbidden();
    }

    #[DataProvider('endpoints')]
    public function test_requires_authentication(string $uri): void
    {
        $this->withHeader('X-Organization-Id', $this->organizationA->id)->getJson($uri)->assertUnauthorized();
    }

    #[DataProvider('endpoints')]
    public function test_member_role_can_read(string $uri): void
    {
        $member = $this->memberOf($this->organizationA, Organization::ROLE_MEMBER);

        $this->actingInOrganization($member, $this->organizationA)->getJson($uri)->assertOk();
    }

    #[DataProvider('endpoints')]
    public function test_organization_id_in_the_query_is_rejected(string $uri): void
    {
        foreach ([$this->organizationA, $this->organizationB] as $organization) {
            $this->actingInOrganization($this->ownerA, $this->organizationA)
                ->getJson("{$uri}&organization_id={$organization->id}")
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['organization_id']);
        }
    }

    /**
     * Distinctive records and amounts for organization B, including statuses A does not have.
     *
     * @return list<string>
     */
    private function seedOrganizationB(): array
    {
        $product = Product::factory()->for($this->organizationB)->create(['name' => 'Tenant B Secret Product', 'sku' => 'B-SECRET', 'price' => '987.65']);
        $customer = Customer::factory()->for($this->organizationB)->create(['name' => 'Tenant B Secret Customer', 'email' => 'b-secret@example.com']);

        $transactions = [
            $this->createTransaction($customer, [[$product, 1, '987.65']], TransactionStatus::Paid, '2026-09-10 15:00:00'),
            $this->createTransaction($customer, [[$product, 1, '987.65']], TransactionStatus::Paid, '2026-09-12 15:00:00'),
            $this->createTransaction($customer, [[$product, 1, '987.65']], TransactionStatus::Pending, '2026-09-13 15:00:00'),
            $this->createTransaction($customer, [[$product, 1, '987.65']], TransactionStatus::Canceled, '2026-09-14 15:00:00'),
            $this->createTransaction($customer, [[$product, 1, '987.65']], TransactionStatus::Paid, '2026-08-10 15:00:00'),
        ];

        return [
            $this->organizationB->id, $product->id, $product->name, $product->sku,
            $customer->id, $customer->name, $customer->email, '987.65',
            ...array_map(fn ($transaction) => $transaction->id, $transactions),
        ];
    }

    /**
     * @param  list<string>  $markers
     */
    private function assertContainsNone(array $markers, TestResponse $response): void
    {
        foreach ($markers as $marker) {
            $this->assertStringNotContainsString($marker, $response->getContent());
        }
    }
}
