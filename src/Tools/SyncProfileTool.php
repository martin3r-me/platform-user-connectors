<?php

namespace Platform\UserConnectors\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolResult;
use Platform\UserConnectors\Models\UserConnectorPhoneNumber;
use Platform\UserConnectors\Models\UserConnectorDevice;
use Illuminate\Support\Facades\Http;
use Platform\UserConnectors\Services\Sipgate\SipgateConnectorService;
use Platform\UserConnectors\Services\RingCentral\RingCentralConnectorService;
use Platform\UserConnectors\Services\Vodafone\VodafoneConnectorService;
use Platform\UserConnectors\Tools\Telephony\TelephonyTool;

class SyncProfileTool extends ConnectorTool
{
    public function getName(): string
    {
        return 'user-connectors.profile.sync';
    }

    public function getDescription(): string
    {
        return 'Synchronisiert das Profil (Rufnummern, Geräte) einer Verbindung vom Provider und schreibt in die strukturierten Tabellen. Gibt Diagnose-Infos zurück (Anzahl Nummern/Geräte im JSON-Blob vs. Tabellen, Fehler).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'connection_id' => ['type' => 'integer', 'description' => 'Connection-ID. Ohne: erste aktive Telefonie-Verbindung (eigene vor geteilten).'],
                'connector_key' => ['type' => 'string', 'enum' => TelephonyTool::PROVIDERS, 'description' => 'Optional. Anbieter, wenn ohne connection_id.'],
                'diagnose_only' => ['type' => 'boolean', 'description' => 'Nur Diagnose ausgeben, keinen Sync auslösen.'],
            ],
            'required' => [],
        ];
    }

    protected function handle(array $arguments, ToolContext $context): ToolResult
    {
        $connection = $this->resolveConnection($context->user, $arguments, TelephonyTool::PROVIDERS);

        try {

            $connection->loadMissing('connector');
            $connectorKey = $connection->connector?->key;

            // Diagnose: Profile blob
            $credentials = $connection->credentials ?? [];
            $profile = $credentials['profile'] ?? null;
            $blobNumbers = $profile['numbers'] ?? [];
            $blobDevices = $profile['devices'] ?? [];
            $blobSms = $profile['sms_extensions'] ?? [];

            // Diagnose: Tables
            $tableNumbers = UserConnectorPhoneNumber::where('connection_id', $connection->id)->count();
            $tableDevices = UserConnectorDevice::where('connection_id', $connection->id)->count();

            $diagnose = [
                'connection_id' => $connection->id,
                'connector_key' => $connectorKey,
                'status' => $connection->status,
                'profile_blob' => [
                    'exists' => $profile !== null,
                    'synced_at' => $profile['synced_at'] ?? null,
                    'numbers_count' => count($blobNumbers),
                    'devices_count' => count($blobDevices),
                    'sms_extensions_count' => count($blobSms),
                    'number_samples' => array_slice(array_map(fn ($n) => $n['number'] ?? $n['phoneNumber'] ?? '?', $blobNumbers), 0, 5),
                    'device_samples' => array_slice(array_map(fn ($d) => ($d['alias'] ?? $d['name'] ?? $d['id'] ?? '?') . ' (' . ($d['type'] ?? '?') . ')', $blobDevices), 0, 5),
                ],
                'tables' => [
                    'phone_numbers_count' => $tableNumbers,
                    'devices_count' => $tableDevices,
                ],
            ];

            if (!empty($arguments['diagnose_only'])) {
                // Raw API probe for Sipgate
                if ($connectorKey === 'sipgate') {
                    $token = $credentials['oauth']['access_token'] ?? null;
                    if ($token) {
                        $baseUrl = config('user-connectors.sipgate.api_base_url', 'https://api.sipgate.com/v2');
                        $http = Http::withToken($token)->timeout(10)->withHeaders(['Accept' => 'application/json']);

                        $webuserId = $credentials['oauth']['sipgate_sub'] ?? 'w0';
                        $numbersResp = $http->get($baseUrl . '/numbers');
                        $devicesResp = $http->get($baseUrl . '/' . $webuserId . '/devices');
                        $smsResp = $http->get($baseUrl . '/' . $webuserId . '/sms');

                        $phonelinesResp = $http->get($baseUrl . '/' . $webuserId . '/phonelines');

                        $diagnose['raw_api'] = [
                            'numbers' => ['status' => $numbersResp->status(), 'body' => $numbersResp->json()],
                            'devices' => ['status' => $devicesResp->status(), 'body' => $devicesResp->json()],
                            'sms' => ['status' => $smsResp->status(), 'body' => $smsResp->json()],
                            'phonelines' => ['status' => $phonelinesResp->status(), 'body' => $phonelinesResp->json()],
                        ];
                    }
                }

                return ToolResult::success($diagnose);
            }

            // Run sync
            $syncError = null;
            try {
                $service = match ($connectorKey) {
                    'sipgate' => app(SipgateConnectorService::class),
                    'ringcentral' => app(RingCentralConnectorService::class),
                    'vodafone' => app(VodafoneConnectorService::class),
                    default => null,
                };

                if ($service) {
                    $service->syncProfile($connection);
                } else {
                    $syncError = "Kein syncProfile für Connector '{$connectorKey}'.";
                }
            } catch (\Throwable $e) {
                report($e);
                $syncError = $e->getMessage();
            }

            // Re-check after sync
            $connection->refresh();
            $profileAfter = $connection->credentials['profile'] ?? null;
            $tableNumbersAfter = UserConnectorPhoneNumber::where('connection_id', $connection->id)->count();
            $tableDevicesAfter = UserConnectorDevice::where('connection_id', $connection->id)->count();

            $diagnose['after_sync'] = [
                'sync_error' => $syncError,
                'profile_synced_at' => $profileAfter['synced_at'] ?? null,
                'blob_numbers_count' => count($profileAfter['numbers'] ?? []),
                'blob_devices_count' => count($profileAfter['devices'] ?? []),
                'table_phone_numbers_count' => $tableNumbersAfter,
                'table_devices_count' => $tableDevicesAfter,
            ];

            return ToolResult::success($diagnose);
        } catch (\Throwable $e) {
            report($e);

            return ToolResult::error('Profil-Sync fehlgeschlagen: ' . $e->getMessage(), 'EXECUTION_ERROR');
        }
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['telefonie', 'sync', 'profile', 'debug', 'rufnummern', 'geräte'],
            'read_only' => false,
            'requires_auth' => true,
            'risk_level' => 'safe',
            'cost_class' => 'external_api_free',
        ];
    }
}
