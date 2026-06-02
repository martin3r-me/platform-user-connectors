<?php

namespace Platform\UserConnectors\Services\RingCentral;

use Carbon\Carbon;
use Platform\Core\Models\User;
use Platform\UserConnectors\Contracts\MessageConnector;
use Platform\UserConnectors\DTOs\Message;
use Platform\UserConnectors\DTOs\Pagination;

/**
 * RingCentral SMS implementation of MessageConnector.
 */
class RingCentralMessageConnector implements MessageConnector
{
    public function __construct(
        protected RingCentralApiService $api,
    ) {}

    public function listMessages(User $user, array $filters = [], ?Pagination $pagination = null): array
    {
        $perPage = $pagination?->perPage ?? 25;
        $page = $pagination?->page ?? 1;

        $query = [
            'messageType' => 'SMS',
            'perPage' => $perPage,
            'page' => $page,
        ];

        if (!empty($filters['dateFrom'])) {
            $query['dateFrom'] = $filters['dateFrom'];
        }
        if (!empty($filters['dateTo'])) {
            $query['dateTo'] = $filters['dateTo'];
        }
        if (!empty($filters['direction'])) {
            $query['direction'] = $filters['direction'];
        }

        $data = $this->api->get($user, '/account/~/extension/~/message-store', $query);

        $messages = array_map(
            fn (array $m) => $this->mapMessage($m),
            $data['records'] ?? []
        );

        $paging = $data['paging'] ?? [];
        $resultPagination = new Pagination(
            page: $paging['page'] ?? $page,
            perPage: $paging['perPage'] ?? $perPage,
            total: $paging['totalElements'] ?? null,
        );

        return ['messages' => $messages, 'pagination' => $resultPagination];
    }

    public function getMessage(User $user, string $messageId): Message
    {
        $data = $this->api->get($user, "/account/~/extension/~/message-store/{$messageId}");

        return $this->mapMessage($data);
    }

    public function sendMessage(User $user, string $to, string $subject, string $body, array $attachments = []): Message
    {
        $data = $this->api->post($user, '/account/~/extension/~/sms', [
            'from' => ['phoneNumber' => $this->resolveFromNumber($user)],
            'to' => [['phoneNumber' => $to]],
            'text' => $body,
        ]);

        return $this->mapMessage($data);
    }

    public function replyToMessage(User $user, string $messageId, string $body, array $attachments = []): Message
    {
        // Get original message to find the sender
        $original = $this->api->get($user, "/account/~/extension/~/message-store/{$messageId}");

        $direction = $original['direction'] ?? 'Inbound';
        $replyTo = $direction === 'Inbound'
            ? ($original['from']['phoneNumber'] ?? '')
            : ($original['to'][0]['phoneNumber'] ?? '');

        if (!$replyTo) {
            throw new \RuntimeException('Keine Telefonnummer für die Antwort gefunden.');
        }

        return $this->sendMessage($user, $replyTo, '', $body);
    }

    public function searchMessages(User $user, string $query, ?Pagination $pagination = null): array
    {
        // RingCentral message-store does not support full-text search.
        // Return filtered list as best effort.
        return $this->listMessages($user, ['phoneNumber' => $query], $pagination);
    }

    public function deleteMessage(User $user, string $messageId): bool
    {
        return $this->api->delete($user, "/account/~/extension/~/message-store/{$messageId}");
    }

    protected function mapMessage(array $data): Message
    {
        $from = $data['from']['phoneNumber'] ?? ($data['from']['name'] ?? 'unknown');
        $to = array_map(
            fn (array $r) => $r['phoneNumber'] ?? ($r['name'] ?? ''),
            $data['to'] ?? []
        );

        return new Message(
            id: (string) ($data['id'] ?? ''),
            provider: 'ringcentral',
            from: $from,
            to: $to,
            subject: $data['subject'] ?? null,
            body: $data['subject'] ?? '',
            bodyType: 'text',
            date: Carbon::parse($data['creationTime'] ?? $data['lastModifiedTime'] ?? now()),
            isRead: ($data['readStatus'] ?? 'Unread') === 'Read',
            threadId: (string) ($data['conversationId'] ?? null),
            attachments: [],
            raw: $data,
        );
    }

    protected function resolveFromNumber(User $user): string
    {
        try {
            $data = $this->api->get($user, '/account/~/extension/~/phone-number', [
                'usageType' => 'DirectNumber',
                'perPage' => 10,
            ]);

            foreach ($data['records'] ?? [] as $number) {
                $features = $number['features'] ?? [];
                if (in_array('SmsSender', $features)) {
                    return $number['phoneNumber'];
                }
            }
        } catch (\Exception $e) {
            // Fall through
        }

        throw new \RuntimeException('Keine SMS-fähige Telefonnummer gefunden.');
    }
}
