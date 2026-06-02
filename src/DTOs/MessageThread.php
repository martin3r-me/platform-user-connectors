<?php

namespace Platform\UserConnectors\DTOs;

class MessageThread
{
    public function __construct(
        public readonly string $id,
        public readonly string $provider,
        public readonly ?string $subject,
        public readonly int $messageCount,
        public readonly ?Message $lastMessage,
        public readonly array $raw,
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'provider' => $this->provider,
            'subject' => $this->subject,
            'message_count' => $this->messageCount,
            'last_message' => $this->lastMessage?->toArray(),
        ];
    }
}
