<?php

namespace Platform\UserConnectors\Tools\RingCentral;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\UserConnectors\DTOs\Pagination;
use Platform\UserConnectors\Services\RingCentral\RingCentralApiService;
use Platform\UserConnectors\Services\RingCentral\RingCentralMessageConnector;

class ListSMSTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string { return 'user-connectors.ringcentral.sms.list'; }

    public function getDescription(): string
    {
        return 'Listet SMS (inbound + outbound) der RingCentral-Verbindung.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'connection_id' => ['type' => 'integer'],
                'per_page' => ['type' => 'integer'],
                'page' => ['type' => 'integer'],
            ],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        if (!$context->user) {
            return ToolResult::error('AUTH_ERROR', 'Benutzer nicht authentifiziert.');
        }
        try {
            $connector = app(RingCentralMessageConnector::class);
            if (!empty($arguments['connection_id'])) {
                $connector = new RingCentralMessageConnector(
                    app(RingCentralApiService::class)->forConnection((int) $arguments['connection_id'])
                );
            }
            $pagination = new Pagination(
                page: (int) ($arguments['page'] ?? 1),
                perPage: (int) ($arguments['per_page'] ?? 25),
            );
            return ToolResult::success($connector->listMessages($context->user, [], $pagination));
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return ['category' => 'inspection', 'tags' => ['ringcentral', 'sms', 'list'],
            'read_only' => true, 'requires_auth' => true, 'risk_level' => 'read', 'idempotent' => true,
            'cost_class' => 'external_api_free'];
    }
}
