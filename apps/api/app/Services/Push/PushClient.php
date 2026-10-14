<?php

namespace App\Services\Push;

use App\Data\Push\PushDelivery;
use App\Data\Push\PushMessage;

/**
 * The boundary with the push service. Implementations never throw for a delivery failure:
 * it is reported in the PushDelivery, since a push is a side effect of something that
 * already succeeded.
 */
interface PushClient
{
    /**
     * @param  list<PushMessage>  $messages
     */
    public function send(array $messages): PushDelivery;
}
