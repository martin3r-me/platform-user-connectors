<?php

namespace Platform\UserConnectors\DTOs;

class Attendee
{
    public function __construct(
        public readonly string $email,
        public readonly ?string $name,
        public readonly string $status,
        public readonly string $type,
    ) {}

    public function toArray(): array
    {
        return [
            'email' => $this->email,
            'name' => $this->name,
            'status' => $this->status,
            'type' => $this->type,
        ];
    }
}
