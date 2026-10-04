<?php

namespace App\Support;

use App\Models\ApiKey;
use Illuminate\Support\Str;

/**
 * Issues and verifies integration keys in the format pb_<prefix>_<secret>.
 *
 * The prefix is public (it identifies the key in lookups, logs and the UI); the
 * secret has ~238 bits of entropy, so a plain SHA-256 is enough to store it:
 * a slow hash like bcrypt protects low-entropy passwords and would only add latency here.
 */
final class ApiKeyGenerator
{
    public const PREFIX_LENGTH = 12;

    public const SECRET_LENGTH = 40;

    private const PATTERN = '/\Apb_([A-Za-z0-9]{12})_([A-Za-z0-9]{40})\z/';

    /**
     * Str::random draws from random_bytes and keeps only [A-Za-z0-9].
     *
     * @return array{prefix: string, secret: string, secret_hash: string, plain_text_key: string}
     */
    public function generate(): array
    {
        $prefix = Str::random(self::PREFIX_LENGTH);
        $secret = Str::random(self::SECRET_LENGTH);

        return [
            'prefix' => $prefix,
            'secret' => $secret,
            'secret_hash' => self::hash($secret),
            'plain_text_key' => "pb_{$prefix}_{$secret}",
        ];
    }

    /**
     * @return array{prefix: string, secret: string}|null null when the token is not a well-formed key
     */
    public function parse(string $token): ?array
    {
        if (preg_match(self::PATTERN, $token, $matches) !== 1) {
            return null;
        }

        return ['prefix' => $matches[1], 'secret' => $matches[2]];
    }

    public function matches(ApiKey $apiKey, string $secret): bool
    {
        return hash_equals($apiKey->secret_hash, self::hash($secret));
    }

    public static function hash(string $secret): string
    {
        return hash('sha256', $secret);
    }
}
