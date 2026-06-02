<?php

namespace Platform\UserConnectors\DTOs;

class Contact
{
    public function __construct(
        public readonly string $id,
        public readonly string $provider,
        public readonly ?string $name,
        public readonly ?string $email,
        public readonly ?string $phone,
        public readonly ?string $company,
        public readonly array $raw,
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'provider' => $this->provider,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'company' => $this->company,
        ];
    }
}
