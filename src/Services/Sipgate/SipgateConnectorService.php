<?php

namespace Platform\UserConnectors\Services\Sipgate;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Platform\Core\Models\User;
use Platform\UserConnectors\Models\UserConnectorConnection;
use Platform\UserConnectors\Models\UserConnectorDevice;
use Platform\UserConnectors\Models\UserConnectorPhoneNumber;
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

            // Resolve webuserId (e.g. "w0") for user-scoped endpoints
            $webuserId = $userInfo['sub'] ?? $connection->credentials['oauth']['sipgate_sub'] ?? null;

            $numbers = $http->get($baseUrl . '/numbers')->json() ?? [];
            $devices = $webuserId ? ($http->get($baseUrl . '/' . $webuserId . '/devices')->json() ?? []) : [];
            $phonelines = $webuserId ? ($http->get($baseUrl . '/' . $webuserId . '/phonelines')->json() ?? []) : [];
            $smsExtensions = $webuserId ? ($http->get($baseUrl . '/' . $webuserId . '/sms')->json() ?? []) : [];

            $credentials = $connection->credentials ?? [];

            // Store sipgate_sub for webhook resolution
            if (!empty($userInfo['sub'])) {
                $credentials['oauth']['sipgate_sub'] = $userInfo['sub'];
            }

            $numberItems = $numbers['items'] ?? $numbers;
            $deviceItems = $devices['items'] ?? $devices;
            $phonelineItems = $phonelines['items'] ?? $phonelines;
            $smsItems = $smsExtensions['items'] ?? $smsExtensions;

            $credentials['profile'] = [
                'user_info' => $userInfo,
                'numbers' => $numberItems,
                'devices' => $deviceItems,
                'phonelines' => $phonelineItems,
                'sms_extensions' => $smsItems,
                'synced_at' => now()->toIso8601String(),
            ];

            $connection->credentials = $credentials;
            $connection->save();

            // Sync to structured tables
            $this->syncPhoneNumbersToTable($connection, $numberItems, $phonelineItems, $smsItems);
            $this->syncDevicesToTable($connection, $deviceItems);

            Log::info('Sipgate syncProfile: OK', [
                'connection_id' => $connection->id,
                'numbers_count' => count($numberItems),
                'devices_count' => count($deviceItems),
            ]);
        } catch (\Exception $e) {
            Log::warning('Sipgate syncProfile fehlgeschlagen', [
                'connection_id' => $connection->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function syncPhoneNumbersToTable(UserConnectorConnection $connection, array $numbers, array $phonelines, array $smsExtensions): void
    {
        $smsCapableIds = collect($smsExtensions)->pluck('id')->filter()->all();

        // Build phoneline lookup: id → alias
        $phonelineMap = collect($phonelines)->keyBy('id')->map(fn ($pl) => $pl['alias'] ?? $pl['id'])->all();
        // User's phoneline IDs
        $userPhonelineIds = array_keys($phonelineMap);

        $syncedIds = [];

        foreach ($numbers as $item) {
            $number = $item['number'] ?? null;
            $endpointId = $item['endpointId'] ?? null;
            if (!$number) {
                continue;
            }

            // Skip numbers not assigned to any of the user's phonelines
            $isAssigned = !empty($endpointId) && in_array($endpointId, $userPhonelineIds, true);
            if (!$isAssigned) {
                continue;
            }

            $capabilities = ['voice'];
            if (in_array($endpointId, $smsCapableIds, true)) {
                $capabilities[] = 'sms';
            }
            if (!empty($item['type']) && strtolower($item['type']) === 'fax') {
                $capabilities = ['fax'];
            }

            $type = !empty($item['type']) ? strtolower($item['type']) : 'voice';

            $phonelineAlias = $phonelineMap[$endpointId] ?? null;
            $localized = $item['localized'] ?? null;
            $label = $phonelineAlias
                ? $phonelineAlias . ($localized ? ' · ' . $localized : '')
                : $localized;

            $record = UserConnectorPhoneNumber::updateOrCreate(
                [
                    'connection_id' => $connection->id,
                    'number' => $number,
                ],
                [
                    'label' => $label,
                    'type' => $type,
                    'capabilities' => $capabilities,
                    'external_id' => $endpointId ?: null,
                    'meta' => array_filter([
                        'endpointId' => $endpointId,
                        'phonelineAlias' => $phonelineAlias,
                        'localized' => $localized,
                    ]),
                ]
            );

            $syncedIds[] = $record->id;
        }

        // Remove numbers no longer returned by provider
        UserConnectorPhoneNumber::where('connection_id', $connection->id)
            ->whereNotIn('id', $syncedIds)
            ->delete();
    }

    protected function syncDevicesToTable(UserConnectorConnection $connection, array $devices): void
    {
        $syncedIds = [];

        foreach ($devices as $item) {
            $deviceId = $item['id'] ?? null;
            if (!$deviceId) {
                continue;
            }

            $type = match (true) {
                str_contains(strtolower($item['type'] ?? ''), 'register') => 'softphone',
                str_contains(strtolower($item['type'] ?? ''), 'mobile') => 'mobile',
                str_contains(strtolower($item['type'] ?? ''), 'external') => 'deskphone',
                default => strtolower($item['type'] ?? 'softphone'),
            };

            $record = UserConnectorDevice::updateOrCreate(
                [
                    'connection_id' => $connection->id,
                    'external_id' => $deviceId,
                ],
                [
                    'name' => $item['alias'] ?? $item['type'] ?? $deviceId,
                    'type' => $type,
                    'is_online' => $item['online'] ?? null,
                    'meta' => array_filter([
                        'dpiName' => $item['dpiName'] ?? null,
                        'activePhonelines' => $item['activePhonelines'] ?? null,
                        'emergencyAddressId' => $item['emergencyAddressId'] ?? null,
                    ]),
                ]
            );

            $syncedIds[] = $record->id;
        }

        // Remove devices no longer returned by provider
        UserConnectorDevice::where('connection_id', $connection->id)
            ->whereNotIn('id', $syncedIds)
            ->delete();
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
