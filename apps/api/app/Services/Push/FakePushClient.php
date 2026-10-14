<?php

namespace App\Services\Push;

use App\Data\Push\PushDelivery;
use App\Data\Push\PushMessage;
use Illuminate\Support\Facades\Log;

/**
 * PUSH_DRIVER=fake: nothing leaves the process. Tests read `$sent`; local development sees
 * one log line per message. Tokens listed in `$unregistered` are answered as Expo answers a
 * dead token.
 */
final class FakePushClient implements PushClient
{
    /** @var list<PushMessage> */
    public array $sent = [];

    /**
     * @param  list<string>  $unregistered
     */
    public function __construct(public array $unregistered = []) {}

    public function send(array $messages): PushDelivery
    {
        $accepted = 0;
        $unregistered = [];

        foreach ($messages as $message) {
            if (in_array($message->token, $this->unregistered, true)) {
                $unregistered[] = $message->token;

                continue;
            }

            $this->sent[] = $message;
            $accepted++;

            Log::debug('Fake push sent.', ['event' => 'push.fake', 'title' => $message->title, 'body' => $message->body]);
        }

        return new PushDelivery($accepted, count($unregistered), $unregistered);
    }
}
