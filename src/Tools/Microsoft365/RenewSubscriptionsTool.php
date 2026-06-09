<?php

namespace Platform\UserConnectors\Tools\Microsoft365;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\UserConnectors\Models\UserConnectorConnection;
use Platform\UserConnectors\Services\Microsoft365\Microsoft365SubscriptionService;

/**
 * Erneuert die Microsoft-Graph-Webhook-Subscriptions einer microsoft365-
 * Connection. Erste Hilfe für „inbound webhooks kommen nicht mehr an" —
 * das passiert oft, wenn die Subscription abgelaufen ist und der
 * Renewal-Job seit Tagen down ist.
 *
 * Versucht zuerst PATCH (Erneuerung); falls eine Subscription nicht mehr
 * existiert, wird sie neu angelegt (createSubscriptions).
 */
class RenewSubscriptionsTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'user-connectors.microsoft365.subscriptions.renew';
    }

    public function getDescription(): string
    {
        return 'Erneuert alle Webhook-Subscriptions einer microsoft365-Connection. '
            . 'Standardmäßig die erste aktive Connection des Users; via connection_id explizit wählbar. '
            . 'Patcht expirationDateTime der bekannten Subscriptions; bei Fehlern (Subscription nicht mehr da) '
            . 'wird neu angelegt. Mit force_recreate=true wird zusätzlich getSubscriptionResources gerufen — '
            . 'fehlt eine Resource komplett in den credentials, wird sie damit (wieder) angelegt.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'connection_id' => ['type' => 'integer', 'description' => 'Optional. Default: erste aktive microsoft365-Connection.'],
                'force_recreate' => [
                    'type' => 'boolean',
                    'description' => 'Default: false. Wenn true, werden alle konfigurierten Resources neu angelegt, auch wenn die Subscription gar nicht mehr in credentials steht.',
                ],
            ],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        if (!$context->user) {
            return ToolResult::error('AUTH_ERROR', 'Benutzer nicht authentifiziert.');
        }

        $connectionId = $arguments['connection_id'] ?? null;
        $connection = $connectionId
            ? UserConnectorConnection::find($connectionId)
            : UserConnectorConnection::query()
                ->where('owner_user_id', $context->user->id)
                ->whereHas('connector', fn ($q) => $q->where('key', 'microsoft365'))
                ->where('status', 'active')
                ->orderByDesc('id')
                ->first();

        if (!$connection) {
            return ToolResult::error('NOT_FOUND', 'Keine passende microsoft365-Connection gefunden.');
        }

        $service = app(Microsoft365SubscriptionService::class);
        $forceRecreate = (bool) ($arguments['force_recreate'] ?? false);

        try {
            $renewed = $service->renewSubscriptions($connection);

            // Bei force_recreate: zusätzlich gewünschte Resources prüfen und
            // fehlende neu anlegen. Schließt die Lücke, wenn renew nur über
            // credentials.subscriptions iteriert und einige da gar nicht
            // mehr drin sind.
            $created = [];
            if ($forceRecreate) {
                $wanted = $service->getSubscriptionResources($connection);
                $haveResources = array_map(fn ($s) => $s['resource'] ?? null, $renewed);
                $missing = array_values(array_filter(
                    $wanted,
                    fn ($r) => !in_array($r['resource'] ?? null, $haveResources, true),
                ));
                if (!empty($missing)) {
                    $created = $service->createSubscriptions($connection, $missing);
                }
            }
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Renewal fehlgeschlagen: ' . $e->getMessage());
        }

        $mapper = fn ($s) => [
            'resource' => $s['resource'] ?? null,
            'change_types' => $s['change_types'] ?? null,
            'expires_at' => isset($s['expires_at']) ? date('Y-m-d H:i:s', $s['expires_at']) : null,
            'shared_mailbox' => $s['shared_mailbox'] ?? null,
            'app_level' => $s['app_level'] ?? false,
        ];

        return ToolResult::success([
            'connection_id' => $connection->id,
            'renewed_count' => count($renewed),
            'created_count' => count($created),
            'renewed' => array_map($mapper, $renewed),
            'newly_created' => array_map($mapper, $created),
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['microsoft365', 'subscriptions', 'webhooks', 'renew', 'maintenance'],
            'read_only' => false,
            'requires_auth' => true,
            'risk_level' => 'write',
            'idempotent' => true,
            'cost_class' => 'external_api_free',
        ];
    }
}
