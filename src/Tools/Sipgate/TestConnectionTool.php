<?php

namespace Platform\UserConnectors\Tools\Sipgate;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\UserConnectors\Services\ConnectionResolver;
use Platform\UserConnectors\Services\Sipgate\SipgateConnectorService;

class TestConnectionTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'user-connectors.sipgate.test';
    }

    public function getDescription(): string
    {
        return 'Testet die Sipgate-Verbindung (OAuth2 Token-Validierung und API-Erreichbarkeit).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'connection_id' => ['type' => 'integer', 'description' => 'Optionale Connection-ID.'],
            ],
            'required' => [],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        if (!$context->user) {
            return ToolResult::error('AUTH_ERROR', 'Benutzer nicht authentifiziert.');
        }

        try {
            $service = app(SipgateConnectorService::class);
            $connectionId = $arguments['connection_id'] ?? null;

            if ($connectionId) {
                $resolver = app(ConnectionResolver::class);
                $connection = $resolver->resolveById($connectionId, $context->user);
            } else {
                $connection = $service->getConnectionForUser($context->user);
            }

            if (!$connection) {
                return ToolResult::error('NO_CONNECTION', 'Keine Sipgate-Verbindung konfiguriert.');
            }

            $result = $service->testConnection($connection);

            if ($result['success']) {
                return ToolResult::success([
                    'status' => 'connected',
                    'message' => $result['message'],
                    'connection_id' => $connection->id,
                ]);
            }

            return ToolResult::error('CONNECTION_FAILED', $result['message']);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['sipgate', 'test', 'connectivity', 'telefonie'],
            'read_only' => true,
            'requires_auth' => true,
            'risk_level' => 'safe',
        ];
    }
}
