<?php

namespace Platform\UserConnectors\Tools\Microsoft365;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\UserConnectors\Services\Microsoft365\Microsoft365MailConnector;

class SendMailTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'user-connectors.microsoft365.mail.send';
    }

    public function getDescription(): string
    {
        return 'Sendet eine E-Mail über das Outlook-Konto des Users. Unterstützt HTML-Body und mehrere Empfänger (kommagetrennt).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'connection_id' => ['type' => 'integer', 'description' => 'Optionale Connection-ID.'],
                'to' => ['type' => 'string', 'description' => 'Empfänger E-Mail-Adresse(n), kommagetrennt.'],
                'subject' => ['type' => 'string', 'description' => 'Betreff der E-Mail.'],
                'body' => ['type' => 'string', 'description' => 'Inhalt der E-Mail (HTML erlaubt).'],
            ],
            'required' => ['to', 'subject', 'body'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        if (!$context->user) {
            return ToolResult::error('AUTH_ERROR', 'Benutzer nicht authentifiziert.');
        }

        try {
            $connector = app(Microsoft365MailConnector::class);
            if (!empty($arguments['connection_id'])) {
                $connector = new Microsoft365MailConnector(
                    app(\Platform\UserConnectors\Services\Microsoft365\Microsoft365ApiService::class)
                        ->forConnection($arguments['connection_id'])
                );
            }

            $message = $connector->sendMessage(
                $context->user,
                $arguments['to'],
                $arguments['subject'],
                $arguments['body'],
            );

            return ToolResult::success([
                'message' => $message->toArray(),
                'status' => 'sent',
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['microsoft365', 'outlook', 'mail', 'email', 'send'],
            'read_only' => false,
            'requires_auth' => true,
            'risk_level' => 'write',
            'confirmation_required' => true,
            'cost_class' => 'external_api_free',
        ];
    }
}
