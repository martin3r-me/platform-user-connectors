<?php

namespace Platform\UserConnectors\Contracts;

use Platform\Core\Models\User;
use Platform\UserConnectors\DTOs\Message;
use Platform\UserConnectors\DTOs\Pagination;

interface MessageConnector
{
    /**
     * @return array{messages: Message[], pagination: Pagination}
     */
    public function listMessages(User $user, array $filters = [], ?Pagination $pagination = null): array;

    public function getMessage(User $user, string $messageId): Message;

    public function sendMessage(User $user, string $to, string $subject, string $body, array $attachments = []): Message;

    public function replyToMessage(User $user, string $messageId, string $body, array $attachments = []): Message;

    /**
     * @return array{messages: Message[], pagination: Pagination}
     */
    public function searchMessages(User $user, string $query, ?Pagination $pagination = null): array;

    public function deleteMessage(User $user, string $messageId): bool;
}
