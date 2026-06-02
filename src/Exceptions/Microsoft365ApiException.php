<?php

namespace Platform\UserConnectors\Exceptions;

class Microsoft365ApiException extends ConnectorException
{
    public static function apiError(int $status, array $data): static
    {
        $error = $data['error'] ?? [];
        $message = $error['message'] ?? ('HTTP ' . $status);
        $code = $error['code'] ?? 'GRAPH_ERROR';

        return new static("Microsoft Graph API: {$message}", $code, $status);
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
