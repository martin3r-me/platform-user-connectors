<?php

namespace Platform\UserConnectors\Services\RingCentral;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Platform\Core\Models\User;
use Platform\UserConnectors\Models\UserConnectorConnection;
use Platform\UserConnectors\Models\UserConnectorDevice;
use Platform\UserConnectors\Models\UserConnectorPhoneNumber;
use Platform\UserConnectors\Services\ConnectionResolver;
use Platform\UserConnectors\Services\OAuth2Service;

class RingCentralConnectorService
{
    protected string $connectorKey = 'ringcentral';

    public function __construct(
        protected ConnectionResolver $resolver,
    ) {}

    public function getConnectorKey(): string
    {
        return $this->connectorKey;
    }

    public function getConnectionForUser(User $user): ?UserConnectorConnection
    {
        return $this->resolver->resolveForUser($this->connectorKey, $user);
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
            $connection = $oauth2->refreshToken($connection);

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
     * Fetch profile data (phone numbers, devices) and store in credentials.profile + structured tables.
     */
    public function syncProfile(UserConnectorConnection $connection): void
    {
        $token = $this->getValidAccessToken($connection);
        if (!$token) {
            Log::warning("{$this->connectorKey} syncProfile: Kein gültiger Token", ['connection_id' => $connection->id]);
            return;
        }

        $baseUrl = config("user-connectors.{$this->connectorKey}.api_base_url", 'https://platform.ringcentral.com/restapi/v1.0');

        try {
            $http = Http::withToken($token)->timeout(10)->withHeaders(['Accept' => 'application/json']);

            $extensionInfo = $http->get($baseUrl . '/account/~/extension/~')->json() ?? [];
            $phoneNumbers = $http->get($baseUrl . '/account/~/extension/~/phone-number')->json() ?? [];
            $devices = $http->get($baseUrl . '/account/~/extension/~/device')->json() ?? [];

            $numberRecords = $phoneNumbers['records'] ?? [];
            $deviceRecords = $devices['records'] ?? [];

            $credentials = $connection->credentials ?? [];
            $credentials['profile'] = [
                'user_info' => $extensionInfo,
                'numbers' => $numberRecords,
                'devices' => $deviceRecords,
                'synced_at' => now()->toIso8601String(),
            ];

            $connection->credentials = $credentials;
            $connection->save();

            // Sync to structured tables
            $this->syncPhoneNumbersToTable($connection, $numberRecords);
            $this->syncDevicesToTable($connection, $deviceRecords);

            Log::info("{$this->connectorKey} syncProfile: OK", [
                'connection_id' => $connection->id,
                'numbers_count' => count($numberRecords),
                'devices_count' => count($deviceRecords),
            ]);
        } catch (\Exception $e) {
            Log::warning("{$this->connectorKey} syncProfile fehlgeschlagen", [
                'connection_id' => $connection->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function syncPhoneNumbersToTable(UserConnectorConnection $connection, array $numbers): void
    {
        $syncedIds = [];

        foreach ($numbers as $item) {
            $number = $item['phoneNumber'] ?? null;
            if (!$number) {
                continue;
            }

            $features = array_map('strtolower', $item['features'] ?? []);
            $capabilities = [];
            if (in_array('callermanagement', $features, true) || in_array('callerid', $features, true)) {
                $capabilities[] = 'voice';
            }
            if (in_array('smssending', $features, true) || in_array('smsreceiving', $features, true)) {
                $capabilities[] = 'sms';
            }
            if (in_array('faxsending', $features, true) || in_array('faxreceiving', $features, true)) {
                $capabilities[] = 'fax';
            }
            if (empty($capabilities)) {
                $capabilities[] = 'voice';
            }

            $usageType = strtolower($item['usageType'] ?? 'directnumber');
            $type = match (true) {
                str_contains($usageType, 'fax') => 'fax',
                in_array('sms', $capabilities) && !in_array('voice', $capabilities) => 'sms',
                default => 'voice',
            };

            $record = UserConnectorPhoneNumber::updateOrCreate(
                [
                    'connection_id' => $connection->id,
                    'number' => $number,
                ],
                [
                    'label' => $item['label'] ?? $item['usageType'] ?? null,
                    'type' => $type,
                    'capabilities' => $capabilities,
                    'external_id' => (string) ($item['id'] ?? ''),
                    'meta' => array_filter([
                        'usageType' => $item['usageType'] ?? null,
                        'paymentType' => $item['paymentType'] ?? null,
                        'type' => $item['type'] ?? null,
                        'features' => $item['features'] ?? null,
                    ]),
                ]
            );

            $syncedIds[] = $record->id;
        }

        UserConnectorPhoneNumber::where('connection_id', $connection->id)
            ->whereNotIn('id', $syncedIds)
            ->delete();
    }

    protected function syncDevicesToTable(UserConnectorConnection $connection, array $devices): void
    {
        $syncedIds = [];

        foreach ($devices as $item) {
            $deviceId = (string) ($item['id'] ?? '');
            if (!$deviceId) {
                continue;
            }

            $type = match (strtolower($item['type'] ?? '')) {
                'softphone' => 'softphone',
                'hardphone' => 'deskphone',
                'otherphone' => 'mobile',
                'webphone' => 'webrtc',
                default => strtolower($item['type'] ?? 'softphone'),
            };

            $record = UserConnectorDevice::updateOrCreate(
                [
                    'connection_id' => $connection->id,
                    'external_id' => $deviceId,
                ],
                [
                    'name' => $item['name'] ?? $item['model']['name'] ?? $deviceId,
                    'type' => $type,
                    'is_online' => isset($item['status']) ? ($item['status'] === 'Online') : null,
                    'meta' => array_filter([
                        'model' => $item['model'] ?? null,
                        'serial' => $item['serial'] ?? null,
                        'computerName' => $item['computerName'] ?? null,
                        'status' => $item['status'] ?? null,
                    ]),
                ]
            );

            $syncedIds[] = $record->id;
        }

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
            $baseUrl = config("user-connectors.{$this->connectorKey}.api_base_url", 'https://platform.ringcentral.com/restapi/v1.0');
            $response = Http::withToken($token)
                ->timeout(10)
                ->get($baseUrl . '/account/~');

            if ($response->successful()) {
                $data = $response->json();

                $connection->status = 'active';
                $connection->last_error = null;
                $connection->last_tested_at = now();
                $connection->save();

                // Sync profile data on successful test
                $this->syncProfile($connection);

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
