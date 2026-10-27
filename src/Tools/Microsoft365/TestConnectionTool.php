<?php

namespace Platform\UserConnectors\Tools\Microsoft365;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolResult;
use Platform\UserConnectors\Services\ConnectionResolver;
use Platform\UserConnectors\Services\Microsoft365\Microsoft365ConnectorService;
use Platform\UserConnectors\Services\Microsoft365\Microsoft365EventTime;

class TestConnectionTool extends Microsoft365Tool
{
    public function getName(): string
    {
        return 'user-connectors.microsoft365.test';
    }

    public function getDescription(): string
    {
        return 'Testet die Microsoft-365-Verbindung (Token + Graph /me) und liefert Konto, Connection-ID und Plattform-Zeitzone. '
            . 'Erste Hilfe, wenn Mail-/Kalender-/Teams-Tools mit UNAUTHORIZED oder NO_CONNECTION scheitern.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['connection_id' => self::connectionProperty()],
        ];
    }

    protected function handle(array $arguments, ToolContext $context): ToolResult
    {
        $service = app(Microsoft365ConnectorService::class);
        $connection = !empty($arguments['connection_id'])
            ? app(ConnectionResolver::class)->resolveById((int) $arguments['connection_id'], $context->user)
            : $service->getConnectionForUser($context->user);

        if (!$connection || $connection->connector?->key !== 'microsoft365') {
            return ToolResult::error('Keine (zugängliche) Microsoft-365-Verbindung gefunden.', 'NO_CONNECTION');
        }

        $result = $service->testConnection($connection);
        if (!$result['success']) {
            return ToolResult::error($result['message'], 'CONNECTION_FAILED');
        }

        $profile = $connection->credentials['profile'] ?? [];

        return ToolResult::success([
            'status' => 'connected',
            'message' => $result['message'],
            'connection_id' => $connection->id,
            'account' => $profile['mail'] ?? $profile['userPrincipalName'] ?? null,
            'shared' => (int) $connection->owner_user_id !== (int) $context->user->id,
            'timezone' => Microsoft365EventTime::defaultTimezone(),
        ]);
    }

    public function getMetadata(): array
    {
        return self::readMetadata(['test', 'connectivity', 'diagnose']);
    }
}
