<?php

namespace Platform\UserConnectors\DTOs;

class Pagination
{
    public function __construct(
        public readonly int $page = 1,
        public readonly int $perPage = 25,
        public readonly ?int $total = null,
        public readonly ?string $nextLink = null,
        public readonly ?string $skipToken = null,
    ) {}

    public function toArray(): array
    {
        return array_filter([
            'page' => $this->page,
            'per_page' => $this->perPage,
            'total' => $this->total,
            'next_link' => $this->nextLink,
            'skip_token' => $this->skipToken,
        ], fn ($v) => $v !== null);
    }
}
