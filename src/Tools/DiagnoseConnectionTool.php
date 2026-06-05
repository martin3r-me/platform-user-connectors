<?php

namespace Platform\UserConnectors\Tools;

use Illuminate\Support\Facades\Http;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\UserConnectors\Models\UserConnectorConnection;
use Platform\UserConnectors\Services\Microsoft365\Microsoft365AppTokenService;
use Platform\UserConnectors\Services\Microsoft365\Microsoft365ConnectorService;

class DiagnoseConnectionTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'user-connectors.connections.diagnose';
    }

    public function getDescription(): string
    {
        return 'Diagnose einer Connector-Verbindung. Prüft Token-Gültigkeit, Graph-API-Zugang, Subscription-Status, App-Permissions und Teams-Zugang. Kann auch Subscriptions erstellen (action: create_teams_subscription oder create_call_records_subscription).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'connection_id' => ['type' => 'integer', 'description' => 'Connection ID. Wenn nicht angegeben, wird die Default-MS365-Connection genutzt.'],
                'action' => ['type' => 'string', 'description' => 'Optional: "create_teams_subscription" oder "create_call_records_subscription" um Subscriptions manuell zu erstellen. Default: Diagnose-Report.'],
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
            $connectionId = $arguments['connection_id'] ?? null;
            $action = $arguments['action'] ?? 'diagnose';

            $query = UserConnectorConnection::query()
                ->where('owner_user_id', $context->user->id)
                ->with(['connector', 'oauthApp']);

            if ($connectionId) {
                $connection = $query->find($connectionId);
            } else {
                $connection = $query
                    ->whereHas('connector', fn ($q) => $q->where('key', 'microsoft365'))
                    ->where('is_default', true)
                    ->first();
            }

            if (!$connection) {
                return ToolResult::error('NOT_FOUND', 'Keine MS365-Verbindung gefunden.');
            }

            if ($action === 'create_teams_subscription') {
                return $this->createTeamsSubscription($connection);
            }

            if ($action === 'create_call_records_subscription') {
                return $this->createCallRecordsSubscription($connection);
            }

            return $this->diagnose($connection);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', $e->getMessage());
        }
    }

    protected function diagnose(UserConnectorConnection $connection): ToolResult
    {
        $report = [];
        $connectorKey = $connection->connector?->key;
        $credentials = $connection->credentials ?? [];

        // Basic info
        $report['connection'] = [
            'id' => $connection->id,
            'connector_key' => $connectorKey,
            'status' => $connection->status,
            'ms365_user_id' => $credentials['ms365_user_id'] ?? null,
            'profile_mail' => $credentials['profile']['mail'] ?? null,
            'last_error' => $connection->last_error,
        ];

        // Token status
        $oauth = $credentials['oauth'] ?? [];
        $expiresAt = $oauth['expires_at'] ?? null;
        $hasAccessToken = !empty($oauth['access_token']);
        $hasRefreshToken = !empty($oauth['refresh_token']);
        $tokenExpired = $expiresAt ? (now()->timestamp >= $expiresAt) : null;
        $tokenExpiresIn = $expiresAt ? ($expiresAt - now()->timestamp) : null;

        $report['token'] = [
            'has_access_token' => $hasAccessToken,
            'has_refresh_token' => $hasRefreshToken,
            'expired' => $tokenExpired,
            'expires_in_seconds' => $tokenExpiresIn,
            'scope' => $oauth['scope'] ?? null,
        ];

        // Subscriptions
        $subs = $credentials['subscriptions'] ?? [];
        $report['subscriptions'] = collect($subs)->map(fn ($s) => [
            'resource' => $s['resource'] ?? null,
            'change_types' => $s['change_types'] ?? null,
            'app_level' => $s['app_level'] ?? false,
            'shared_mailbox' => $s['shared_mailbox'] ?? null,
            'expires_at' => isset($s['expires_at']) ? date('Y-m-d H:i:s', $s['expires_at']) : null,
            'expired' => isset($s['expires_at']) ? (now()->timestamp >= $s['expires_at']) : null,
        ])->all();

        $report['has_teams_subscription'] = collect($subs)->contains(fn ($s) =>
            str_contains(strtolower($s['resource'] ?? ''), 'chat')
        );

        $report['has_call_records_subscription'] = collect($subs)->contains(fn ($s) =>
            str_contains(strtolower($s['resource'] ?? ''), 'callrecords')
        );

        // OAuthApp settings
        $oauthApp = $connection->oauthApp;
        $report['oauth_app'] = [
            'id' => $oauthApp?->id,
            'name' => $oauthApp?->name,
            'has_tenant_id' => !empty($oauthApp?->settings['tenant_id']),
            'tenant_id' => $oauthApp?->settings['tenant_id'] ?? null,
        ];

        // Test Graph API access
        if ($connectorKey === 'microsoft365' && $hasAccessToken) {
            $connectorService = app(Microsoft365ConnectorService::class);
            $token = $connectorService->getValidAccessToken($connection);

            if ($token) {
                $baseUrl = config('user-connectors.microsoft365.graph_base_url', 'https://graph.microsoft.com/v1.0');

                // Test /me
                $meResponse = Http::withToken($token)->timeout(10)->get("{$baseUrl}/me", [
                    '$select' => 'id,displayName,mail',
                ]);
                $report['graph_me'] = [
                    'status' => $meResponse->status(),
                    'ok' => $meResponse->successful(),
                    'data' => $meResponse->successful() ? $meResponse->json() : mb_substr($meResponse->body(), 0, 300),
                ];

                // Test /me/chats (delegated Teams access)
                $chatsResponse = Http::withToken($token)->timeout(10)->get("{$baseUrl}/me/chats", [
                    '$top' => 1,
                ]);
                $report['graph_chats'] = [
                    'status' => $chatsResponse->status(),
                    'ok' => $chatsResponse->successful(),
                    'data' => $chatsResponse->successful()
                        ? ['count' => count($chatsResponse->json('value') ?? [])]
                        : mb_substr($chatsResponse->body(), 0, 300),
                ];

                // Test app token
                if (!empty($oauthApp?->settings['tenant_id'])) {
                    $appToken = app(Microsoft365AppTokenService::class)->getAppToken($oauthApp);
                    $report['app_token'] = [
                        'available' => !empty($appToken),
                    ];

                    if ($appToken) {
                        // List existing subscriptions via Graph
                        $subsResponse = Http::withToken($appToken)->timeout(10)->get("{$baseUrl}/subscriptions");
                        $report['graph_subscriptions'] = [
                            'status' => $subsResponse->status(),
                            'ok' => $subsResponse->successful(),
                            'data' => $subsResponse->successful()
                                ? collect($subsResponse->json('value') ?? [])->map(fn ($s) => [
                                    'id' => $s['id'] ?? null,
                                    'resource' => $s['resource'] ?? null,
                                    'changeType' => $s['changeType'] ?? null,
                                    'expirationDateTime' => $s['expirationDateTime'] ?? null,
                                ])->all()
                                : mb_substr($subsResponse->body(), 0, 300),
                        ];
                    }
                }
            } else {
                $report['graph_me'] = ['error' => 'Kein gültiger Token verfügbar'];
            }
        }

        // Config check
        $report['config'] = [
            'resources' => config('user-connectors.microsoft365.subscriptions.resources', []),
            'app_resources' => config('user-connectors.microsoft365.subscriptions.app_resources', []),
            'call_records' => config('user-connectors.microsoft365.call_records', []),
        ];

        return ToolResult::success($report);
    }

    protected function createTeamsSubscription(UserConnectorConnection $connection): ToolResult
    {
        $oauthApp = $connection->oauthApp;
        if (!$oauthApp || empty($oauthApp->settings['tenant_id'])) {
            return ToolResult::error('CONFIG_ERROR', 'OAuthApp hat keine tenant_id konfiguriert. Benötigt für App-level Subscriptions.');
        }

        $appToken = app(Microsoft365AppTokenService::class)->getAppToken($oauthApp);
        if (!$appToken) {
            return ToolResult::error('TOKEN_ERROR', 'Kein App-Token verfügbar. Prüfe client_id/client_secret/tenant_id.');
        }

        $baseUrl = config('user-connectors.microsoft365.graph_base_url', 'https://graph.microsoft.com/v1.0');
        $webhookUrl = route('user-connectors.webhooks.microsoft365');

        $appResources = config('user-connectors.microsoft365.subscriptions.app_resources', []);
        if (empty($appResources)) {
            return ToolResult::error('CONFIG_ERROR', 'Keine app_resources in Config definiert.');
        }

        $results = [];

        foreach ($appResources as $res) {
            $clientState = \Illuminate\Support\Str::random(40);

            $payload = [
                'changeType' => $res['changeType'],
                'notificationUrl' => $webhookUrl,
                'resource' => $res['resource'],
                'expirationDateTime' => now()->addHour()->toIso8601String(),
                'clientState' => $clientState,
            ];

            $response = Http::withToken($appToken)
                ->timeout(30)
                ->post("{$baseUrl}/subscriptions", $payload);

            $result = [
                'resource' => $res['resource'],
                'request_payload' => $payload,
                'status' => $response->status(),
                'ok' => $response->successful(),
                'response' => $response->successful()
                    ? $response->json()
                    : json_decode($response->body(), true) ?? $response->body(),
            ];

            if ($response->successful()) {
                // Persist to connection credentials
                $credentials = $connection->credentials;
                $subs = $credentials['subscriptions'] ?? [];
                $subs[] = [
                    'id' => $response->json('id'),
                    'resource' => $res['resource'],
                    'change_types' => $res['changeType'],
                    'expires_at' => \Carbon\Carbon::parse($response->json('expirationDateTime'))->timestamp,
                    'client_state' => $clientState,
                    'app_level' => true,
                ];
                $credentials['subscriptions'] = $subs;
                $connection->credentials = $credentials;
                $connection->save();

                $result['saved'] = true;
            }

            $results[] = $result;
        }

        return ToolResult::success([
            'connection_id' => $connection->id,
            'results' => $results,
        ]);
    }

    protected function createCallRecordsSubscription(UserConnectorConnection $connection): ToolResult
    {
        $oauthApp = $connection->oauthApp;
        if (!$oauthApp || empty($oauthApp->settings['tenant_id'])) {
            return ToolResult::error('CONFIG_ERROR', 'OAuthApp hat keine tenant_id konfiguriert. Benötigt für CallRecords Subscriptions.');
        }

        $appToken = app(Microsoft365AppTokenService::class)->getAppToken($oauthApp);
        if (!$appToken) {
            return ToolResult::error('TOKEN_ERROR', 'Kein App-Token verfügbar. Prüfe client_id/client_secret/tenant_id.');
        }

        $callRecordResources = config('user-connectors.microsoft365.call_records.resources', []);
        if (empty($callRecordResources)) {
            return ToolResult::error('CONFIG_ERROR', 'Keine call_records.resources in Config definiert.');
        }

        $baseUrl = config('user-connectors.microsoft365.graph_base_url', 'https://graph.microsoft.com/v1.0');
        $webhookUrl = route('user-connectors.webhooks.microsoft365.call-records');

        $results = [];

        foreach ($callRecordResources as $res) {
            $clientState = \Illuminate\Support\Str::random(40);

            $payload = [
                'changeType' => $res['changeType'],
                'notificationUrl' => $webhookUrl,
                'resource' => $res['resource'],
                'expirationDateTime' => now()->addHour()->toIso8601String(),
                'clientState' => $clientState,
            ];

            $response = Http::withToken($appToken)
                ->timeout(30)
                ->post("{$baseUrl}/subscriptions", $payload);

            $result = [
                'resource' => $res['resource'],
                'request_payload' => $payload,
                'status' => $response->status(),
                'ok' => $response->successful(),
                'response' => $response->successful()
                    ? $response->json()
                    : json_decode($response->body(), true) ?? $response->body(),
            ];

            if ($response->successful()) {
                // Persist to connection credentials
                $credentials = $connection->credentials;
                $subs = $credentials['subscriptions'] ?? [];
                $subs[] = [
                    'id' => $response->json('id'),
                    'resource' => $res['resource'],
                    'change_types' => $res['changeType'],
                    'expires_at' => \Carbon\Carbon::parse($response->json('expirationDateTime'))->timestamp,
                    'client_state' => $clientState,
                    'app_level' => true,
                ];
                $credentials['subscriptions'] = $subs;
                $connection->credentials = $credentials;
                $connection->save();

                $result['saved'] = true;
            }

            $results[] = $result;
        }

        return ToolResult::success([
            'connection_id' => $connection->id,
            'results' => $results,
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'diagnostic',
            'tags' => ['user-connectors', 'microsoft365', 'teams', 'subscriptions', 'diagnose', 'debug'],
            'read_only' => false,
            'requires_auth' => true,
            'risk_level' => 'safe',
            'cost_class' => 'local_db',
        ];
    }
}
