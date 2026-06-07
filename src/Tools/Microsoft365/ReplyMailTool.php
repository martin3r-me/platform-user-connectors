<?php

namespace Platform\UserConnectors\Tools\Microsoft365;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\UserConnectors\Services\Microsoft365\Microsoft365ApiService;
use Platform\UserConnectors\Services\Microsoft365\Microsoft365MailConnector;

/**
 * Echte Outlook-Antwort über Graph POST /messages/{id}/reply — behält
 * Thread-Header, Conversation-ID und Original-Inhalt. Unterschied zu
 * SendMailTool: das wäre eine fresh composed mail ohne Thread-Bezug.
 */
class ReplyMailTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string { return 'user-connectors.microsoft365.mail.reply'; }

    public function getDescription(): string
    {
        return 'Antwortet auf eine Outlook-Mail über Graphs nativen Reply-Endpoint '
            . '(Thread + Conversation-ID bleiben erhalten). Default: reply_all=true — '
            . 'alle ursprünglichen Empfänger bleiben drauf. Mit reply_all=false geht die '
            . 'Antwort nur an den Absender.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'connection_id' => ['type' => 'integer'],
                'external_mail_id' => ['type' => 'string'],
                'body' => ['type' => 'string'],
                'reply_all' => ['type' => 'boolean', 'description' => 'Default: true.'],
            ],
            'required' => ['external_mail_id', 'body'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        if (!$context->user) {
            return ToolResult::error('AUTH_ERROR', 'Benutzer nicht authentifiziert.');
        }

        $mailId = trim((string) ($arguments['external_mail_id'] ?? ''));
        $body = (string) ($arguments['body'] ?? '');
        if ($mailId === '' || trim($body) === '') {
            return ToolResult::error('VALIDATION_ERROR', 'external_mail_id und body sind erforderlich.');
        }

        try {
            $connector = app(Microsoft365MailConnector::class);
            if (!empty($arguments['connection_id'])) {
                $connector = new Microsoft365MailConnector(
                    app(Microsoft365ApiService::class)->forConnection((int) $arguments['connection_id'])
                );
            }
            $replyAll = $arguments['reply_all'] ?? true;
            $message = $connector->replyToMessage($context->user, $mailId, $body, [], (bool) $replyAll);
            return ToolResult::success(['message' => $message->toArray(), 'status' => 'sent']);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Reply fehlgeschlagen: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return ['category' => 'action', 'tags' => ['microsoft365', 'mail', 'reply'], 'read_only' => false,
            'requires_auth' => true, 'risk_level' => 'write', 'confirmation_required' => true,
            'cost_class' => 'external_api_free'];
    }
}
