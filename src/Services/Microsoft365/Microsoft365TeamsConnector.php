<?php

namespace Platform\UserConnectors\Services\Microsoft365;

use Platform\Core\Models\User;
use Platform\UserConnectors\Contracts\PresenceConnector;
use Platform\UserConnectors\DTOs\Pagination;

class Microsoft365TeamsConnector implements PresenceConnector
{
    public function __construct(
        protected Microsoft365ApiService $api,
    ) {}

    public function listChannels(User $user): array
    {
        // Get teams the user is member of, then channels per team
        $teamsData = $this->api->get($user, '/me/joinedTeams', [
            '$select' => 'id,displayName,description',
        ]);

        $channels = [];
        foreach ($teamsData['value'] ?? [] as $team) {
            $teamId = $team['id'];
            $channelsData = $this->api->get($user, "/teams/{$teamId}/channels", [
                '$select' => 'id,displayName,description,membershipType',
            ]);

            foreach ($channelsData['value'] ?? [] as $ch) {
                $channels[] = [
                    'id' => $ch['id'],
                    'team_id' => $teamId,
                    'team_name' => $team['displayName'] ?? '',
                    'name' => $ch['displayName'] ?? '',
                    'description' => $ch['description'] ?? null,
                    'membership_type' => $ch['membershipType'] ?? 'standard',
                ];
            }
        }

        return ['channels' => $channels];
    }

    public function getChannelMessages(User $user, string $channelId, ?Pagination $pagination = null): array
    {
        // channelId format: "teamId:channelId"
        [$teamId, $chId] = $this->parseChannelId($channelId);

        $top = $pagination?->perPage ?? 25;

        $data = $this->api->get($user, "/teams/{$teamId}/channels/{$chId}/messages", [
            '$top' => $top,
        ]);

        $messages = array_map(fn (array $m) => [
            'id' => $m['id'] ?? '',
            'from' => $m['from']['user']['displayName'] ?? ($m['from']['user']['id'] ?? 'unknown'),
            'body' => $m['body']['content'] ?? '',
            'body_type' => strtolower($m['body']['contentType'] ?? 'text'),
            'created_at' => $m['createdDateTime'] ?? null,
        ], $data['value'] ?? []);

        return [
            'messages' => $messages,
            'pagination' => new Pagination(
                page: $pagination?->page ?? 1,
                perPage: $top,
            ),
        ];
    }

    public function sendChannelMessage(User $user, string $channelId, string $body): array
    {
        [$teamId, $chId] = $this->parseChannelId($channelId);

        $data = $this->api->post($user, "/teams/{$teamId}/channels/{$chId}/messages", [
            'body' => [
                'contentType' => 'html',
                'content' => $body,
            ],
        ]);

        return [
            'id' => $data['id'] ?? '',
            'status' => 'sent',
        ];
    }

    public function listChats(User $user): array
    {
        $data = $this->api->get($user, '/me/chats', [
            '$top' => 50,
            '$select' => 'id,topic,chatType,lastUpdatedDateTime',
            '$expand' => 'members($select=displayName,email)',
            '$orderby' => 'lastUpdatedDateTime desc',
        ]);

        $chats = array_map(fn (array $c) => [
            'id' => $c['id'] ?? '',
            'topic' => $c['topic'] ?? null,
            'chat_type' => $c['chatType'] ?? 'oneOnOne',
            'last_updated' => $c['lastUpdatedDateTime'] ?? null,
            'members' => array_map(fn (array $m) => [
                'name' => $m['displayName'] ?? null,
                'email' => $m['email'] ?? null,
            ], $c['members'] ?? []),
        ], $data['value'] ?? []);

        return ['chats' => $chats];
    }

    public function sendChatMessage(User $user, string $chatId, string $body, string $contentType = 'html'): array
    {
        $data = $this->api->post($user, "/chats/{$chatId}/messages", [
            'body' => [
                'contentType' => in_array($contentType, ['html', 'text'], true) ? $contentType : 'html',
                'content' => $body,
            ],
        ]);

        return [
            'id' => $data['id'] ?? '',
            'status' => 'sent',
        ];
    }

