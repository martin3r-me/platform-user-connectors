<?php

namespace Platform\UserConnectors\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Platform\UserConnectors\Models\UserConnectorInboundEvent;

/**
 * Dispatched for message-specific events (new SMS, new email).
 */
class InboundMessageEvent
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly UserConnectorInboundEvent $event,
        public readonly string $messageType, // 'sms', 'email', 'teams'
    ) {}
}
