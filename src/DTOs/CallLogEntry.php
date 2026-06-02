<?php

namespace Platform\UserConnectors\DTOs;

use Carbon\Carbon;

class CallLogEntry
{
    public function __construct(
        public readonly string $id,
        public readonly string $provider,
        public readonly string $direction,
        public readonly string $type,
        public readonly ?string $from,
        public readonly ?string $to,
        public readonly Carbon $startTime,
        public readonly ?int $durationSeconds,
        public readonly string $result,
        public readonly array $raw,
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'provider' => $this->provider,
            'direction' => $this->direction,
            'type' => $this->type,
            'from' => $this->from,
            'to' => $this->to,
            'start_time' => $this->startTime->toIso8601String(),
            'duration_seconds' => $this->durationSeconds,
            'result' => $this->result,
        ];
    }
}