    /**
     * Read messages from a 1:1 or group chat.
     */
    public function getChatMessages(User $user, string $chatId, ?Pagination $pagination = null): array
    {
        $top = $pagination?->perPage ?? 25;

        $data = $this->api->get($user, "/chats/{$chatId}/messages", [
            '$top' => $top,
        ]);

        $messages = array_map(fn (array $m) => [
            'id' => $m['id'] ?? '',
            'from' => $m['from']['user']['displayName'] ?? ($m['from']['user']['id'] ?? 'unknown'),
            'body' => $m['body']['content'] ?? '',
            'body_type' => strtolower($m['body']['contentType'] ?? 'text'),
            'created_at' => $m['createdDateTime'] ?? null,
        ], $data['value'] ?? []);

        return [
            'messages' => $messages,
            'pagination' => new Pagination(
                page: $pagination?->page ?? 1,
                perPage: $top,
            ),
        ];
    }

    /**
     * Teams hat keinen nativen Chat-Forward — wir simulieren ihn als
     * Quote-and-Send: Original-Inhalt als Blockquote oben, Kommentar
     * darunter, dann normal in den Ziel-Chat senden.
     */
    public function forwardChatMessage(User $user, string $sourceChatId, string $sourceMessageId, string $targetChatId, string $comment = ''): array
    {
        $original = $this->api->get($user, "/chats/{$sourceChatId}/messages/{$sourceMessageId}", []);
        $originalBody = $original['body']['content'] ?? '';
        $originalFrom = $original['from']['user']['displayName'] ?? 'unbekannt';
        $originalAt = $original['createdDateTime'] ?? '';

        $html = '<div>' . nl2br(htmlspecialchars($comment)) . '</div>'
            . '<blockquote style="border-left:3px solid #888;padding-left:8px;color:#555;">'
            . '<div><em>Weitergeleitet: ' . htmlspecialchars($originalFrom) . ' (' . htmlspecialchars($originalAt) . ')</em></div>'
            . $originalBody
            . '</blockquote>';

        return $this->sendChatMessage($user, $targetChatId, $html, 'html');
    }

    public function listJoinedTeams(User $user): array
    {
        $data = $this->api->get($user, '/me/joinedTeams', [
            '$select' => 'id,displayName,description,isArchived',
        ]);

        return ['teams' => array_map(fn (array $t) => [
            'id' => $t['id'] ?? '',
            'name' => $t['displayName'] ?? '',
            'description' => $t['description'] ?? null,
            'is_archived' => (bool) ($t['isArchived'] ?? false),
        ], $data['value'] ?? [])];
    }

    /**
     * Antwort in einen bestehenden Channel-Thread (reply).
     */
    public function replyToChannelMessage(User $user, string $channelId, string $messageId, string $body, string $contentType = 'html'): array
    {
        [$teamId, $chId] = $this->parseChannelId($channelId);

        $data = $this->api->post($user, "/teams/{$teamId}/channels/{$chId}/messages/{$messageId}/replies", [
            'body' => [
                'contentType' => in_array($contentType, ['html', 'text'], true) ? $contentType : 'html',
                'content' => $body,
            ],
        ]);

        return [
            'id' => $data['id'] ?? '',
            'status' => 'sent',
        ];
    }

    /**
     * Channel-Forward analog zu Chat-Forward: Quote+Send in einen
     * (anderen) Channel.
     */
    public function forwardChannelMessage(User $user, string $sourceChannelId, string $sourceMessageId, string $targetChannelId, string $comment = ''): array
    {
        [$srcTeamId, $srcChId] = $this->parseChannelId($sourceChannelId);
        $original = $this->api->get($user, "/teams/{$srcTeamId}/channels/{$srcChId}/messages/{$sourceMessageId}", []);
        $originalBody = $original['body']['content'] ?? '';
        $originalFrom = $original['from']['user']['displayName'] ?? 'unbekannt';
        $originalAt = $original['createdDateTime'] ?? '';

        $html = '<div>' . nl2br(htmlspecialchars($comment)) . '</div>'
            . '<blockquote style="border-left:3px solid #888;padding-left:8px;color:#555;">'
            . '<div><em>Weitergeleitet: ' . htmlspecialchars($originalFrom) . ' (' . htmlspecialchars($originalAt) . ')</em></div>'
            . $originalBody
            . '</blockquote>';

        return $this->sendChannelMessage($user, $targetChannelId, $html);
    }

    /**
     * Parse combined channelId "teamId:channelId" into parts.
     */
    protected function parseChannelId(string $channelId): array
    {
        $parts = explode(':', $channelId, 2);

        if (count($parts) !== 2) {
            throw new \InvalidArgumentException(
                'Channel-ID muss im Format "teamId:channelId" angegeben werden.'
            );
        }

        return $parts;
    }
}
