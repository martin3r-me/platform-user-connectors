<?php

namespace Platform\UserConnectors\Tools\Microsoft365;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolResult;

/**
 * Echte Outlook-Antwort (Thread, Conversation-ID und Original-Zitat bleiben
 * erhalten) — anders als mail.send.
 */
class ReplyMailTool extends Microsoft365Tool
{
    public function getName(): string
    {
        return 'user-connectors.microsoft365.mail.reply';
    }

    public function getDescription(): string
    {
        return 'Antwortet auf eine Outlook-Mail (Thread und Original-Zitat bleiben erhalten). '
            . 'reply_all=true (Default) = allen antworten, false = nur dem Absender. '
            . 'Zusätzliche Empfänger mit to/cc/bcc ergänzen, einzelne mit exclude entfernen, Anhänge mitschicken; '
            . 'save_as_draft=true legt die Antwort nur als Entwurf ab (später mail.draft).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'connection_id' => self::connectionProperty(),
                'external_mail_id' => ['type' => 'string', 'description' => 'Graph-Message-ID der Mail, auf die geantwortet wird (id aus mail.list).'],
                'body' => ['type' => 'string', 'description' => 'Antworttext (steht über dem zitierten Original).'],
                'body_type' => ['type' => 'string', 'enum' => ['text', 'html'], 'description' => 'Optional. Default: automatisch.'],
                'reply_all' => ['type' => 'boolean', 'description' => 'Default: true.'],
                'to' => self::addressProperty('Optional. Zusätzliche Empfänger (ergänzt die automatischen).'),
                'cc' => self::addressProperty('Optional. Zusätzliche CC-Empfänger.'),
                'bcc' => self::addressProperty('Optional. BCC-Empfänger.'),
                'exclude' => self::addressProperty('Optional. Adressen, die aus der Antwort entfernt werden (z. B. bei Allen antworten).'),
                'importance' => ['type' => 'string', 'enum' => ['low', 'normal', 'high']],
                'attachments' => self::attachmentsProperty(),
                'save_as_draft' => ['type' => 'boolean', 'description' => 'true = Antwort nur als Entwurf speichern (liefert draft_id + web_link).'],
            ],
            'required' => ['external_mail_id', 'body'],
        ];
    }

    protected function errorPrefix(): string
    {
        return 'Antwort fehlgeschlagen';
    }

    protected function handle(array $arguments, ToolContext $context): ToolResult
    {
        $mailId = $this->requireString($arguments, 'external_mail_id');
        $body = (string) ($arguments['body'] ?? '');
        if (trim($body) === '') {
            throw new \InvalidArgumentException('body ist erforderlich.');
        }
        $replyAll = $this->optionalBool($arguments, 'reply_all') ?? true;
        $draft = (bool) $this->optionalBool($arguments, 'save_as_draft');

        $message = $this->mail($arguments)->replyToMessage(
            $context->user,
            $mailId,
            $body,
            $this->attachmentsArgument($arguments),
            $replyAll,
            $arguments['body_type'] ?? null,
            [
                'to' => $arguments['to'] ?? null,
                'cc' => $arguments['cc'] ?? null,
                'bcc' => $arguments['bcc'] ?? null,
                'exclude' => $arguments['exclude'] ?? null,
                'importance' => $arguments['importance'] ?? null,
                'save_as_draft' => $draft,
            ],
        );

        return ToolResult::success(array_filter([
            'status' => $draft ? 'draft_saved' : 'sent',
            'reply_all' => $replyAll,
            'external_mail_id' => $mailId,
            'draft_id' => $draft ? $message->id : null,
            'web_link' => $message->webLink,
            'to' => $message->to ?: null,
            'cc' => $message->cc ?: null,
            'bcc' => ($message->raw['bcc'] ?? []) ?: null,
        ], fn ($v) => $v !== null));
    }

    public function getMetadata(): array
    {
        return self::writeMetadata(['outlook', 'mail', 'email', 'reply', 'reply_all', 'draft', 'channel:mail', 'action:reply'], [
            'examples' => [
                'Allen antworten: {"external_mail_id": "AAMk…", "body": "Danke, passt!"}',
                'Nur Absender, Chef in BCC: {"external_mail_id": "AAMk…", "body": "…", "reply_all": false, "bcc": "chef@firma.de"}',
                'Als Entwurf: {"external_mail_id": "AAMk…", "body": "…", "save_as_draft": true}',
            ],
            'related_tools' => ['user-connectors.microsoft365.mail.list', 'user-connectors.microsoft365.mail.draft'],
        ]);
    }
}
