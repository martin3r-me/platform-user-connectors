<?php

namespace Platform\UserConnectors\Exceptions;

class RingCentralApiException extends ConnectorException
{
    public static function fromResponse(int $status, array $data): static
    {
        $message = $data['message'] ?? $data['error_description'] ?? ('HTTP ' . $status);
        $code = $data['errorCode'] ?? 'RC_API_ERROR';

        return new static("RingCentral API: {$message}", $code, $status);
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
