<?php

namespace Platform\UserConnectors\Services\Sipgate;

use Carbon\Carbon;
use Platform\Core\Models\User;
use Platform\UserConnectors\Contracts\MessageConnector;
use Platform\UserConnectors\DTOs\Message;
use Platform\UserConnectors\DTOs\Pagination;

/**
 * Sipgate SMS implementation of MessageConnector.
 *
 * Sipgate's API treats SMS as part of the history/sessions model,
 * not as a traditional message store. This adapter normalizes
 * the SMS history into the unified Message DTO.
 */
class SipgateMessageConnector implements MessageConnector
{
    public function __construct(
        protected SipgateApiService $api,
    ) {}

    public function listMessages(User $user, array $filters = [], ?Pagination $pagination = null): array
    {
        $perPage = $pagination?->perPage ?? 25;
        $page = $pagination?->page ?? 1;
        $offset = ($page - 1) * $perPage;

        $apiFilters = [
            'types' => 'SMS',
            'limit' => $perPage,
            'offset' => $offset,
        ];

        if (!empty($filters['direction'])) {
            $apiFilters['directions'] = ucfirst(strtolower($filters['direction']));
        }
        if (!empty($filters['phonenumber'])) {
            $apiFilters['phonenumber'] = $filters['phonenumber'];
        }
        if (!empty($filters['from'])) {
            $apiFilters['from'] = $filters['from'];
        }
        if (!empty($filters['to'])) {
            $apiFilters['to'] = $filters['to'];
        }

        $data = $this->api->getHistory($user, $apiFilters);

        $messages = array_map(
            fn (array $entry) => $this->mapMessage($entry),
            $data['items'] ?? []
        );

        $total = $data['totalCount'] ?? null;
        $resultPagination = new Pagination(
            page: $page,
            perPage: $perPage,
            total: $total,
        );

        return ['messages' => $messages, 'pagination' => $resultPagination];
    }

    public function getMessage(User $user, string $messageId): Message
    {
        // Sipgate doesn't have a single-message endpoint.
        // Use history with the ID to find the entry.
        $data = $this->api->getHistory($user, [
            'types' => 'SMS',
            'limit' => 50,
        ]);

        foreach ($data['items'] ?? [] as $item) {
            if (($item['id'] ?? '') === $messageId) {
                return $this->mapMessage($item);
            }
        }

        throw new \RuntimeException("SMS mit ID '{$messageId}' nicht gefunden.");
    }

    public function sendMessage(User $user, string $to, string $subject, string $body, array $attachments = []): Message
    {
        $smsId = $this->resolveSmsId($user);

        $this->api->sendSms($user, $smsId, $to, $body);

        // Sipgate returns 204 on SMS send, construct a response Message
        return new Message(
            id: uniqid('sms_'),
            provider: 'sipgate',
            from: $smsId,
            to: [$to],
            subject: null,
            body: $body,
            bodyType: 'text',
            date: Carbon::now(),
            isRead: true,
            threadId: null,
            attachments: [],
            raw: ['smsId' => $smsId, 'recipient' => $to, 'message' => $body],
        );
    }

    public function replyToMessage(User $user, string $messageId, string $body, array $attachments = []): Message
    {
        $original = $this->getMessage($user, $messageId);

        // Reply to the sender of the original message
        $replyTo = $original->from;
        if (!$replyTo) {
            throw new \RuntimeException('Keine Telefonnummer für die Antwort gefunden.');
        }

        return $this->sendMessage($user, $replyTo, '', $body);
    }

    public function searchMessages(User $user, string $query, ?Pagination $pagination = null): array
    {
        // Sipgate history doesn't support full-text search.
        // Best effort: filter by phone number.
        return $this->listMessages($user, ['phonenumber' => $query], $pagination);
    }

    public function deleteMessage(User $user, string $messageId): bool
    {
        // Sipgate doesn't support deleting individual SMS from history.
        throw new \RuntimeException('Sipgate unterstützt das Löschen einzelner SMS nicht.');
    }

    protected function mapMessage(array $data): Message
    {
        $from = $data['source'] ?? $data['from'] ?? 'unknown';
        $to = [$data['target'] ?? $data['to'] ?? ''];

        return new Message(
            id: (string) ($data['id'] ?? ''),
            provider: 'sipgate',
            from: $from,
            to: $to,
            subject: null,
            body: $data['smsContent'] ?? $data['note'] ?? '',
            bodyType: 'text',
            date: Carbon::parse($data['created'] ?? $data['createdAt'] ?? now()),
            isRead: $data['read'] ?? true,
            threadId: null,
            attachments: [],
            raw: $data,
        );
    }

    protected function resolveSmsId(User $user): string
    {
        try {
            $data = $this->api->getSmsExtensions($user);

            $items = $data['items'] ?? [];
            if (!empty($items)) {
                return $items[0]['id'] ?? $items[0]['smsId'] ?? '';
            }
        } catch (\Exception $e) {
            // Fall through
        }

        throw new \RuntimeException('Keine SMS-Extension bei Sipgate gefunden.');
    }
}
