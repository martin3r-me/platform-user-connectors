<?php

namespace Platform\UserConnectors\Contracts;

use Platform\Core\Models\User;
use Platform\UserConnectors\DTOs\Pagination;

interface PresenceConnector
{
    /**
     * List teams/channels the user is a member of.
     *
     * @return array{channels: array}
     */
    public function listChannels(User $user): array;

    /**
     * @return array{messages: array, pagination: Pagination}
     */
    public function getChannelMessages(User $user, string $channelId, ?Pagination $pagination = null): array;

    /**
     * @return array{id: string, status: string}
     */
    public function sendChannelMessage(User $user, string $channelId, string $body): array;

    /**
     * List 1:1 and group chats.
     *
     * @return array{chats: array}
     */
    public function listChats(User $user): array;

    /**
     * @return array{id: string, status: string}
     */
    public function sendChatMessage(User $user, string $chatId, string $body): array;
}
