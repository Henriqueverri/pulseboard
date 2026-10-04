<?php

namespace Tests\Feature\Domain;

use App\Enums\TransactionSource;
use App\Models\ApiKey;
use App\Models\Organization;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiKeyModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_belongs_to_an_organization_and_its_creator(): void
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->create();

        $apiKey = ApiKey::factory()->for($organization)->create(['created_by_user_id' => $owner->id]);

        $this->assertTrue($apiKey->organization->is($organization));
        $this->assertTrue($apiKey->createdBy->is($owner));
        $this->assertTrue($organization->apiKeys->contains($apiKey));
    }

    public function test_secret_hash_is_never_serialized(): void
    {
        $apiKey = ApiKey::factory()->create();

        $this->assertArrayNotHasKey('secret_hash', $apiKey->toArray());
        $this->assertStringNotContainsString($apiKey->secret_hash, $apiKey->toJson());
        $this->assertSame(64, strlen($apiKey->secret_hash));
    }

    public function test_prefix_and_secret_hash_are_not_mass_assignable(): void
    {
        $apiKey = new ApiKey([
            'name' => 'Shop',
            'prefix' => 'chosenbyuser',
            'secret_hash' => str_repeat('a', 64),
            'revoked_at' => now(),
        ]);

        $this->assertSame('Shop', $apiKey->name);
        $this->assertNull($apiKey->prefix);
        $this->assertNull($apiKey->secret_hash);
        $this->assertNull($apiKey->revoked_at);
    }

    public function test_prefix_is_unique_across_organizations(): void
    {
        ApiKey::factory()->create(['prefix' => 'Ab12Cd34Ef56']);

        $this->expectException(UniqueConstraintViolationException::class);

        ApiKey::factory()->create(['prefix' => 'Ab12Cd34Ef56']);
    }

    public function test_keys_are_deleted_with_their_organization(): void
    {
        $organization = Organization::factory()->create();
        ApiKey::factory()->for($organization)->count(2)->create();
        $kept = ApiKey::factory()->create();

        $organization->delete();

        $this->assertSame([$kept->id], ApiKey::query()->pluck('id')->all());
    }

    public function test_deleting_the_creator_keeps_the_key(): void
    {
        $owner = User::factory()->create();
        $apiKey = ApiKey::factory()->create(['created_by_user_id' => $owner->id]);

        $owner->delete();

        $this->assertModelExists($apiKey);
        $this->assertNull($apiKey->refresh()->created_by_user_id);
    }

    public function test_deleting_a_key_keeps_the_transactions_it_ingested(): void
    {
        $organization = Organization::factory()->create();
        $apiKey = ApiKey::factory()->for($organization)->create();
        $transaction = Transaction::factory()->for($organization)->create([
            'source' => TransactionSource::Ingest,
            'api_key_id' => $apiKey->id,
        ]);
        $transaction->statusChanges()->update(['api_key_id' => $apiKey->id]);

        $apiKey->delete();

        $this->assertModelExists($transaction);
        $this->assertNull($transaction->refresh()->api_key_id);
        $this->assertSame(TransactionSource::Ingest, $transaction->source);
        $this->assertNull($transaction->statusChanges()->sole()->api_key_id);
    }
}
