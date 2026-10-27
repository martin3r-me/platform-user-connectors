<?php

namespace Platform\UserConnectors\Tools\Microsoft365;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolResult;
use Platform\UserConnectors\DTOs\Message;
use Platform\UserConnectors\DTOs\Pagination;

class ListMailTool extends Microsoft365Tool
{
    public function getName(): string
    {
        return 'user-connectors.microsoft365.mail.list';
    }

    public function getDescription(): string
    {
        return 'Listet oder sucht E-Mails im Outlook-Postfach (neueste zuerst) — oder liest mit external_mail_id eine einzelne Mail komplett. '
            . 'Filter: folder, from, date_from/date_to, is_read, has_attachments; search = Volltext über Absender/Betreff/Body '
            . '(KQL möglich, z. B. "subject:Angebot"). Alle Filter sind frei kombinierbar. '
            . 'body_format: preview (Default, Kurzvorschau), text (voller Body als Klartext — beste Wahl zum Lesen), html, none. '
            . 'Weitere Seiten: next_cursor als cursor übergeben. Die id jeder Mail ist die external_mail_id für reply/forward/attachment.content.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'connection_id' => self::connectionProperty(),
                'external_mail_id' => ['type' => 'string', 'description' => 'Optional. Einzelne Mail per Graph-Message-ID abrufen (Default body_format=text). Andere Filter werden ignoriert.'],
                'folder' => ['type' => 'string', 'description' => 'Ordner: inbox, sentitems, drafts, deleteditems, junkemail, archive oder Ordner-ID. Default: alle Ordner.'],
                'search' => ['type' => 'string', 'description' => 'Volltextsuche (Absender, Betreff, Body). Ergebnisse nach Datum, kein total.'],
                'from' => ['type' => 'string', 'description' => 'Absender-E-Mail-Adresse (exakt).'],
                'date_from' => ['type' => 'string', 'description' => 'Empfangen ab (2026-11-03 oder ISO-8601).'],
                'date_to' => ['type' => 'string', 'description' => 'Empfangen bis (reines Datum = einschließlich dieses Tages).'],
                'is_read' => ['type' => 'boolean', 'description' => 'false = nur ungelesene, true = nur gelesene.'],
                'has_attachments' => ['type' => 'boolean', 'description' => 'Nur Mails mit (true) / ohne (false) Anhänge.'],
                'body_format' => ['type' => 'string', 'enum' => ['preview', 'text', 'html', 'none', 'full'], 'description' => 'Default: preview (bei external_mail_id: text). "full" = html.'],
                'per_page' => ['type' => 'integer', 'description' => 'Treffer pro Seite (1–100). Default 25.'],
                'limit' => ['type' => 'integer', 'description' => 'Alias für per_page.'],
                'page' => ['type' => 'integer', 'description' => 'Seite ab 1. Bei search max. 10 — danach cursor nutzen.'],
                'cursor' => self::cursorProperty(),
                'include_inline_attachments' => ['type' => 'boolean', 'description' => 'Default false: eingebettete Bilder (Signaturen, Logos) werden nicht als Anhang gelistet.'],
            ],
            'required' => [],
        ];
    }

    protected function handle(array $arguments, ToolContext $context): ToolResult
    {
        $connector = $this->mail($arguments);
        $includeInline = (bool) $this->optionalBool($arguments, 'include_inline_attachments');

        if (!empty($arguments['external_mail_id'])) {
            $message = $connector->getMessage(
                $context->user,
                trim((string) $arguments['external_mail_id']),
                (string) ($arguments['body_format'] ?? 'text'),
            );

            return ToolResult::success(['message' => $this->present($message, $includeInline, true)]);
        }

        $filters = array_filter([
            'folder' => $arguments['folder'] ?? null,
            'search' => $arguments['search'] ?? null,
            'from' => $arguments['from'] ?? null,
            'date_from' => $arguments['date_from'] ?? null,
            'date_to' => $arguments['date_to'] ?? null,
            'is_read' => $this->optionalBool($arguments, 'is_read'),
            'has_attachments' => $this->optionalBool($arguments, 'has_attachments'),
            'body_format' => $arguments['body_format'] ?? 'preview',
            'cursor' => $arguments['cursor'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        $result = $connector->listMessages($context->user, $filters, new Pagination(
            page: max(1, (int) ($arguments['page'] ?? 1)),
            perPage: max(1, min(100, (int) ($arguments['per_page'] ?? $arguments['limit'] ?? 25))),
        ));

        /** @var Pagination $pagination */
        $pagination = $result['pagination'];

        return ToolResult::success(array_filter([
            'count' => count($result['messages']),
            'total' => $pagination->total,
            'messages' => array_map(fn (Message $m) => $this->present($m, $includeInline, false), $result['messages']),
            'next_cursor' => $pagination->nextLink,
            'notes' => $result['notes'] ?: null,
            'filters' => array_diff_key($filters, ['cursor' => true]),
        ], fn ($v) => $v !== null));
    }

    protected function present(Message $message, bool $includeInline, bool $single): array
    {
        $data = $message->toArray();
        unset($data['provider']);

        if ($data['body_type'] === 'none' || ($data['body'] === '' && !$single)) {
            unset($data['body'], $data['body_type']);
        }
        if (!$includeInline) {
            $data['attachments'] = array_values(array_filter($data['attachments'], fn ($a) => empty($a['is_inline'])));
        }
        if ($data['attachments'] === []) {
            unset($data['attachments']);
        }

        return $data;
    }

    public function getMetadata(): array
    {
        return self::readMetadata(['outlook', 'mail', 'email', 'list', 'search', 'read', 'channel:mail', 'action:list', 'action:search'], [
            'examples' => [
                'Ungelesene Mails von heute: {"is_read": false, "date_from": "2026-11-03"}',
                'Suche: {"search": "Angebot", "from": "kunde@firma.de"}',
                'Mail lesen: {"external_mail_id": "AAMk…", "body_format": "text"}',
            ],
            'related_tools' => ['user-connectors.microsoft365.mail.reply', 'user-connectors.microsoft365.mail.forward', 'user-connectors.microsoft365.mail.attachment.content'],
        ]);
    }
}
