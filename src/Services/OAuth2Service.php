<?php

namespace Platform\UserConnectors\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Platform\UserConnectors\Models\UserConnector;
use Platform\UserConnectors\Models\UserConnectorConnection;
use Platform\UserConnectors\Models\UserConnectorOAuthApp;
use Platform\UserConnectors\Services\WebhookSubscriptionManager;

class OAuth2Service
{
    public function buildAuthorizeUrl(UserConnectorOAuthApp $app, string $state): string
    {
        $cfg = $app->getOAuthConfig();

        $authorizeUrl = $cfg['authorize_url'] ?? null;
        if (!$authorizeUrl) {
            throw new \RuntimeException("authorize_url fehlt für OAuth-App '{$app->name}'.");
        }

        if (empty($cfg['client_id'])) {
            throw new \RuntimeException("client_id fehlt für OAuth-App '{$app->name}'.");
        }

        $connectorKey = $app->connector->key;
        $scopes = $cfg['scopes'] ?? [];

        $params = [
            'response_type' => 'code',
            'client_id' => $cfg['client_id'],
            'redirect_uri' => $this->redirectUri($connectorKey),
            'scope' => is_array($scopes) ? implode(' ', $scopes) : (string) $scopes,
            'state' => $state,
        ];

        if (!empty($cfg['extra_params']) && is_array($cfg['extra_params'])) {
            $params = array_merge($params, $cfg['extra_params']);
        }

        return rtrim($authorizeUrl, '?') . '?' . http_build_query($params);
    }

    public function redirectUri(string $connectorKey): string
    {
        return route('user-connectors.oauth2.callback', ['connectorKey' => $connectorKey]);
    }

    /**
     * Exchange authorization code for tokens and persist in Connection.
     */
    public function handleCallback(Request $request, string $connectorKey): UserConnectorConnection
    {
        // Verify state
        $state = (string) $request->query('state', '');
        $expectedState = (string) $request->session()->pull('user-connectors.oauth2.state');

        if (!$state || !$expectedState || !hash_equals($expectedState, $state)) {
            throw new \RuntimeException('Ungültiger OAuth state.');
        }

        $code = (string) $request->query('code', '');
        if (!$code) {
            $err = (string) $request->query('error', 'OAuth error');
            throw new \RuntimeException('OAuth Callback ohne code: ' . $err);
        }

        $ownerUserId = (int) $request->session()->pull('user-connectors.oauth2.owner_user_id');
        if ($ownerUserId <= 0 && $request->user()) {
            $ownerUserId = $request->user()->id;
        }

        if ($ownerUserId <= 0) {
            throw new \RuntimeException('Owner-User-ID fehlt.');
        }

        // Resolve OAuth App from session
        $oauthAppId = (int) $request->session()->pull('user-connectors.oauth2.oauth_app_id', 0);
        if ($oauthAppId <= 0) {
            throw new \RuntimeException('OAuth-App-ID fehlt in der Session.');
        }

        $oauthApp = UserConnectorOAuthApp::with('connector')->find($oauthAppId);
        if (!$oauthApp || $oauthApp->connector->key !== $connectorKey) {
            throw new \RuntimeException("OAuth-App #{$oauthAppId} nicht gefunden oder passt nicht zu '{$connectorKey}'.");
        }

        $connector = $oauthApp->connector;
        $cfg = $oauthApp->getOAuthConfig();

        // Token exchange
        $tokenUrl = $cfg['token_url'] ?? null;
        if (!$tokenUrl) {
            throw new \RuntimeException("token_url fehlt für OAuth-App '{$oauthApp->name}'.");
        }

        $tokenParams = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUri($connectorKey),
        ];

        $useBasicAuth = ($cfg['token_auth_method'] ?? 'body') === 'basic';

        \Log::info('UserConnectors OAuth2 Token Exchange Request', [
            'connector_key' => $connectorKey,
            'token_url' => $tokenUrl,
            'auth_method' => $useBasicAuth ? 'basic' : 'body',
            'grant_type' => $tokenParams['grant_type'],
            'redirect_uri' => $tokenParams['redirect_uri'],
            'has_client_id' => !empty($cfg['client_id']),
            'has_client_secret' => !empty($cfg['client_secret']),
            'client_id_prefix' => substr($cfg['client_id'] ?? '', 0, 8) . '...',
        ]);

