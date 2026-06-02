<?php

namespace Platform\UserConnectors\DTOs;

use Carbon\Carbon;

class Message
{
    public function __construct(
        public readonly string $id,
        public readonly string $provider,
        public readonly string $from,
        public readonly array $to,
        public readonly ?string $subject,
        public readonly string $body,
        public readonly string $bodyType,
        public readonly Carbon $date,
        public readonly bool $isRead,
        public readonly ?string $threadId,
        public readonly array $attachments,
        public readonly array $raw,
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'provider' => $this->provider,
            'from' => $this->from,
            'to' => $this->to,
            'subject' => $this->subject,
            'body' => $this->body,
            'body_type' => $this->bodyType,
            'date' => $this->date->toIso8601String(),
            'is_read' => $this->isRead,
            'thread_id' => $this->threadId,
            'attachments' => $this->attachments,
        ];
    }
}
