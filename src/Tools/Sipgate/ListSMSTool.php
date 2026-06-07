<?php

namespace Platform\UserConnectors\Tools\Sipgate;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\UserConnectors\DTOs\Pagination;
use Platform\UserConnectors\Services\Sipgate\SipgateApiService;
use Platform\UserConnectors\Services\Sipgate\SipgateMessageConnector;

class ListSMSTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string { return 'user-connectors.sipgate.sms.list'; }

    public function getDescription(): string
    {
        return 'Listet SMS (inbound + outbound) der Sipgate-Verbindung.';
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
            $connector = app(SipgateMessageConnector::class);
            if (!empty($arguments['connection_id'])) {
                $connector = new SipgateMessageConnector(
                    app(SipgateApiService::class)->forConnection((int) $arguments['connection_id'])
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
        return ['category' => 'inspection', 'tags' => ['sipgate', 'sms', 'list'],
            'read_only' => true, 'requires_auth' => true, 'risk_level' => 'read', 'idempotent' => true,
            'cost_class' => 'external_api_free'];
    }
}
