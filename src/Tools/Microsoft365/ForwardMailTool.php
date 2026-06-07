<?php

namespace Platform\UserConnectors\Tools\Microsoft365;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\UserConnectors\Services\Microsoft365\Microsoft365ApiService;
use Platform\UserConnectors\Services\Microsoft365\Microsoft365MailConnector;

/**
 * Forward an existing Outlook mail via MS Graph's native /forward endpoint —
 * preserves attachments and the original message body (Graph appends the
 * comment above the quoted original). Unlike SendMail, this does NOT
 * compose a fresh message.
 */
class ForwardMailTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'user-connectors.microsoft365.mail.forward';
    }

    public function getDescription(): string
    {
        return 'Leitet eine bestehende Outlook-Mail an einen oder mehrere Empfänger weiter (MS Graph nativer Forward). '
            . 'Original-Anhänge und Originaltext bleiben erhalten. Erwartet die MS-Graph-Message-ID (external_mail_id). '
            . 'Optional: comment (kurzer Intro-Text vor dem Original-Inhalt).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'connection_id' => ['type' => 'integer', 'description' => 'Optionale Connection-ID.'],
                'external_mail_id' => ['type' => 'string', 'description' => 'MS-Graph Message-ID der Original-Mail (i.d.R. aus user_connector_mail_sessions.external_mail_id).'],
                'to' => [
                    'type' => 'array',
                    'description' => 'Empfänger-Adressen.',
                    'items' => ['type' => 'string'],
                ],
                'comment' => ['type' => 'string', 'description' => 'Optionaler Intro-Text vor dem Original-Inhalt.'],
            ],
            'required' => ['external_mail_id', 'to'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        if (!$context->user) {
            return ToolResult::error('AUTH_ERROR', 'Benutzer nicht authentifiziert.');
        }

        $externalMailId = trim((string) ($arguments['external_mail_id'] ?? ''));
        $to = (array) ($arguments['to'] ?? []);
        $comment = (string) ($arguments['comment'] ?? '');

        if ($externalMailId === '' || empty($to)) {
            return ToolResult::error('VALIDATION_ERROR', 'external_mail_id und to sind erforderlich.');
        }

        try {
            $connector = app(Microsoft365MailConnector::class);
            if (!empty($arguments['connection_id'])) {
                $connector = new Microsoft365MailConnector(
                    app(Microsoft365ApiService::class)->forConnection((int) $arguments['connection_id'])
                );
            }

            $message = $connector->forwardMessage(
                $context->user,
                $externalMailId,
                $to,
                $comment,
            );

            return ToolResult::success([
                'message' => $message->toArray(),
                'status' => 'forwarded',
                'recipients' => array_values($to),
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Forward fehlgeschlagen: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['microsoft365', 'outlook', 'mail', 'email', 'forward'],
            'read_only' => false,
            'requires_auth' => true,
            'risk_level' => 'write',
            'confirmation_required' => true,
            'cost_class' => 'external_api_free',
        ];
    }
}
