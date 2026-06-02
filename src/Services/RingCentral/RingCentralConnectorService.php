<?php

namespace Platform\UserConnectors\Services\RingCentral;

use Illuminate\Support\Facades\Log;
use Platform\Core\Models\User;
use Platform\UserConnectors\Models\UserConnectorConnection;
use Platform\UserConnectors\Services\ConnectionResolver;
use Platform\UserConnectors\Services\OAuth2Service;

class RingCentralConnectorService
{
    public function __construct(
        protected ConnectionResolver $resolver,
    ) {}

    public function getConnectionForUser(User $user): ?UserConnectorConnection
    {
        return $this->resolver->resolveForUser('ringcentral', $user);
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
            Log::warning('RingCentral UC: Kein Refresh-Token', ['connection_id' => $connection->id]);
            return null;
        }

        try {
            $oauth2 = app(OAuth2Service::class);
            $connection = $oauth2->refreshToken('ringcentral', $connection);

            Log::info('RingCentral UC: Token refreshed', ['connection_id' => $connection->id]);

            return $this->getAccessToken($connection);
        } catch (\Exception $e) {
            Log::error('RingCentral UC: Token-Refresh fehlgeschlagen', [
                'connection_id' => $connection->id,
                'error' => $e->getMessage(),
            ]);
            return null;
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
            $baseUrl = config('user-connectors.ringcentral.api_base_url', 'https://platform.ringcentral.com/restapi/v1.0');
            $response = \Illuminate\Support\Facades\Http::withToken($token)
                ->timeout(10)
                ->get($baseUrl . '/account/~');

            if ($response->successful()) {
                $data = $response->json();

                $connection->status = 'active';
                $connection->last_error = null;
                $connection->last_tested_at = now();
                $connection->save();

                return [
                    'success' => true,
                    'message' => 'Verbindung erfolgreich. Account: ' . ($data['mainNumber'] ?? $data['id'] ?? 'OK'),
                ];
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
