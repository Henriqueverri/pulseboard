<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * Something that happened in an organization, broadcast to its members on
 * `private-organization.{id}`. Dispatched through {@see DomainEvents}, after the change is
 * committed and after the response is sent, so a broadcast never delays or fails an ingestion.
 */
abstract class OrganizationEvent implements ShouldBroadcastNow
{
    public function __construct(public readonly string $organizationId) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('organization.'.$this->organizationId);
    }

    abstract public function broadcastAs(): string;

    /**
     * @return array<string, mixed>
     */
    abstract public function broadcastWith(): array;
}
