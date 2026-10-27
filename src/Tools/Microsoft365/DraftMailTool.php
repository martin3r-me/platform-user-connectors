<?php

namespace Platform\UserConnectors\Tools\Microsoft365;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolResult;

/**
 * Entwürfe weiterbearbeiten: senden, ändern, verwerfen. Entwürfe entstehen
 * über save_as_draft in mail.send / mail.reply / mail.forward oder in Outlook;
 * auflisten mit mail.list folder=drafts.
 */
class DraftMailTool extends Microsoft365Tool
{
    public function getName(): string
    {
        return 'user-connectors.microsoft365.mail.draft';
    }

    public function getDescription(): string
    {
        return 'Bearbeitet einen Outlook-Entwurf: action=send (abschicken), update (Betreff/Text/Empfänger ändern, Anhänge ergänzen) '
            . 'oder delete (verwerfen). draft_id aus save_as_draft-Antworten oder mail.list folder=drafts.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'connection_id' => self::connectionProperty(),
                'action' => ['type' => 'string', 'enum' => ['send', 'update', 'delete']],
                'draft_id' => ['type' => 'string', 'description' => 'Message-ID des Entwurfs.'],
                'subject' => ['type' => 'string', 'description' => 'update: neuer Betreff.'],
                'body' => ['type' => 'string', 'description' => 'update: neuer Text — ersetzt den kompletten Body (bei Antwort-Entwürfen inkl. Zitat).'],
                'body_type' => ['type' => 'string', 'enum' => ['text', 'html']],
                'to' => self::addressProperty('update: ersetzt die An-Liste.'),
                'cc' => self::addressProperty('update: ersetzt die CC-Liste.'),
                'bcc' => self::addressProperty('update: ersetzt die BCC-Liste.'),
                'importance' => ['type' => 'string', 'enum' => ['low', 'normal', 'high']],
                'attachments' => self::attachmentsProperty(),
                'send_after_update' => ['type' => 'boolean', 'description' => 'update: danach direkt senden. Default false.'],
            ],
            'required' => ['action', 'draft_id'],
        ];
    }

    protected function errorPrefix(): string
    {
        return 'Entwurf-Aktion fehlgeschlagen';
    }

    protected function handle(array $arguments, ToolContext $context): ToolResult
    {
        $draftId = $this->requireString($arguments, 'draft_id');
        $connector = $this->mail($arguments);

        switch ($arguments['action'] ?? '') {
            case 'send':
                $connector->sendDraft($context->user, $draftId);

                return ToolResult::success(['status' => 'sent', 'draft_id' => $draftId]);

            case 'delete':
                $connector->deleteMessage($context->user, $draftId);

                return ToolResult::success(['status' => 'deleted', 'draft_id' => $draftId]);

            case 'update':
                $message = $connector->updateDraft(
                    $context->user,
                    $draftId,
                    array_intersect_key($arguments, array_flip(['subject', 'body', 'body_type', 'to', 'cc', 'bcc', 'importance'])),
                    $this->attachmentsArgument($arguments),
                );
                $send = (bool) $this->optionalBool($arguments, 'send_after_update');
                if ($send) {
                    $connector->sendDraft($context->user, $draftId);
                }

                return ToolResult::success(array_filter([
                    'status' => $send ? 'sent' : 'updated',
                    'draft_id' => $draftId,
                    'subject' => $message->subject,
                    'to' => $message->to ?: null,
                    'cc' => $message->cc ?: null,
                    'web_link' => $message->webLink,
                ], fn ($v) => $v !== null));

            default:
                throw new \InvalidArgumentException('action muss send, update oder delete sein.');
        }
    }

    public function getMetadata(): array
    {
        return self::writeMetadata(['outlook', 'mail', 'email', 'draft', 'entwurf', 'send', 'channel:mail']);
    }
}
