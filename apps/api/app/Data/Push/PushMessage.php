<?php

namespace App\Data\Push;

/**
 * One notification to one Expo push token. `data` reaches the app untouched (the screen to
 * open and the organization it belongs to).
 */
final readonly class PushMessage
{
    /**
     * @param  array<string, string>  $data
     */
    public function __construct(
        public string $token,
        public string $title,
        public string $body,
        public array $data = [],
    ) {}
}
