<?php

namespace App\Data\Push;

/**
 * Outcome of handing messages to the push service. `accepted` means the service took the
 * message, not that the device showed it. `unregisteredTokens` must not be used again.
 */
final readonly class PushDelivery
{
    /**
     * @param  list<string>  $unregisteredTokens
     */
    public function __construct(
        public int $accepted,
        public int $failed,
        public array $unregisteredTokens = [],
    ) {}
}
