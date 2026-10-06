<?php

namespace App\Data\Ai;

use InvalidArgumentException;

final readonly class LlmMessage
{
    public const ROLE_USER = 'user';

    public const ROLE_ASSISTANT = 'assistant';

    public function __construct(
        public string $role,
        public string $content,
    ) {
        if (! in_array($role, [self::ROLE_USER, self::ROLE_ASSISTANT], true)) {
            throw new InvalidArgumentException("Unsupported message role: {$role}.");
        }
    }

    public static function user(string $content): self
    {
        return new self(self::ROLE_USER, $content);
    }

    public static function assistant(string $content): self
    {
        return new self(self::ROLE_ASSISTANT, $content);
    }
}
