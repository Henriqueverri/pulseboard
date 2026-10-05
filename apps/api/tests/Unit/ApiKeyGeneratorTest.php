<?php

namespace Tests\Unit;

use App\Models\ApiKey;
use App\Support\ApiKeyGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ApiKeyGeneratorTest extends TestCase
{
    public function test_generates_a_key_in_the_pb_prefix_secret_format(): void
    {
        $generated = (new ApiKeyGenerator)->generate();

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{12}$/', $generated['prefix']);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{40}$/', $generated['secret']);
        $this->assertSame("pb_{$generated['prefix']}_{$generated['secret']}", $generated['plain_text_key']);
        $this->assertSame(hash('sha256', $generated['secret']), $generated['secret_hash']);
    }

    public function test_every_key_is_different(): void
    {
        $generator = new ApiKeyGenerator;
        $keys = array_map(fn () => $generator->generate(), range(1, 50));

        $this->assertCount(50, array_unique(array_column($keys, 'prefix')));
        $this->assertCount(50, array_unique(array_column($keys, 'secret')));
    }

    public function test_parses_a_generated_key_back_into_prefix_and_secret(): void
    {
        $generator = new ApiKeyGenerator;
        $generated = $generator->generate();

        $this->assertSame(
            ['prefix' => $generated['prefix'], 'secret' => $generated['secret']],
            $generator->parse($generated['plain_text_key']),
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function malformedTokens(): array
    {
        $prefix = str_repeat('a', 12);
        $secret = str_repeat('b', 40);

        return [
            'empty' => [''],
            'no pb_ marker' => ["xx_{$prefix}_{$secret}"],
            'short prefix' => ['pb_'.str_repeat('a', 11)."_{$secret}"],
            'long secret' => ["pb_{$prefix}_{$secret}c"],
            'short secret' => ["pb_{$prefix}_".str_repeat('b', 39)],
            'non base62 secret' => ["pb_{$prefix}_".str_repeat('b', 39).'-'],
            'trailing newline' => ["pb_{$prefix}_{$secret}\n"],
            'sanctum style token' => ['1|abcdef'],
        ];
    }

    #[DataProvider('malformedTokens')]
    public function test_rejects_malformed_tokens(string $token): void
    {
        $this->assertNull((new ApiKeyGenerator)->parse($token));
    }

    public function test_matches_only_the_secret_whose_hash_is_stored(): void
    {
        $generator = new ApiKeyGenerator;
        $generated = $generator->generate();
        $apiKey = (new ApiKey)->forceFill(['secret_hash' => $generated['secret_hash']]);

        $this->assertTrue($generator->matches($apiKey, $generated['secret']));
        $this->assertFalse($generator->matches($apiKey, $generator->generate()['secret']));
        $this->assertFalse($generator->matches($apiKey, $generated['secret_hash']));
    }
}
