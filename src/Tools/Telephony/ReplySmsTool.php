<?php

namespace Platform\UserConnectors\Tools\Telephony;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolResult;

class ReplySmsTool extends TelephonyTool
{
    public function getName(): string
    {
        return 'user-connectors.telephony.sms.reply';
    }

    public function getDescription(): string
    {
        return 'Antwortet auf eine empfangene SMS (message_id aus telephony.sms.list) an deren Absender.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => self::connectionProperties() + [
                'message_id' => ['type' => 'string', 'description' => 'id der SMS, auf die geantwortet wird.'],
                'body' => ['type' => 'string'],
            ],
            'required' => ['message_id', 'body'],
        ];
    }

    protected function errorPrefix(): string
    {
        return 'SMS-Antwort fehlgeschlagen';
    }

    protected function handle(array $arguments, ToolContext $context): ToolResult
    {
        $connection = $this->connection($context->user, $arguments);
        $message = $this->messages($connection)->replyToMessage(
            $context->user,
            $this->requireString($arguments, 'message_id'),
            $this->requireString($arguments, 'body'),
        );

        return ToolResult::success($this->connectionInfo($connection) + [
            'status' => 'sent',
            'message' => $this->presentMessage($message),
        ]);
    }

    public function getMetadata(): array
    {
        return self::writeMetadata(['sms', 'reply', 'channel:sms', 'action:reply'], ['cost_class' => 'external_api_paid']);
    }
}
