<?php

namespace Platform\UserConnectors\Tools\Telephony;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolResult;

class TestConnectionTool extends TelephonyTool
{
    public function getName(): string
    {
        return 'user-connectors.telephony.test';
    }

    public function getDescription(): string
    {
        return 'Testet eine Telefonie-Verbindung (Sipgate, RingCentral, Vodafone): Token und API-Zugang.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => self::connectionProperties()];
    }

    protected function handle(array $arguments, ToolContext $context): ToolResult
    {
        $connection = $this->connection($context->user, $arguments);
        $result = $this->connectorService($connection)->testConnection($connection);

        if (empty($result['success'])) {
            return ToolResult::error($result['message'] ?? 'Verbindungstest fehlgeschlagen.', 'CONNECTION_FAILED');
        }

        return ToolResult::success($this->connectionInfo($connection) + [
            'status' => 'connected',
            'message' => $result['message'] ?? null,
            'shared' => (int) $connection->owner_user_id !== (int) $context->user->id,
        ]);
    }

    public function getMetadata(): array
    {
        return self::readMetadata(['test', 'connectivity', 'diagnose']);
    }
}
