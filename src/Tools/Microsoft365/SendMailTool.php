<?php

namespace Platform\UserConnectors\Tools\Microsoft365;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolResult;

class SendMailTool extends Microsoft365Tool
{
    public function getName(): string
    {
        return 'user-connectors.microsoft365.mail.send';
    }

    public function getDescription(): string
    {
        return 'Schreibt eine neue E-Mail über das Outlook-Konto des Users — senden oder mit save_as_draft=true nur als Entwurf ablegen. '
            . 'Empfänger (to/cc/bcc) als Array oder komma-/semikolongetrennt. Body: Plain Text (Zeilenumbrüche bleiben) oder HTML. '
            . 'Für Antworten auf eine bestehende Mail mail.reply nutzen (Thread bleibt erhalten).';
    }

    public function getSchema(): array
    {
        $addresses = fn (string $desc) => [
            'type' => ['array', 'string'],
            'items' => ['type' => 'string'],
            'description' => $desc,
        ];

        return [
            'type' => 'object',
            'properties' => [
                'connection_id' => self::connectionProperty(),
                'to' => $addresses('Empfänger, z. B. ["a@firma.de", "b@firma.de"] oder "a@firma.de, b@firma.de".'),
                'cc' => $addresses('Optional. CC-Empfänger.'),
                'bcc' => $addresses('Optional. BCC-Empfänger.'),
                'reply_to' => $addresses('Optional. Abweichende Antwortadresse.'),
                'subject' => ['type' => 'string', 'description' => 'Betreff.'],
                'body' => ['type' => 'string', 'description' => 'Inhalt (Plain Text oder HTML, siehe body_type).'],
                'body_type' => ['type' => 'string', 'enum' => ['text', 'html'], 'description' => 'Optional. Default: automatisch — ohne HTML-Tags wird Plain Text angenommen.'],
                'importance' => ['type' => 'string', 'enum' => ['low', 'normal', 'high']],
                'save_as_draft' => ['type' => 'boolean', 'description' => 'true = nicht senden, sondern als Entwurf speichern (liefert id + web_link zum Öffnen in Outlook).'],
                'attachments' => [
                    'type' => 'array',
                    'description' => 'Optional. Dateianhänge (je max. ~3 MB).',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => ['type' => 'string'],
                            'content_base64' => ['type' => 'string'],
                            'content_type' => ['type' => 'string'],
                        ],
                        'required' => ['name', 'content_base64'],
                    ],
                ],
            ],
            'required' => ['to', 'subject', 'body'],
        ];
    }

    protected function errorPrefix(): string
    {
        return 'Mail-Versand fehlgeschlagen';
    }

    protected function handle(array $arguments, ToolContext $context): ToolResult
    {
        $subject = $this->requireString($arguments, 'subject');
        $body = (string) ($arguments['body'] ?? '');
        if (trim($body) === '') {
            throw new \InvalidArgumentException('body ist erforderlich.');
        }
        $draft = (bool) $this->optionalBool($arguments, 'save_as_draft');

        $message = $this->mail($arguments)->sendMessage(
            $context->user,
            is_array($arguments['to'] ?? null) ? $arguments['to'] : (string) ($arguments['to'] ?? ''),
            $subject,
            $body,
            array_values(array_filter((array) ($arguments['attachments'] ?? []), 'is_array')),
            $arguments['body_type'] ?? null,
            [
                'cc' => $arguments['cc'] ?? null,
                'bcc' => $arguments['bcc'] ?? null,
                'reply_to' => $arguments['reply_to'] ?? null,
                'importance' => $arguments['importance'] ?? null,
                'save_as_draft' => $draft,
            ],
        );

        return ToolResult::success(array_filter([
            'status' => $draft ? 'draft_saved' : 'sent',
            'id' => $draft ? $message->id : null,
            'web_link' => $message->webLink,
            'to' => $message->to,
            'cc' => $message->cc ?: null,
            'subject' => $subject,
        ], fn ($v) => $v !== null));
    }

    public function getMetadata(): array
    {
        return self::writeMetadata(['outlook', 'mail', 'email', 'send', 'draft', 'channel:mail', 'action:send'], [
            'related_tools' => ['user-connectors.microsoft365.mail.reply', 'user-connectors.microsoft365.mail.forward'],
        ]);
    }
}
