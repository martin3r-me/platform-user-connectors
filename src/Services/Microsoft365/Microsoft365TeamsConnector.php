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
        // Graph's /me/chats does not support $orderby on lastUpdatedDateTime
        // (the only documented sortable field is lastMessagePreview/
        // createdDateTime, which requires extra perms). We fetch without
        // server-side ordering and sort the response client-side — same
        // user-facing result, no permission rabbit hole.
        $data = $this->api->get($user, '/me/chats', [
            '$top' => 50,
            '$select' => 'id,topic,chatType,lastUpdatedDateTime',
            '$expand' => 'members',
        ]);

        $chats = array_map(fn (array $c) => [
            'id' => $c['id'] ?? '',
            'topic' => $c['topic'] ?? null,
            'chat_type' => $c['chatType'] ?? 'oneOnOne',
            'last_updated' => $c['lastUpdatedDateTime'] ?? null,
            'members' => array_map(fn (array $m) => [
                'name' => $m['displayName'] ?? null,
                'user_id' => $m['userId'] ?? null,
            ], $c['members'] ?? []),
        ], $data['value'] ?? []);

        // Newest first by last_updated; null timestamps sink to the end.
        usort($chats, function ($a, $b) {
            $ta = $a['last_updated'] ?? '';
            $tb = $b['last_updated'] ?? '';
            return strcmp($tb, $ta);
        });

        return ['chats' => $chats];
    }

    /**
     * Creates a new Teams chat (1:1 or group). For group chats, pass
     * member_user_ids with 2+ entries; for 1:1, exactly one other user.
     * The signed-in user is implicitly added — caller does not need to
     * include their own userId.
     */
    public function createChat(User $user, array $memberUserIds, ?string $topic = null): array
    {
        $memberUserIds = array_values(array_filter(array_unique(array_map(
            fn ($id) => trim((string) $id),
            $memberUserIds,
        ))));
        if (empty($memberUserIds)) {
            throw new \InvalidArgumentException('At least one member user_id is required.');
        }

        // Resolve the signed-in user's Graph id (we need it as an explicit
        // chat member; Graph does not auto-add the caller).
        $me = $this->api->get($user, '/me', ['$select' => 'id']);
        $myId = (string) ($me['id'] ?? '');
        if ($myId === '') {
            throw new \RuntimeException('Could not resolve the signed-in user\'s Graph id.');
        }
        $allIds = array_values(array_unique(array_merge([$myId], $memberUserIds)));

        $chatType = count($allIds) > 2 ? 'group' : 'oneOnOne';

        $members = array_map(fn ($id) => [
            '@odata.type' => '#microsoft.graph.aadUserConversationMember',
            'roles' => ['owner'],
            'user@odata.bind' => "https://graph.microsoft.com/v1.0/users('{$id}')",
        ], $allIds);

        $payload = [
            'chatType' => $chatType,
            'members' => $members,
        ];
        if ($chatType === 'group' && $topic !== null && trim($topic) !== '') {
            $payload['topic'] = trim($topic);
        }

        $data = $this->api->post($user, '/chats', $payload);

        return [
            'id' => (string) ($data['id'] ?? ''),
            'chat_type' => $data['chatType'] ?? $chatType,
            'topic' => $data['topic'] ?? null,
            'member_count' => count($allIds),
        ];
    }

    /**
     * Resolve one or more email addresses to Graph user ids. Two strategies
     * per email:
     *   1. GET /users/{email} — works when the email equals the UPN
     *   2. GET /users?$filter=mail eq '…' or userPrincipalName eq '…'
     *      — covers the (common) case where the user's primary mail
     *      differs from their UPN
     *
     * Returns an array with two keys so the caller can give the user a
     * clear error per unresolved address instead of silently dropping it:
     *
     *   [
     *     'resolved'   => array<string, string>  email => user_id
     *     'unresolved' => array<int, array{email: string, reason: string}>
     *   ]
     *
     * @param array<int, string> $emails
     */
    public function resolveUserIds(User $user, array $emails): array
    {
        $resolved = [];
        $unresolved = [];

        foreach ($emails as $email) {
            $email = trim((string) $email);
            if ($email === '') {
                continue;
            }

            // Strategy 1: direct lookup (email as UPN).
            try {
                $data = $this->api->get(
                    $user,
                    '/users/' . rawurlencode($email),
                    ['$select' => 'id,mail,userPrincipalName']
                );
                if (!empty($data['id'])) {
                    $resolved[$email] = (string) $data['id'];
                    continue;
                }
            } catch (\Throwable $e) {
                // Fall through to strategy 2.
            }

            // Strategy 2: filter on mail OR UPN (covers BHG-style mismatch
            // between the displayed mail and the AAD UPN).
            $escaped = str_replace("'", "''", $email);
            try {
                $data = $this->api->get($user, '/users', [
                    '$filter' => "mail eq '{$escaped}' or userPrincipalName eq '{$escaped}'",
                    '$select' => 'id,mail,userPrincipalName',
                    '$top' => 1,
                ]);
                $hit = $data['value'][0] ?? null;
                if ($hit && !empty($hit['id'])) {
                    $resolved[$email] = (string) $hit['id'];
                    continue;
                }
                $unresolved[] = [
                    'email' => $email,
                    'reason' => 'No user matched on UPN or mail — wrong domain, external account, or missing User.Read.All scope.',
                ];
            } catch (\Throwable $e) {
                $unresolved[] = [
                    'email' => $email,
                    'reason' => 'Lookup failed: ' . $e->getMessage(),
                ];
            }
        }

        return ['resolved' => $resolved, 'unresolved' => $unresolved];
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
