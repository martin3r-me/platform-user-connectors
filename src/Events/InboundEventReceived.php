<?php

namespace Platform\UserConnectors\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Platform\UserConnectors\Models\UserConnectorInboundEvent;

/**
 * Dispatched when a new inbound event is received from any connector provider.
 *
 * Listeners can subscribe to this event to handle real-time notifications,
 * UI updates, or any other processing.
 */
class InboundEventReceived
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly UserConnectorInboundEvent $event,
    ) {}
}
