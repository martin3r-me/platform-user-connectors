<?php

namespace Platform\UserConnectors\Tools\Microsoft365;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolResult;

/**
 * Echte Outlook-Antwort über Graph /reply bzw. /replyAll — behält Thread,
 * Conversation-ID und zitiert das Original (anders als mail.send).
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
            . 'Default reply_all=true — alle ursprünglichen Empfänger bleiben drauf; reply_all=false antwortet nur dem Absender. '
            . 'Body: Plain Text (Zeilenumbrüche bleiben) oder HTML.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'connection_id' => self::connectionProperty(),
                'external_mail_id' => ['type' => 'string', 'description' => 'Graph-Message-ID der Mail, auf die geantwortet wird (id aus mail.list).'],
                'body' => ['type' => 'string', 'description' => 'Antworttext.'],
                'body_type' => ['type' => 'string', 'enum' => ['text', 'html'], 'description' => 'Optional. Default: automatisch.'],
                'reply_all' => ['type' => 'boolean', 'description' => 'Default: true.'],
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

        $this->mail($arguments)->replyToMessage($context->user, $mailId, $body, [], $replyAll, $arguments['body_type'] ?? null);

        return ToolResult::success([
            'status' => 'sent',
            'reply_all' => $replyAll,
            'external_mail_id' => $mailId,
        ]);
    }

    public function getMetadata(): array
    {
        return self::writeMetadata(['outlook', 'mail', 'email', 'reply', 'channel:mail', 'action:reply'], [
            'related_tools' => ['user-connectors.microsoft365.mail.list'],
        ]);
    }
}
