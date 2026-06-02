<?php

namespace Platform\UserConnectors\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\UserConnectors\Models\UserConnectorConnection;
use Platform\UserConnectors\Models\UserConnectorPhoneNumber;
use Platform\UserConnectors\Services\ConnectionResolver;

class ListPhoneNumbersTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'user-connectors.phone-numbers.list';
    }

    public function getDescription(): string
    {
        return 'Listet alle Rufnummern einer Connector-Verbindung auf. Zeigt Nummer, Typ, Fähigkeiten (voice/sms/fax), Standard-Status und Provider-ID. Filterbar nach Connector (sipgate, ringcentral, vodafone) und Fähigkeit.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'connection_id' => ['type' => 'integer', 'description' => 'Connection-ID. Wenn leer, werden Nummern aller Verbindungen des Users zurückgegeben.'],
                'connector_key' => ['type' => 'string', 'description' => 'Filter nach Connector: sipgate, ringcentral, vodafone.'],
                'capability' => ['type' => 'string', 'description' => 'Filter nach Fähigkeit: voice, sms, fax.'],
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
            $resolver = app(ConnectionResolver::class);

            if (!empty($arguments['connection_id'])) {
                $connection = $resolver->resolveById((int) $arguments['connection_id'], $context->user);
                if (!$connection) {
                    return ToolResult::error('NOT_FOUND', 'Verbindung nicht gefunden oder kein Zugriff.');
                }
                $connectionIds = [$connection->id];
            } elseif (!empty($arguments['connector_key'])) {
                $connections = $resolver->resolveAllForUser($arguments['connector_key'], $context->user);
                $connectionIds = $connections->pluck('id')->all();
            } else {
                // All connections of the user
                $connectionIds = UserConnectorConnection::query()
                    ->where('owner_user_id', $context->user->id)
                    ->where('status', 'active')
                    ->pluck('id')
                    ->all();
            }

            if (empty($connectionIds)) {
                return ToolResult::success(['phone_numbers' => [], 'total' => 0]);
            }

            $query = UserConnectorPhoneNumber::query()
                ->whereIn('connection_id', $connectionIds)
                ->with('connection.connector');

            if (!empty($arguments['capability'])) {
                $query->withCapability($arguments['capability']);
            }

            $numbers = $query->orderByDesc('is_default')->orderBy('number')->get();

            $result = $numbers->map(fn (UserConnectorPhoneNumber $phone) => array_filter([
                'id' => $phone->id,
                'connection_id' => $phone->connection_id,
                'connector' => $phone->connection?->connector?->key,
                'connection_name' => $phone->connection?->name,
                'number' => $phone->number,
                'label' => $phone->label,
                'type' => $phone->type,
                'capabilities' => $phone->capabilities,
                'is_default' => $phone->is_default,
                'external_id' => $phone->external_id,
                'phoneline' => $phone->meta['phonelineAlias'] ?? null,
                'assigned' => $phone->meta['assigned'] ?? null,
            ], fn ($v) => $v !== null))->all();

            return ToolResult::success([
                'phone_numbers' => $result,
                'total' => count($result),
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['telefonie', 'rufnummern', 'phone', 'numbers', 'sipgate', 'ringcentral', 'vodafone'],
            'read_only' => true,
            'requires_auth' => true,
            'risk_level' => 'safe',
            'cost_class' => 'database',
        ];
    }
}