        if ($useBasicAuth) {
            // Send credentials as HTTP Basic Auth header (required by RingCentral)
            $resp = Http::asForm()
                ->withBasicAuth($cfg['client_id'], $cfg['client_secret'] ?? '')
                ->post($tokenUrl, $tokenParams);
        } else {
            // Send credentials in request body (default for most providers)
            $tokenParams['client_id'] = $cfg['client_id'];
            if (!empty($cfg['client_secret'])) {
                $tokenParams['client_secret'] = $cfg['client_secret'];
            }
            $resp = Http::asForm()->post($tokenUrl, $tokenParams);
        }

        if (!$resp->successful()) {
            \Log::error('UserConnectors OAuth2 Token Exchange Failed', [
                'connector_key' => $connectorKey,
                'oauth_app_id' => $oauthApp->id,
                'token_url' => $tokenUrl,
                'auth_method' => $useBasicAuth ? 'basic' : 'body',
                'status' => $resp->status(),
                'response_headers' => $resp->headers(),
                'body' => $resp->body(),
            ]);
            throw new \RuntimeException('Token Exchange fehlgeschlagen: ' . $resp->body());
        }

        $payload = $resp->json() ?? [];

        $expiresIn = isset($payload['expires_in']) ? (int) $payload['expires_in'] : null;
        $expiresAt = $expiresIn ? now()->addSeconds($expiresIn)->timestamp : null;

        // Reconnect or new connection
        $connectionId = (int) $request->session()->pull('user-connectors.oauth2.connection_id', 0);

        if ($connectionId > 0) {
            $connection = UserConnectorConnection::withTrashed()
                ->where('id', $connectionId)
                ->where('owner_user_id', $ownerUserId)
                ->first();

            if ($connection && $connection->trashed()) {
                $connection->restore();
            }

            if (!$connection) {
                throw new \RuntimeException("Connection #{$connectionId} nicht gefunden.");
            }
        } else {
            $isFirst = !UserConnectorConnection::query()
                ->where('connector_id', $connector->id)
                ->where('owner_user_id', $ownerUserId)
                ->exists();

            $connection = new UserConnectorConnection([
                'connector_id' => $connector->id,
                'oauth_app_id' => $oauthApp->id,
                'owner_user_id' => $ownerUserId,
                'name' => UserConnectorConnection::generateName($connector->id, $ownerUserId, $connector->name),
                'is_default' => $isFirst,
                'capabilities' => $connector->capabilities,
            ]);
        }

        // Always update oauth_app_id (also on reconnect)
        $connection->oauth_app_id = $oauthApp->id;

        $credentials = $connection->credentials ?? [];
        $credentials['oauth'] = array_merge($credentials['oauth'] ?? [], [
            'access_token' => $payload['access_token'] ?? null,
            'refresh_token' => $payload['refresh_token'] ?? ($credentials['oauth']['refresh_token'] ?? null),
            'token_type' => $payload['token_type'] ?? 'Bearer',
            'scope' => $payload['scope'] ?? null,
            'expires_in' => $expiresIn,
            'expires_at' => $expiresAt,
            'token_issued_at' => now()->timestamp,
        ]);

        $connection->auth_scheme = 'oauth2';
        $connection->status = 'active';
        $connection->last_error = null;
        $connection->credentials = $credentials;
        $connection->save();
        $connection->refresh();

        \Log::info('UserConnectors OAuth2 Connection Saved', [
            'connector_key' => $connectorKey,
            'oauth_app_id' => $oauthApp->id,
            'connection_id' => $connection->id,
        ]);

        // Copy default subscription settings into credentials if not yet present
        $credentials = $connection->credentials;
        if (empty($credentials['settings'])) {
            $defaults = config("user-connectors.{$connectorKey}.subscriptions", []);
            if ($defaults) {
                $credentials['settings'] = [
                    'subscriptions_enabled' => $defaults['enabled'] ?? true,
                    'subscription_resources' => $defaults['resources'] ?? $defaults['event_filters'] ?? [],
                ];
                $connection->credentials = $credentials;
                $connection->save();
            }
        }

