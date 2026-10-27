<?php

namespace Platform\UserConnectors\Tools\Microsoft365;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolResult;

/**
 * Weiterleiten über Graphs nativen /forward — Anhänge und Original-Inhalt
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
            . 'Optional comment als Intro-Text über dem Original.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'connection_id' => self::connectionProperty(),
                'external_mail_id' => ['type' => 'string', 'description' => 'Graph-Message-ID der Original-Mail (id aus mail.list).'],
                'to' => [
                    'type' => ['array', 'string'],
                    'items' => ['type' => 'string'],
                    'description' => 'Empfänger als Array oder komma-/semikolongetrennt.',
                ],
                'comment' => ['type' => 'string', 'description' => 'Optional. Intro-Text vor dem Original.'],
                'body_type' => ['type' => 'string', 'enum' => ['text', 'html'], 'description' => 'Optional, Format des comment. Default: automatisch.'],
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

        $message = $this->mail($arguments)->forwardMessage(
            $context->user,
            $mailId,
            is_array($arguments['to'] ?? null) ? $arguments['to'] : (string) ($arguments['to'] ?? ''),
            (string) ($arguments['comment'] ?? ''),
            $arguments['body_type'] ?? null,
        );

        return ToolResult::success([
            'status' => 'forwarded',
            'recipients' => $message->to,
            'external_mail_id' => $mailId,
        ]);
    }

    public function getMetadata(): array
    {
        return self::writeMetadata(['outlook', 'mail', 'email', 'forward', 'channel:mail', 'action:forward']);
    }
}
