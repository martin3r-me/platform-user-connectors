<?php

namespace Platform\UserConnectors\Tools\RingCentral;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\UserConnectors\Services\RingCentral\RingCentralApiService;
use Platform\UserConnectors\Services\RingCentral\RingCentralMessageConnector;

class ReplySMSTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string { return 'user-connectors.ringcentral.sms.reply'; }

    public function getDescription(): string
    {
        return 'Antwortet auf eine bestehende RingCentral-SMS (replyToMessage). Erwartet message_id und body.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'connection_id' => ['type' => 'integer'],
                'message_id' => ['type' => 'string'],
                'body' => ['type' => 'string'],
            ],
            'required' => ['message_id', 'body'],
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
            $message = $connector->replyToMessage($context->user, (string) $arguments['message_id'], (string) $arguments['body']);
            return ToolResult::success(['message' => $message->toArray(), 'status' => 'sent']);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Reply fehlgeschlagen: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return ['category' => 'action', 'tags' => ['ringcentral', 'sms', 'reply'],
            'read_only' => false, 'requires_auth' => true, 'risk_level' => 'write',
            'confirmation_required' => true, 'cost_class' => 'external_api_paid'];
    }
}
