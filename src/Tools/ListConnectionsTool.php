<?php

namespace Platform\UserConnectors\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolResult;
use Platform\UserConnectors\Models\UserConnectorConnection;

class ListConnectionsTool extends ConnectorTool
{
    public function getName(): string
    {
        return 'user-connectors.connections.list';
    }

    public function getDescription(): string
    {
        return 'Listet die Connector-Verbindungen des Users — eigene und mit ihm geteilte (Microsoft 365, Sipgate, RingCentral, Vodafone) — '
            . 'mit Status, Konto und letztem Fehler. Die id ist die connection_id für alle anderen user-connectors-Tools.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'connector_key' => ['type' => 'string', 'enum' => ['microsoft365', 'sipgate', 'ringcentral', 'vodafone', 'plaud']],
                'status' => ['type' => 'string', 'enum' => ['active', 'error', 'pending']],
                'include_shared' => ['type' => 'boolean', 'description' => 'Default true.'],
                'include_subscriptions' => ['type' => 'boolean', 'description' => 'Webhook-Subscriptions im Detail (nur eigene Verbindungen). Default false.'],
            ],
        ];
    }

    protected function handle(array $arguments, ToolContext $context): ToolResult
    {
        $user = $context->user;
        $connections = $this->accessibleConnections($user, !empty($arguments['connector_key']) ? [(string) $arguments['connector_key']] : []);
        $connections->loadMissing(['oauthApp', 'ownerUser']);

        if (($this->optionalBool($arguments, 'include_shared') ?? true) === false) {
            $connections = $connections->filter(fn ($c) => (int) $c->owner_user_id === (int) $user->id);
        }
        if (!empty($arguments['status'])) {
            $connections = $connections->where('status', (string) $arguments['status']);
        }
        $withSubscriptions = (bool) $this->optionalBool($arguments, 'include_subscriptions');

        $result = $connections->map(function (UserConnectorConnection $conn) use ($user, $withSubscriptions) {
            $own = (int) $conn->owner_user_id === (int) $user->id;
            $credentials = $own ? ($conn->credentials ?? []) : [];
            $profile = $credentials['profile'] ?? [];
            $subscriptions = $credentials['subscriptions'] ?? [];

            return array_filter([
                'id' => $conn->id,
                'connector_key' => $conn->connector?->key,
                'name' => $conn->name,
                'status' => $conn->status,
                'is_default' => (bool) $conn->is_default,
                'shared' => !$own,
                'owner' => $own ? null : $conn->ownerUser?->name,
                'account' => $profile['mail'] ?? $profile['displayName'] ?? null,
                'oauth_app' => $own ? $conn->oauthApp?->name : null,
                'ms365_user_id' => $credentials['ms365_user_id'] ?? null,
                'shared_mailboxes' => ($credentials['shared_mailboxes'] ?? []) ?: null,
                'subscriptions_count' => $own ? count($subscriptions) : null,
                'subscriptions' => $own && $withSubscriptions ? array_map(fn ($s) => [
                    'resource' => $s['resource'] ?? null,
                    'change_types' => $s['change_types'] ?? null,
                    'shared_mailbox' => $s['shared_mailbox'] ?? null,
                    'expires_at' => isset($s['expires_at']) ? date('Y-m-d H:i:s', $s['expires_at']) : null,
                ], $subscriptions) : null,
                'last_tested_at' => $conn->last_tested_at?->toIso8601String(),
                'last_error' => $own ? $conn->last_error : null,
            ], fn ($v) => $v !== null);
        })->values()->all();

        return ToolResult::success(['connections' => $result, 'total' => count($result)]);
    }

    public function getMetadata(): array
    {
        return self::readMetadata(['connections', 'status', 'subscriptions', 'microsoft365', 'sipgate', 'ringcentral', 'vodafone']);
    }
}