        // Auto-create webhook subscriptions
        try {
            $manager = app(WebhookSubscriptionManager::class);
            if ($manager->getConnector($connectorKey)) {
                $manager->createSubscriptions($connection);
            }
        } catch (\Throwable $e) {
            \Log::warning('UserConnectors: Auto-create Subscriptions fehlgeschlagen', [
                'connector_key' => $connectorKey,
                'connection_id' => $connection->id,
                'error' => $e->getMessage(),
            ]);
        }

        // Post-connect profile sync
        $this->syncProfileAfterConnect($connection, $connectorKey);

        return $connection;
    }

    /**
     * Refresh tokens for a connection using its linked OAuth App.
     */
    public function refreshToken(UserConnectorConnection $connection): UserConnectorConnection
    {
        $oauthApp = $connection->oauthApp;
        if (!$oauthApp) {
            throw new \RuntimeException("Connection #{$connection->id} hat keine verknüpfte OAuth-App.");
        }

        $cfg = $oauthApp->getOAuthConfig();
        $oauth = $connection->credentials['oauth'] ?? [];
        $refreshToken = $oauth['refresh_token'] ?? null;

        if (!$refreshToken) {
            throw new \RuntimeException('Kein refresh_token vorhanden.');
        }

        $tokenUrl = $cfg['token_url'] ?? null;
        if (!$tokenUrl) {
            throw new \RuntimeException("token_url fehlt für OAuth-App '{$oauthApp->name}'.");
        }

        $useBasicAuth = ($cfg['token_auth_method'] ?? 'body') === 'basic';

        $refreshParams = [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ];

        if ($useBasicAuth) {
            $resp = Http::asForm()
                ->withBasicAuth($cfg['client_id'], $cfg['client_secret'] ?? '')
                ->post($tokenUrl, $refreshParams);
        } else {
            $refreshParams['client_id'] = $cfg['client_id'];
            $refreshParams['client_secret'] = $cfg['client_secret'] ?? null;
            $resp = Http::asForm()->post($tokenUrl, $refreshParams);
        }

        if (!$resp->successful()) {
            throw new \RuntimeException('Token Refresh fehlgeschlagen: ' . $resp->body());
        }

        $payload = $resp->json();
        $expiresIn = isset($payload['expires_in']) ? (int) $payload['expires_in'] : null;
        $expiresAt = $expiresIn ? now()->addSeconds($expiresIn)->timestamp : null;

        $credentials = $connection->credentials ?? [];
        $credentials['oauth'] = array_merge($credentials['oauth'] ?? [], [
            'access_token' => $payload['access_token'] ?? null,
            'refresh_token' => $payload['refresh_token'] ?? $refreshToken,
            'token_type' => $payload['token_type'] ?? ($credentials['oauth']['token_type'] ?? 'Bearer'),
            'scope' => $payload['scope'] ?? ($credentials['oauth']['scope'] ?? null),
            'expires_in' => $expiresIn,
            'expires_at' => $expiresAt,
            'token_issued_at' => now()->timestamp,
        ]);

        $connection->credentials = $credentials;
        $connection->status = 'active';
        $connection->last_error = null;
        $connection->save();

        return $connection;
    }

    /**
     * Run connector-specific profile sync after OAuth connect/reconnect.
     */
    protected function syncProfileAfterConnect(UserConnectorConnection $connection, string $connectorKey): void
    {
        try {
            match ($connectorKey) {
                'sipgate' => app(\Platform\UserConnectors\Services\Sipgate\SipgateConnectorService::class)->syncProfile($connection),
                'ringcentral' => app(\Platform\UserConnectors\Services\RingCentral\RingCentralConnectorService::class)->syncProfile($connection),
                'vodafone' => app(\Platform\UserConnectors\Services\Vodafone\VodafoneConnectorService::class)->syncProfile($connection),
                default => null,
            };
        } catch (\Throwable $e) {
            \Log::warning('UserConnectors: Post-connect profile sync fehlgeschlagen', [
                'connector_key' => $connectorKey,
                'connection_id' => $connection->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function newState(): string
    {
        return Str::random(32);
    }
}
