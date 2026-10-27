<?php

namespace Platform\UserConnectors\Tools\Telephony;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolResult;
use Platform\UserConnectors\DTOs\Message;
use Platform\UserConnectors\DTOs\Pagination;

class ListSmsTool extends TelephonyTool
{
    public function getName(): string
    {
        return 'user-connectors.telephony.sms.list';
    }

    public function getDescription(): string
    {
        return 'Listet SMS (ein- und ausgehend) live beim Anbieter der Telefonie-Verbindung. '
            . 'Für die lokal gespeicherte Historie über alle Kanäle: user-connectors.message-sessions.list.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => self::connectionProperties() + [
                'page' => ['type' => 'integer', 'description' => 'Seite ab 1.'],
                'per_page' => ['type' => 'integer', 'description' => '1–100. Default 25.'],
            ],
        ];
    }

    protected function handle(array $arguments, ToolContext $context): ToolResult
    {
        $connection = $this->connection($context->user, $arguments);
        $result = $this->messages($connection)->listMessages($context->user, [], new Pagination(
            page: max(1, (int) ($arguments['page'] ?? 1)),
            perPage: $this->limit($arguments),
        ));

        return ToolResult::success($this->connectionInfo($connection) + [
            'count' => count($result['messages']),
            'messages' => array_map(fn (Message $m) => $this->presentMessage($m), $result['messages']),
            'pagination' => $result['pagination']->toArray(),
        ]);
    }

    public function getMetadata(): array
    {
        return self::readMetadata(['sms', 'list', 'channel:sms', 'action:list']);
    }
}
