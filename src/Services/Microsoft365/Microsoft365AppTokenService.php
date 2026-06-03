<?php

namespace Platform\UserConnectors\Services\Microsoft365;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Platform\UserConnectors\Models\UserConnectorOAuthApp;

/**
 * Client Credentials Flow for app-level Graph API calls.
 *
 * Uses client_id, client_secret, and tenant_id from the OAuthApp settings (UI-configured).
 * Required for endpoints that need Application Permissions (e.g. CallRecords.Read.All).
 */
class Microsoft365AppTokenService
{
    /**
     * Get an app-level access token for the given OAuthApp.
     *
     * Uses cached token if still valid, otherwise requests a new one.
     */
    public function getAppToken(UserConnectorOAuthApp $oauthApp): ?string
    {
        $settings = $oauthApp->settings ?? [];
        $clientId = $settings['client_id'] ?? null;
        $clientSecret = $settings['client_secret'] ?? null;
        $tenantId = $settings['tenant_id'] ?? null;

        if (!$clientId || !$clientSecret || !$tenantId) {
            Log::warning('MS365 AppToken: Fehlende Credentials (client_id, client_secret oder tenant_id)', [
                'oauth_app_id' => $oauthApp->id,
                'has_client_id' => (bool) $clientId,
                'has_client_secret' => (bool) $clientSecret,
                'has_tenant_id' => (bool) $tenantId,
            ]);
            return null;
        }

        $cacheKey = "ms365_app_token:{$oauthApp->id}";
        $cached = Cache::get($cacheKey);

        if ($cached) {
            return $cached;
        }

        return $this->requestNewToken($oauthApp, $tenantId, $clientId, $clientSecret, $cacheKey);
    }

    protected function requestNewToken(
        UserConnectorOAuthApp $oauthApp,
        string $tenantId,
        string $clientId,
        string $clientSecret,
        string $cacheKey,
    ): ?string {
        $tokenUrl = "https://login.microsoftonline.com/{$tenantId}/oauth2/v2.0/token";

        try {
            $response = Http::asForm()
                ->timeout(15)
                ->post($tokenUrl, [
                    'grant_type' => 'client_credentials',
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                    'scope' => 'https://graph.microsoft.com/.default',
                ]);

            if (!$response->successful()) {
                Log::error('MS365 AppToken: Token-Request fehlgeschlagen', [
                    'oauth_app_id' => $oauthApp->id,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return null;
            }

            $data = $response->json();
            $accessToken = $data['access_token'] ?? null;
            $expiresIn = $data['expires_in'] ?? 3600;

            if ($accessToken) {
                // Cache with 5 minute buffer before expiry
                Cache::put($cacheKey, $accessToken, max(60, $expiresIn - 300));
            }

            Log::info('MS365 AppToken: Neuer Token erhalten', [
                'oauth_app_id' => $oauthApp->id,
                'expires_in' => $expiresIn,
            ]);

            return $accessToken;
        } catch (\Exception $e) {
            Log::error('MS365 AppToken: Exception', [
                'oauth_app_id' => $oauthApp->id,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
