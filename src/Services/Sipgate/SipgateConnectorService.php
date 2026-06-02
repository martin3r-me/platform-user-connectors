<?php

namespace Platform\UserConnectors\Services\Sipgate;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Platform\Core\Models\User;
use Platform\UserConnectors\Models\UserConnectorConnection;
use Platform\UserConnectors\Services\ConnectionResolver;
use Platform\UserConnectors\Services\OAuth2Service;

class SipgateConnectorService
{
    public function __construct(
        protected ConnectionResolver $resolver,
    ) {}

    public function getConnectionForUser(User $user): ?UserConnectorConnection
    {
        return $this->resolver->resolveForUser('sipgate', $user);
    }

    public function getAccessToken(UserConnectorConnection $connection): ?string
    {
        return $connection->credentials['oauth']['access_token'] ?? null;
    }

    public function isTokenExpired(UserConnectorConnection $connection): bool
    {
        $expiresAt = $connection->credentials['oauth']['expires_at'] ?? null;
        if (!$expiresAt) {
            return false;
        }

        return now()->timestamp >= ($expiresAt - 300);
    }

    public function getValidAccessToken(UserConnectorConnection $connection): ?string
    {
        if ($this->isTokenExpired($connection)) {
            $newToken = $this->refreshToken($connection);
            if ($newToken) {
                return $newToken;
            }
        }

        return $this->getAccessToken($connection);
    }

    public function refreshToken(UserConnectorConnection $connection): ?string
    {
        $refreshToken = $connection->credentials['oauth']['refresh_token'] ?? null;
        if (!$refreshToken) {
            Log::warning('Sipgate UC: Kein Refresh-Token', ['connection_id' => $connection->id]);
            return null;
        }

        try {
            $oauth2 = app(OAuth2Service::class);
            $connection = $oauth2->refreshToken($connection);

            Log::info('Sipgate UC: Token refreshed', ['connection_id' => $connection->id]);

            return $this->getAccessToken($connection);
        } catch (\Exception $e) {
            Log::error('Sipgate UC: Token-Refresh fehlgeschlagen', [
                'connection_id' => $connection->id,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Fetch profile data (user info, numbers, devices) and store in credentials.profile.
     */
    public function syncProfile(UserConnectorConnection $connection): void
    {
        $token = $this->getValidAccessToken($connection);
        if (!$token) {
            Log::warning('Sipgate syncProfile: Kein gültiger Token', ['connection_id' => $connection->id]);
            return;
        }

        $baseUrl = config('user-connectors.sipgate.api_base_url', 'https://api.sipgate.com/v2');

        try {
            $http = Http::withToken($token)->timeout(10)->withHeaders(['Accept' => 'application/json']);

            $userInfo = $http->get($baseUrl . '/authorization/userinfo')->json() ?? [];
            $numbers = $http->get($baseUrl . '/numbers')->json() ?? [];
            $devices = $http->get($baseUrl . '/devices')->json() ?? [];
            $smsExtensions = $http->get($baseUrl . '/sms')->json() ?? [];

            $credentials = $connection->credentials ?? [];

            // Store sipgate_sub for webhook resolution
            if (!empty($userInfo['sub'])) {
                $credentials['oauth']['sipgate_sub'] = $userInfo['sub'];
            }

            $credentials['profile'] = [
                'user_info' => $userInfo,
                'numbers' => $numbers['items'] ?? $numbers,
                'devices' => $devices['items'] ?? $devices,
                'sms_extensions' => $smsExtensions['items'] ?? $smsExtensions,
                'synced_at' => now()->toIso8601String(),
            ];

            $connection->credentials = $credentials;
            $connection->save();

            Log::info('Sipgate syncProfile: OK', [
                'connection_id' => $connection->id,
                'numbers_count' => count($credentials['profile']['numbers']),
                'devices_count' => count($credentials['profile']['devices']),
            ]);
        } catch (\Exception $e) {
            Log::warning('Sipgate syncProfile fehlgeschlagen', [
                'connection_id' => $connection->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function testConnection(UserConnectorConnection $connection): array
    {
        $token = $this->getValidAccessToken($connection);
        if (!$token) {
            return ['success' => false, 'message' => 'Kein gültiger Access-Token.'];
        }

        try {
            $baseUrl = config('user-connectors.sipgate.api_base_url', 'https://api.sipgate.com/v2');
            $response = \Illuminate\Support\Facades\Http::withToken($token)
                ->timeout(10)
                ->get($baseUrl . '/authorization/userinfo');

            if ($response->successful()) {
                $data = $response->json();

                // Store sipgate_sub for webhook connection resolution
                $credentials = $connection->credentials ?? [];
                if (!empty($data['sub'])) {
                    $credentials['oauth']['sipgate_sub'] = $data['sub'];
                    $connection->credentials = $credentials;
                }

                $connection->status = 'active';
                $connection->last_error = null;
                $connection->last_tested_at = now();
                $connection->save();

                $displayName = $data['sub'] ?? $data['locale'] ?? 'OK';

                // Sync profile data on successful test
                $this->syncProfile($connection);

                return ['success' => true, 'message' => "Verbindung erfolgreich. Account: {$displayName}"];
            }

            $connection->status = 'error';
            $connection->last_error = 'HTTP ' . $response->status();
            $connection->last_tested_at = now();
            $connection->save();

            return ['success' => false, 'message' => 'API-Fehler: HTTP ' . $response->status()];
        } catch (\Exception $e) {
            $connection->status = 'error';
            $connection->last_error = $e->getMessage();
            $connection->last_tested_at = now();
            $connection->save();

            return ['success' => false, 'message' => 'Fehler: ' . $e->getMessage()];
        }
    }
}
