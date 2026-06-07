<?php

namespace Platform\UserConnectors\Services\Microsoft365;

use Carbon\Carbon;
use Platform\Core\Models\User;
use Platform\UserConnectors\Contracts\MessageConnector;
use Platform\UserConnectors\DTOs\Message;
use Platform\UserConnectors\DTOs\Pagination;

class Microsoft365MailConnector implements MessageConnector
{
    public function __construct(
        protected Microsoft365ApiService $api,
    ) {}

    public function listMessages(User $user, array $filters = [], ?Pagination $pagination = null): array
    {
        $query = [];

        $top = $pagination?->perPage ?? 25;
        $skip = $pagination ? ($pagination->page - 1) * $top : 0;

        $query['$top'] = $top;
        if ($skip > 0) {
            $query['$skip'] = $skip;
        }
        $query['$orderby'] = 'receivedDateTime desc';
        $query['$select'] = 'id,subject,bodyPreview,body,from,toRecipients,receivedDateTime,isRead,conversationId,hasAttachments';

        // Filter support
        $filterParts = [];
        if (!empty($filters['folder'])) {
            // Will use folder-specific endpoint
        }
        if (!empty($filters['is_read'])) {
            $filterParts[] = 'isRead eq ' . ($filters['is_read'] === 'true' ? 'true' : 'false');
        }
        if (!empty($filterParts)) {
            $query['$filter'] = implode(' and ', $filterParts);
        }

        $folder = $filters['folder'] ?? null;
        $path = $folder
            ? "/me/mailFolders/{$folder}/messages"
            : '/me/messages';

        $data = $this->api->get($user, $path, $query);

        $messages = array_map(
            fn (array $m) => $this->mapMessage($m),
            $data['value'] ?? []
        );

        $nextLink = $data['@odata.nextLink'] ?? null;
        $resultPagination = new Pagination(
            page: $pagination?->page ?? 1,
            perPage: $top,
            total: $data['@odata.count'] ?? null,
            nextLink: $nextLink,
        );

        return ['messages' => $messages, 'pagination' => $resultPagination];
    }

    public function getMessage(User $user, string $messageId): Message
    {
        $data = $this->api->get($user, "/me/messages/{$messageId}", [
            '$select' => 'id,subject,body,from,toRecipients,receivedDateTime,isRead,conversationId,hasAttachments,attachments',
            '$expand' => 'attachments',
        ]);

        return $this->mapMessage($data);
    }

    public function sendMessage(User $user, string $to, string $subject, string $body, array $attachments = []): Message
    {
        $message = [
            'subject' => $subject,
            'body' => [
                'contentType' => 'HTML',
                'content' => $body,
            ],
            'toRecipients' => array_map(fn (string $addr) => [
                'emailAddress' => ['address' => $addr],
            ], is_array($to) ? $to : [$to]),
        ];

        if (!empty($attachments)) {
            $message['attachments'] = $this->mapAttachmentsForSend($attachments);
        }

        // Send and save to Sent Items
        $data = $this->api->post($user, '/me/sendMail', [
            'message' => $message,
            'saveToSentItems' => true,
        ]);

        // sendMail returns 202 with no body, construct a minimal Message
        return new Message(
            id: 'sent-' . now()->timestamp,
            provider: 'microsoft365',
            from: 'me',
            to: is_array($to) ? $to : [$to],
            subject: $subject,
            body: $body,
            bodyType: 'html',
            date: now(),
            isRead: true,
            threadId: null,
            attachments: [],
            raw: $data ?: [],
        );
    }

    /**
     * Reply to a mail via Graph. Default: replyAll — alle ursprünglichen
     * Empfänger (außer dem Absender) bleiben dran. Override mit replyAll=false
     * für eine Privat-Antwort nur an den Absender.
     */
    public function replyToMessage(User $user, string $messageId, string $body, array $attachments = [], bool $replyAll = true): Message
    {
        $endpoint = $replyAll ? 'replyAll' : 'reply';

        $payload = [
            'comment' => $body,
        ];

        $this->api->post($user, "/me/messages/{$messageId}/{$endpoint}", $payload);

        // reply / replyAll returns 202, return a minimal Message stub.
        return new Message(
            id: 'reply-' . now()->timestamp,
            provider: 'microsoft365',
            from: 'me',
            to: [],
            subject: null,
            body: $body,
            bodyType: 'html',
            date: now(),
            isRead: true,
            threadId: null,
            attachments: [],
            raw: [],
        );
    }

