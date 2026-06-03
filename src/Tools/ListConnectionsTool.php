<?php

namespace Platform\UserConnectors\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\UserConnectors\Models\UserConnectorConnection;

class ListConnectionsTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'user-connectors.connections.list';
    }

    public function getDescription(): string
    {
        return 'Listet alle Connector-Verbindungen des Users auf. Zeigt Status, Connector-Typ, OAuth-App, ms365_user_id, Subscription-Anzahl, Shared Mailboxes und letzte Test-Ergebnisse. Filterbar nach Connector-Key und Status.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'connector_key' => ['type' => 'string', 'description' => 'Filter nach Connector: microsoft365, sipgate, ringcentral, vodafone.'],
                'status' => ['type' => 'string', 'description' => 'Filter nach Status: active, error, pending.'],
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
            $query = UserConnectorConnection::query()
                ->where('owner_user_id', $context->user->id)
                ->with(['connector', 'oauthApp']);

            if (!empty($arguments['connector_key'])) {
                $query->whereHas('connector', fn ($q) => $q->where('key', $arguments['connector_key']));
            }

            if (!empty($arguments['status'])) {
                $query->where('status', $arguments['status']);
            }

            $connections = $query->orderByDesc('is_default')->orderBy('created_at')->get();

            $result = $connections->map(function (UserConnectorConnection $conn) {
                $credentials = $conn->credentials;
                $subscriptions = $credentials['subscriptions'] ?? [];
                $sharedMailboxes = $credentials['shared_mailboxes'] ?? [];
                $profile = $credentials['profile'] ?? [];

                return [
                    'id' => $conn->id,
                    'connector_key' => $conn->connector?->key,
                    'connector_name' => $conn->connector?->name,
                    'oauth_app' => $conn->oauthApp?->name,
                    'name' => $conn->name,
                    'status' => $conn->status,
                    'is_default' => $conn->is_default,
                    'ms365_user_id' => $credentials['ms365_user_id'] ?? null,
                    'profile' => $profile ? [
                        'displayName' => $profile['displayName'] ?? null,
                        'mail' => $profile['mail'] ?? null,
                        'synced_at' => $profile['synced_at'] ?? null,
                    ] : null,
                    'subscriptions_count' => count($subscriptions),
                    'subscriptions' => collect($subscriptions)->map(fn ($s) => [
                        'resource' => $s['resource'] ?? null,
                        'change_types' => $s['change_types'] ?? null,
                        'shared_mailbox' => $s['shared_mailbox'] ?? null,
                        'expires_at' => isset($s['expires_at']) ? date('Y-m-d H:i:s', $s['expires_at']) : null,
                    ])->all(),
                    'shared_mailboxes' => $sharedMailboxes,
                    'last_tested_at' => $conn->last_tested_at?->toIso8601String(),
                    'last_error' => $conn->last_error,
                    'created_at' => $conn->created_at->toIso8601String(),
                ];
            })->all();

            return ToolResult::success([
                'connections' => $result,
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
            'tags' => ['user-connectors', 'connections', 'status', 'subscriptions', 'microsoft365', 'sipgate', 'ringcentral', 'vodafone'],
            'read_only' => true,
            'requires_auth' => true,
            'risk_level' => 'safe',
            'cost_class' => 'database',
        ];
    }
}
