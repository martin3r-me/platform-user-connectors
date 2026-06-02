<?php

namespace Platform\UserConnectors\DTOs;

use Carbon\Carbon;

class Availability
{
    public function __construct(
        public readonly string $status,
        public readonly Carbon $start,
        public readonly Carbon $end,
    ) {}

    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'start' => $this->start->toIso8601String(),
            'end' => $this->end->toIso8601String(),
        ];
    }
}
