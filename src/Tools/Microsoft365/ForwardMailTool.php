<?php

namespace Platform\UserConnectors\Tools\Microsoft365;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolResult;

/**
 * Weiterleiten über Graphs nativen Forward — Anhänge und Original-Inhalt
 * bleiben erhalten (anders als mail.send).
 */
class ForwardMailTool extends Microsoft365Tool
{
    public function getName(): string
    {
        return 'user-connectors.microsoft365.mail.forward';
    }

    public function getDescription(): string
    {
        return 'Leitet eine bestehende Outlook-Mail weiter — Original-Anhänge und -Text bleiben erhalten. '
            . 'Optional comment als Intro, cc/bcc, weitere Anhänge; save_as_draft=true legt die Weiterleitung nur als Entwurf ab.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'connection_id' => self::connectionProperty(),
                'external_mail_id' => ['type' => 'string', 'description' => 'Graph-Message-ID der Original-Mail (id aus mail.list).'],
                'to' => self::addressProperty('Empfänger.'),
                'cc' => self::addressProperty('Optional. CC-Empfänger.'),
                'bcc' => self::addressProperty('Optional. BCC-Empfänger.'),
                'comment' => ['type' => 'string', 'description' => 'Optional. Intro-Text vor dem Original.'],
                'body_type' => ['type' => 'string', 'enum' => ['text', 'html'], 'description' => 'Optional, Format des comment. Default: automatisch.'],
                'importance' => ['type' => 'string', 'enum' => ['low', 'normal', 'high']],
                'attachments' => self::attachmentsProperty(),
                'save_as_draft' => ['type' => 'boolean', 'description' => 'true = nur als Entwurf speichern (liefert draft_id + web_link).'],
            ],
            'required' => ['external_mail_id', 'to'],
        ];
    }

    protected function errorPrefix(): string
    {
        return 'Weiterleiten fehlgeschlagen';
    }

    protected function handle(array $arguments, ToolContext $context): ToolResult
    {
        $mailId = $this->requireString($arguments, 'external_mail_id');
        $draft = (bool) $this->optionalBool($arguments, 'save_as_draft');

        $message = $this->mail($arguments)->forwardMessage(
            $context->user,
            $mailId,
            is_array($arguments['to'] ?? null) ? $arguments['to'] : (string) ($arguments['to'] ?? ''),
            (string) ($arguments['comment'] ?? ''),
            $arguments['body_type'] ?? null,
            $this->attachmentsArgument($arguments),
            [
                'cc' => $arguments['cc'] ?? null,
                'bcc' => $arguments['bcc'] ?? null,
                'importance' => $arguments['importance'] ?? null,
                'save_as_draft' => $draft,
            ],
        );

        return ToolResult::success(array_filter([
            'status' => $draft ? 'draft_saved' : 'forwarded',
            'recipients' => $message->to,
            'cc' => $message->cc ?: null,
            'bcc' => ($message->raw['bcc'] ?? []) ?: null,
            'external_mail_id' => $mailId,
            'draft_id' => $draft ? $message->id : null,
            'web_link' => $message->webLink,
        ], fn ($v) => $v !== null));
    }

    public function getMetadata(): array
    {
        return self::writeMetadata(['outlook', 'mail', 'email', 'forward', 'draft', 'channel:mail', 'action:forward']);
    }
}
