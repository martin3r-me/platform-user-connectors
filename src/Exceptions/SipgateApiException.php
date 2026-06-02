<?php

namespace Platform\UserConnectors\Exceptions;

class SipgateApiException extends ConnectorException
{
    public static function fromResponse(int $status, array $data): static
    {
        $message = $data['message'] ?? $data['error'] ?? ('HTTP ' . $status);
        $code = 'SIPGATE_API_ERROR';

        return new static("Sipgate API: {$message}", $code, $status);
    }

    public static function rateLimited(?int $retryAfter = null): static
    {
        $msg = 'Rate limit erreicht.';
        if ($retryAfter) {
            $msg .= " Retry nach {$retryAfter} Sekunden.";
        }

        return new static($msg, 'RATE_LIMITED', 429);
    }

    public static function connectionError(string $detail): static
    {
        return new static("Verbindungsfehler: {$detail}", 'CONNECTION_ERROR');
    }
}
