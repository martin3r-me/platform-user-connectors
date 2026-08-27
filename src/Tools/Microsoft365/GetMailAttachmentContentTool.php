<?php

namespace Platform\UserConnectors\Tools\Microsoft365;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\UserConnectors\Services\Microsoft365\Microsoft365ApiService;
use Platform\UserConnectors\Services\Microsoft365\Microsoft365MailConnector;

/**
 * Liest den Binary-Inhalt eines Mail-Anhangs (base64) über MS Graph.
 * ListMailTool/getMessage liefern nur Attachment-Metadaten (id/name/size) —
 * dieses Tool ist das Analogon zu integrations.easybill.attachment.content.
 */
class GetMailAttachmentContentTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'user-connectors.microsoft365.mail.attachment.content';
    }

    public function getDescription(): string
    {
        return 'Liest den Inhalt (Base64) eines Anhangs einer Outlook-Mail. Benötigt external_mail_id (MS-Graph Message-ID) und attachment_id.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'connection_id' => ['type' => 'integer', 'description' => 'Optionale Connection-ID.'],
                'external_mail_id' => ['type' => 'string', 'description' => 'MS-Graph Message-ID der Mail (i.d.R. aus user_connector_mail_sessions.external_mail_id).'],
                'attachment_id' => ['type' => 'string', 'description' => 'MS-Graph Attachment-ID.'],
            ],
            'required' => ['external_mail_id', 'attachment_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        if (!$context->user) {
            return ToolResult::error('AUTH_ERROR', 'Benutzer nicht authentifiziert.');
        }

        $externalMailId = trim((string) ($arguments['external_mail_id'] ?? ''));
        $attachmentId = trim((string) ($arguments['attachment_id'] ?? ''));

        if ($externalMailId === '' || $attachmentId === '') {
            return ToolResult::error('VALIDATION_ERROR', 'external_mail_id und attachment_id sind erforderlich.');
        }

        try {
            $connector = app(Microsoft365MailConnector::class);
            if (!empty($arguments['connection_id'])) {
                $connector = new Microsoft365MailConnector(
                    app(Microsoft365ApiService::class)->forConnection((int) $arguments['connection_id'])
                );
            }

            $attachment = $connector->getAttachmentContent($context->user, $externalMailId, $attachmentId);

            return ToolResult::success(['attachment' => $attachment]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['microsoft365', 'outlook', 'mail', 'email', 'attachment', 'content'],
            'read_only' => true,
            'requires_auth' => true,
            'risk_level' => 'safe',
            'cost_class' => 'external_api_free',
        ];
    }
}