    /**
     * Forward an existing mail to one or more recipients via MS Graph's native
     * forward endpoint — preserves attachments + original headers, unlike
     * sending a brand-new message. Pass addresses as a list; comment is the
     * forwarding intro (sits above the original mail in Outlook).
     *
     * @param array<int, string> $toAddresses
     */
    public function forwardMessage(User $user, string $messageId, array $toAddresses, string $comment = ''): Message
    {
        $recipients = array_values(array_filter(array_map(
            fn ($addr) => is_string($addr) && trim($addr) !== ''
                ? ['emailAddress' => ['address' => trim($addr)]]
                : null,
            $toAddresses,
        )));

        if (empty($recipients)) {
            throw new \InvalidArgumentException('Forward needs at least one recipient.');
        }

        $payload = [
            'comment' => $comment,
            'toRecipients' => $recipients,
        ];

        $this->api->post($user, "/me/messages/{$messageId}/forward", $payload);

        // forward returns 202 with no body — return a minimal Message stub.
        return new Message(
            id: 'forward-' . now()->timestamp,
            provider: 'microsoft365',
            from: 'me',
            to: array_map(fn ($r) => $r['emailAddress']['address'], $recipients),
            subject: null,
            body: $comment,
            bodyType: 'html',
            date: now(),
            isRead: true,
            threadId: null,
            attachments: [],
            raw: [],
        );
    }

    public function searchMessages(User $user, string $query, ?Pagination $pagination = null): array
    {
        $top = $pagination?->perPage ?? 25;

        $data = $this->api->get($user, '/me/messages', [
            '$search' => '"' . $query . '"',
            '$top' => $top,
            '$select' => 'id,subject,bodyPreview,body,from,toRecipients,receivedDateTime,isRead,conversationId,hasAttachments',
        ]);

        $messages = array_map(
            fn (array $m) => $this->mapMessage($m),
            $data['value'] ?? []
        );

        $resultPagination = new Pagination(
            page: $pagination?->page ?? 1,
            perPage: $top,
            total: $data['@odata.count'] ?? null,
        );

        return ['messages' => $messages, 'pagination' => $resultPagination];
    }

    public function deleteMessage(User $user, string $messageId): bool
    {
        return $this->api->delete($user, "/me/messages/{$messageId}");
    }

    protected function mapMessage(array $data): Message
    {
        $from = $data['from']['emailAddress']['address'] ?? ($data['from']['emailAddress']['name'] ?? 'unknown');
        $to = array_map(
            fn (array $r) => $r['emailAddress']['address'] ?? $r['emailAddress']['name'] ?? '',
            $data['toRecipients'] ?? []
        );

        $bodyContent = $data['body']['content'] ?? $data['bodyPreview'] ?? '';
        $bodyType = $data['body']['contentType'] ?? 'text';

        $attachmentsList = [];
        if (!empty($data['attachments'])) {
            foreach ($data['attachments'] as $att) {
                $attachmentsList[] = [
                    'id' => $att['id'] ?? null,
                    'name' => $att['name'] ?? null,
                    'content_type' => $att['contentType'] ?? null,
                    'size' => $att['size'] ?? null,
                ];
            }
        }

        return new Message(
            id: $data['id'] ?? '',
            provider: 'microsoft365',
            from: $from,
            to: $to,
            subject: $data['subject'] ?? null,
            body: $bodyContent,
            bodyType: strtolower($bodyType) === 'html' ? 'html' : 'text',
            date: Carbon::parse($data['receivedDateTime'] ?? now()),
            isRead: $data['isRead'] ?? false,
            threadId: $data['conversationId'] ?? null,
            attachments: $attachmentsList,
            raw: $data,
        );
    }

    protected function mapAttachmentsForSend(array $attachments): array
    {
        return array_map(fn (array $att) => [
            '@odata.type' => '#microsoft.graph.fileAttachment',
            'name' => $att['name'] ?? 'attachment',
            'contentType' => $att['content_type'] ?? 'application/octet-stream',
            'contentBytes' => $att['content_base64'] ?? base64_encode($att['content'] ?? ''),
        ], $attachments);
    }
}
