<?php

namespace Platform\UserConnectors\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Platform\UserConnectors\Models\UserConnectorInboundEvent;

/**
 * Dispatched for call-specific events (new call, answered, hangup).
 */
class InboundCallEvent
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly UserConnectorInboundEvent $event,
        public readonly string $callStatus, // 'ringing', 'answered', 'hangup'
    ) {}
}
