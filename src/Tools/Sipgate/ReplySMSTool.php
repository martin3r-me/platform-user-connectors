<?php

namespace Platform\UserConnectors\Tools\Sipgate;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\UserConnectors\Services\Sipgate\SipgateApiService;
use Platform\UserConnectors\Services\Sipgate\SipgateMessageConnector;

class ReplySMSTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string { return 'user-connectors.sipgate.sms.reply'; }

    public function getDescription(): string
    {
        return 'Antwortet auf eine bestehende Sipgate-SMS (replyToMessage). Erwartet message_id und body.';
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
            $connector = app(SipgateMessageConnector::class);
            if (!empty($arguments['connection_id'])) {
                $connector = new SipgateMessageConnector(
                    app(SipgateApiService::class)->forConnection((int) $arguments['connection_id'])
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
        return ['category' => 'action', 'tags' => ['sipgate', 'sms', 'reply'],
            'read_only' => false, 'requires_auth' => true, 'risk_level' => 'write',
            'confirmation_required' => true, 'cost_class' => 'external_api_paid'];
    }
}
