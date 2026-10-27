<?php

namespace Platform\UserConnectors\Tools\Microsoft365;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolResult;

/**
 * Liest den Inhalt eines Mail-Anhangs. mail.list liefert nur Metadaten
 * (id/name/size) — dies ist das Analogon zu integrations.easybill.attachment.content.
 */
class GetMailAttachmentContentTool extends Microsoft365Tool
{
    public function getName(): string
    {
        return 'user-connectors.microsoft365.mail.attachment.content';
    }

    public function getDescription(): string
    {
        return 'Liest den Inhalt (Base64) eines Outlook-Mail-Anhangs. external_mail_id + attachment_id stammen aus mail.list. '
            . 'Excel-Anhänge mit parse_spreadsheet=true direkt als Tabellen (Sheets/Rows) statt Base64.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'connection_id' => self::connectionProperty(),
                'external_mail_id' => ['type' => 'string', 'description' => 'Graph-Message-ID der Mail.'],
                'attachment_id' => ['type' => 'string', 'description' => 'Graph-Attachment-ID (attachments[].id aus mail.list).'],
                'parse_spreadsheet' => ['type' => 'boolean', 'description' => 'Optional: xlsx/xls serverseitig parsen und strukturierte Sheets/Rows statt Base64 liefern.'],
            ],
            'required' => ['external_mail_id', 'attachment_id'],
        ];
    }

    protected function handle(array $arguments, ToolContext $context): ToolResult
    {
        $attachment = $this->mail($arguments)->getAttachmentContent(
            $context->user,
            $this->requireString($arguments, 'external_mail_id'),
            $this->requireString($arguments, 'attachment_id'),
        );

        if ($this->optionalBool($arguments, 'parse_spreadsheet')) {
            $name = (string) ($attachment['name'] ?? '');
            if (!preg_match('/\.xlsx?$/i', $name) || empty($attachment['content_base64'])) {
                throw new \InvalidArgumentException('parse_spreadsheet erfordert einen xlsx/xls-Anhang mit Inhalt.');
            }
            $parsed = app(\Platform\Core\Services\SpreadsheetParserService::class)
                ->parse(base64_decode($attachment['content_base64'], true) ?: '', $name);
            unset($attachment['content_base64']);
            $attachment['sheets'] = $parsed['sheets'];
        }

        return ToolResult::success(['attachment' => $attachment]);
    }

    public function getMetadata(): array
    {
        return self::readMetadata(['outlook', 'mail', 'email', 'attachment', 'content']);
    }
}
