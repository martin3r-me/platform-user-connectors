<?php

namespace Platform\UserConnectors\Exceptions;

class ConnectorException extends \RuntimeException
{
    protected ?string $errorCode;

    public function __construct(string $message, ?string $errorCode = null, int $httpStatus = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $httpStatus, $previous);
        $this->errorCode = $errorCode;
    }

    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    public static function noConnection(string $connector = ''): static
    {
        $msg = $connector
            ? "Keine {$connector}-Verbindung konfiguriert."
            : 'Keine Verbindung konfiguriert.';

        return new static($msg, 'NO_CONNECTION');
    }

    public static function unauthorized(): static
    {
        return new static('Token ungültig oder abgelaufen. Bitte erneut verbinden.', 'UNAUTHORIZED', 401);
    }
}
