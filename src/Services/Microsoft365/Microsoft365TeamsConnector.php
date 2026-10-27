<?php

namespace Platform\UserConnectors\Services\Microsoft365;

use Illuminate\Support\Facades\Log;
use Platform\Core\Models\User;
use Platform\UserConnectors\Contracts\PresenceConnector;
use Platform\UserConnectors\DTOs\Pagination;
use Platform\UserConnectors\Exceptions\Microsoft365ApiException;

class Microsoft365TeamsConnector implements PresenceConnector
{
    /** Graph-Maximum für $top bei Chat-/Channel-Nachrichten. */
    public const MAX_MESSAGES_PER_PAGE = 50;

    public function __construct(
        protected Microsoft365ApiService $api,
    ) {}

    /**
     * Channels über alle (oder ein) Team(s). last_message_at kostet einen
     * Graph-Call pro Channel und ist deshalb opt-in.
     */
    public function listChannels(User $user, ?string $teamId = null, bool $includeLastMessage = false): array
    {
        $teams = $teamId !== null && $teamId !== ''
            ? [['id' => $teamId, 'name' => null]]
            : $this->listJoinedTeams($user)['teams'];

        $channels = [];
        foreach ($teams as $team) {
            $channelsData = $this->api->get($user, '/teams/' . Microsoft365ApiService::segment($team['id']) . '/channels', [
                '$select' => 'id,displayName,description,membershipType',
            ]);

            foreach ($channelsData['value'] ?? [] as $ch) {
                $channel = [
                    // Kombinierte ID — direkt für channel.send/messages.list verwendbar.
                    'id' => $team['id'] . ':' . $ch['id'],
                    'channel_id' => $ch['id'],
                    'team_id' => $team['id'],
                    'team_name' => $team['name'],
                    'name' => $ch['displayName'] ?? '',
                    'description' => $ch['description'] ?? null,
                    'membership_type' => $ch['membershipType'] ?? 'standard',
                ];
                if ($includeLastMessage) {
                    $channel['last_message_at'] = $this->fetchLastMessageAt($user, $team['id'], $ch['id']);
                }
                $channels[] = $channel;
            }
        }

        return ['teams' => $teamId ? [] : $teams, 'channels' => $channels];
    }

    /**
     * Graph liefert für Channels keinen Lesestatus — als Aktivitäts-Proxy
     * dient der Zeitstempel der jüngsten Nachricht.
     */
    protected function fetchLastMessageAt(User $user, string $teamId, string $channelId): ?string
    {
        try {
            $data = $this->api->get($user, $this->channelPath($teamId, $channelId) . '/messages', ['$top' => 1]);
            $latest = $data['value'][0] ?? null;

            return $latest['lastModifiedDateTime'] ?? $latest['createdDateTime'] ?? null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Options: cursor, thread_id (Antworten eines Threads statt Root-Posts),
     * body_format (text|html), include_system.
     */
    public function getChannelMessages(User $user, string $channelId, ?Pagination $pagination = null, array $options = []): array
    {
        [$teamId, $chId] = $this->parseChannelId($channelId);
        $path = $this->channelPath($teamId, $chId) . '/messages';
        if (!empty($options['thread_id'])) {
            $path .= '/' . Microsoft365ApiService::segment((string) $options['thread_id']) . '/replies';
        }

        return $this->fetchMessages($user, $path, $pagination, $options);
    }

    /**
     * Sendet in einen Channel; mit $replyToMessageId als Antwort in den Thread.
     * contentType: auto (Default — Plain Text mit Umbrüchen wird zu HTML), html, text.
     */
    public function sendChannelMessage(User $user, string $channelId, string $body, string $contentType = 'auto', ?string $replyToMessageId = null): array
    {
        [$teamId, $chId] = $this->parseChannelId($channelId);
        $path = $this->channelPath($teamId, $chId) . '/messages';
        if ($replyToMessageId !== null && $replyToMessageId !== '') {
            $path .= '/' . Microsoft365ApiService::segment($replyToMessageId) . '/replies';
        }

        $data = $this->api->post($user, $path, ['body' => $this->bodyPayload($body, $contentType)]);

        return ['id' => $data['id'] ?? '', 'status' => 'sent', 'web_url' => $data['webUrl'] ?? null];
    }

    public function replyToChannelMessage(User $user, string $channelId, string $messageId, string $body, string $contentType = 'auto'): array
    {
        return $this->sendChannelMessage($user, $channelId, $body, $contentType, $messageId);
    }

    /**
     * Chats, zuletzt aktive zuerst — exakt nach Graph-Doku (chat-list):
     * $orderby nur lastMessagePreview/createdDateTime desc, $expand nur EIN
     * Property (members ODER lastMessagePreview), $select nicht unterstützt.
     * Mitglieder kommen daher per JSON-Batch (max. 20 je Batch) nach.
     * Ungelesen = lastMessagePreview.createdDateTime > viewpoint.lastMessageReadDateTime.
     *
     * Options: limit (Default 20), search, unread_only, include_members (Default true),
     * include_hidden, cursor.
     */
    public function listChats(User $user, array $options = []): array
    {
        $limit = max(1, min(50, (int) ($options['limit'] ?? 20)));
        $sortError = null;

        if (!empty($options['cursor'])) {
            $data = $this->api->getNextPage($user, (string) $options['cursor']);
        } else {
            try {
                $data = $this->api->get($user, '/me/chats', [
                    '$top' => $limit,
                    '$expand' => 'lastMessagePreview',
                    '$orderby' => 'lastMessagePreview/createdDateTime desc',
                ]);
            } catch (Microsoft365ApiException $e) {
                if ($e->getCode() !== 400) {
                    throw $e;
                }
                // Letzter Ausweg: unsortiert, aber sichtbar gemeldet.
                $sortError = $e->getMessage();
                Log::warning('Microsoft365: /me/chats ohne Sortierung abgerufen', ['error' => $sortError]);
                $data = $this->api->get($user, '/me/chats', ['$top' => $limit, '$expand' => 'members']);
            }
        }

        $rows = $data['value'] ?? [];
        if (empty($options['include_hidden'])) {
            $rows = array_values(array_filter($rows, fn ($c) => empty($c['viewpoint']['isHidden'])));
        }

        if (($options['include_members'] ?? true) && $rows !== [] && !array_key_exists('members', $rows[0])) {
            $members = $this->fetchMembers($user, array_column($rows, 'id'));
            foreach ($rows as &$row) {
                $row['members'] = $members[$row['id']] ?? null;
            }
            unset($row);
        }

        $chats = array_map(function (array $c) {
            $last = $c['lastMessagePreview'] ?? null;
            $lastAt = $last['createdDateTime'] ?? null;
            $readAt = $c['viewpoint']['lastMessageReadDateTime'] ?? null;

            return array_filter([
                'id' => $c['id'] ?? '',
                'topic' => $c['topic'] ?? null,
                'chat_type' => $c['chatType'] ?? 'oneOnOne',
                'unread' => $lastAt && $readAt !== null ? strcmp($lastAt, $readAt) > 0 : null,
                'last_message' => $last ? array_filter([
                    'at' => $lastAt,
                    'from' => $last['from']['user']['displayName'] ?? $last['from']['application']['displayName'] ?? null,
                    'preview' => ($last['messageType'] ?? 'message') === 'message'
                        ? mb_substr(self::htmlToText((string) ($last['body']['content'] ?? '')), 0, 200)
                        : null,
                ], fn ($v) => $v !== null && $v !== '') : null,
                'last_updated' => $c['lastUpdatedDateTime'] ?? null,
                'members' => isset($c['members']) ? array_map(fn (array $m) => [
                    'name' => $m['displayName'] ?? null,
                    'email' => $m['email'] ?? null,
                    'user_id' => $m['userId'] ?? null,
                ], $c['members']) : null,
                'web_url' => $c['webUrl'] ?? null,
            ], fn ($v) => $v !== null);
        }, $rows);

        if (!empty($options['unread_only'])) {
            $chats = array_values(array_filter($chats, fn ($c) => !empty($c['unread'])));
        }

        $search = mb_strtolower(trim((string) ($options['search'] ?? '')));
        if ($search !== '') {
            $chats = array_values(array_filter($chats, function (array $c) use ($search) {
                $haystack = mb_strtolower(($c['topic'] ?? '') . ' ' . ($c['last_message']['from'] ?? '') . ' ' . implode(' ', array_map(
                    fn ($m) => ($m['name'] ?? '') . ' ' . ($m['email'] ?? ''),
                    $c['members'] ?? [],
                )));

                return str_contains($haystack, $search);
            }));
        }

        if ($sortError) {
            $activity = fn (array $c) => $c['last_message']['at'] ?? $c['last_updated'] ?? '';
            usort($chats, fn ($a, $b) => strcmp($activity($b), $activity($a)));
        }

        $result = ['chats' => $chats, 'next_cursor' => $data['@odata.nextLink'] ?? null];
        if ($sortError) {
            $result['sort_warning'] = 'Graph lehnte die Sortierung nach letzter Aktivität ab — Reihenfolge nur innerhalb dieser Seite. (' . $sortError . ')';
        }

        return $result;
    }

    /**
     * Mitglieder mehrerer Chats per JSON-Batch (Graph: max. 20 Requests je Batch).
     *
     * @param array<int, string> $chatIds
     * @return array<string, array> chatId => members
     */
    protected function fetchMembers(User $user, array $chatIds): array
    {
        $members = [];
        foreach (array_chunk(array_values($chatIds), 20) as $chunk) {
            try {
                $response = $this->api->post($user, '/$batch', ['requests' => array_map(fn ($id, $i) => [
                    'id' => (string) $i,
                    'method' => 'GET',
                    'url' => '/chats/' . Microsoft365ApiService::segment($id) . '/members',
                ], $chunk, array_keys($chunk))]);
            } catch (\Throwable $e) {
                Log::warning('Microsoft365: Chat-Mitglieder per Batch fehlgeschlagen', ['error' => $e->getMessage()]);
                continue;
            }
            foreach ($response['responses'] ?? [] as $r) {
                $chatId = $chunk[(int) ($r['id'] ?? -1)] ?? null;
                if ($chatId !== null && ($r['status'] ?? 0) === 200) {
                    $members[$chatId] = $r['body']['value'] ?? [];
                }
            }
        }

        return $members;
    }

    /**
     * Neuer Chat (1:1 oder Gruppe). Der angemeldete User wird ergänzt —
     * Graph fügt den Aufrufer nicht automatisch hinzu. Für 1:1 liefert Graph
     * einen bestehenden Chat zurück, statt einen zweiten anzulegen.
     */
    public function createChat(User $user, array $memberUserIds, ?string $topic = null): array
    {
        $memberUserIds = array_values(array_filter(array_unique(array_map(
            fn ($id) => trim((string) $id),
            $memberUserIds,
        ))));
        if (empty($memberUserIds)) {
            throw new \InvalidArgumentException('Mindestens ein Mitglied ist erforderlich.');
        }

        $me = $this->api->get($user, '/me', ['$select' => 'id']);
        $myId = (string) ($me['id'] ?? '');
        if ($myId === '') {
            throw new \RuntimeException('Graph-ID des angemeldeten Users nicht ermittelbar.');
        }
        $allIds = array_values(array_unique(array_merge([$myId], $memberUserIds)));

        $chatType = count($allIds) > 2 ? 'group' : 'oneOnOne';

        $payload = [
            'chatType' => $chatType,
            'members' => array_map(fn ($id) => [
                '@odata.type' => '#microsoft.graph.aadUserConversationMember',
                'roles' => ['owner'],
                'user@odata.bind' => "https://graph.microsoft.com/v1.0/users('" . str_replace("'", "''", $id) . "')",
            ], $allIds),
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
            'web_url' => $data['webUrl'] ?? null,
        ];
    }

    /**
     * E-Mail-Adressen → Graph-User-IDs. Erst /users/{email} (Mail = UPN),
     * dann $filter auf mail/UPN (abweichende Primäradresse).
     *
     * @param array<int, string> $emails
     * @return array{resolved: array<string, string>, unresolved: array<int, array{email: string, reason: string}>}
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

            try {
                $data = $this->api->get($user, '/users/' . rawurlencode($email), ['$select' => 'id,mail,userPrincipalName']);
                if (!empty($data['id'])) {
                    $resolved[$email] = (string) $data['id'];
                    continue;
                }
            } catch (\Throwable) {
                // weiter mit Strategie 2
            }

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
                    'reason' => 'Kein User mit dieser Mail/UPN — falsche Domain, externer Account oder fehlende User.Read.All-Berechtigung.',
                ];
            } catch (\Throwable $e) {
                $unresolved[] = ['email' => $email, 'reason' => 'Lookup fehlgeschlagen: ' . $e->getMessage()];
            }
        }

        return ['resolved' => $resolved, 'unresolved' => $unresolved];
    }

    /**
     * contentType: html (Default, Bestandsverhalten), text, auto.
     */
    public function sendChatMessage(User $user, string $chatId, string $body, string $contentType = 'html'): array
    {
        $data = $this->api->post($user, '/chats/' . Microsoft365ApiService::segment($chatId) . '/messages', [
            'body' => $this->bodyPayload($body, $contentType),
        ]);

        return ['id' => $data['id'] ?? '', 'status' => 'sent', 'web_url' => $data['webUrl'] ?? null];
    }

    /**
     * Options: cursor, body_format (text|html), include_system.
     */
    public function getChatMessages(User $user, string $chatId, ?Pagination $pagination = null, array $options = []): array
    {
        return $this->fetchMessages($user, '/chats/' . Microsoft365ApiService::segment($chatId) . '/messages', $pagination, $options);
    }

    /**
     * Teams hat keinen nativen Forward — Quote-and-Send in den Ziel-Chat.
     */
    public function forwardChatMessage(User $user, string $sourceChatId, string $sourceMessageId, string $targetChatId, string $comment = ''): array
    {
        $original = $this->api->get(
            $user,
            '/chats/' . Microsoft365ApiService::segment($sourceChatId) . '/messages/' . Microsoft365ApiService::segment($sourceMessageId),
        );

        return $this->sendChatMessage($user, $targetChatId, $this->quote($original, $comment), 'html');
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
     * Channel-Forward: Quote-and-Send in einen (anderen) Channel.
     */
    public function forwardChannelMessage(User $user, string $sourceChannelId, string $sourceMessageId, string $targetChannelId, string $comment = ''): array
    {
        [$srcTeamId, $srcChId] = $this->parseChannelId($sourceChannelId);
        $original = $this->api->get(
            $user,
            $this->channelPath($srcTeamId, $srcChId) . '/messages/' . Microsoft365ApiService::segment($sourceMessageId),
        );

        return $this->sendChannelMessage($user, $targetChannelId, $this->quote($original, $comment), 'html');
    }

    /**
     * HTML → lesbarer Text (für Agenten: spart Tokens, keine <p>/<div>-Suppe).
     */
    public static function htmlToText(string $html): string
    {
        $text = preg_replace('/<(br|\/p|\/div|\/li|\/h\d)\s*\/?>/i', "\n", $html);
        $text = preg_replace('/<li[^>]*>/i', '- ', $text);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t\x{00A0}]+/u", ' ', $text);
        $text = preg_replace("/\n\s*\n\s*\n+/", "\n\n", $text);

        return trim($text);
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    protected function fetchMessages(User $user, string $path, ?Pagination $pagination, array $options): array
    {
        $top = max(1, min(self::MAX_MESSAGES_PER_PAGE, $pagination?->perPage ?? 25));

        $data = !empty($options['cursor'])
            ? $this->api->getNextPage($user, (string) $options['cursor'])
            : $this->api->get($user, $path, ['$top' => $top]);

        $bodyFormat = ($options['body_format'] ?? 'text') === 'html' ? 'html' : 'text';
        $includeSystem = (bool) ($options['include_system'] ?? false);

        $messages = [];
        foreach ($data['value'] ?? [] as $m) {
            $type = $m['messageType'] ?? 'message';
            if (!$includeSystem && $type !== 'message') {
                continue;
            }
            if (!empty($m['deletedDateTime'])) {
                continue;
            }
            $messages[] = $this->mapMessage($m, $bodyFormat);
        }

        return [
            'messages' => $messages,
            'pagination' => new Pagination(
                page: $pagination?->page ?? 1,
                perPage: $top,
                nextLink: $data['@odata.nextLink'] ?? null,
            ),
            'next_cursor' => $data['@odata.nextLink'] ?? null,
        ];
    }

    protected function mapMessage(array $m, string $bodyFormat): array
    {
        $content = (string) ($m['body']['content'] ?? '');
        $isHtml = strtolower($m['body']['contentType'] ?? 'text') === 'html';

        return array_filter([
            'id' => $m['id'] ?? '',
            'reply_to_id' => $m['replyToId'] ?? null,
            'from' => $m['from']['user']['displayName'] ?? $m['from']['application']['displayName'] ?? ($m['from']['user']['id'] ?? 'unknown'),
            'from_id' => $m['from']['user']['id'] ?? null,
            'created_at' => $m['createdDateTime'] ?? null,
            'edited_at' => $m['lastEditedDateTime'] ?? null,
            'subject' => $m['subject'] ?? null,
            'body' => $bodyFormat === 'text' && $isHtml ? self::htmlToText($content) : $content,
            'body_type' => $bodyFormat === 'text' ? 'text' : ($isHtml ? 'html' : 'text'),
            'message_type' => ($m['messageType'] ?? 'message') !== 'message' ? $m['messageType'] : null,
            'importance' => ($m['importance'] ?? 'normal') !== 'normal' ? $m['importance'] : null,
            'attachments' => array_values(array_filter(array_map(
                fn ($a) => $a['name'] ?? null ? ['name' => $a['name'], 'url' => $a['contentUrl'] ?? null] : null,
                $m['attachments'] ?? [],
            ))) ?: null,
            'web_url' => $m['webUrl'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');
    }

    protected function bodyPayload(string $body, string $contentType): array
    {
        $contentType = strtolower(trim($contentType));
        if ($contentType === 'text') {
            return ['contentType' => 'text', 'content' => $body];
        }

        return [
            'contentType' => 'html',
            'content' => $contentType === 'html' ? $body : Microsoft365MailConnector::normalizeBody($body),
        ];
    }

    protected function quote(array $original, string $comment): string
    {
        $from = $original['from']['user']['displayName'] ?? 'unbekannt';
        $at = $original['createdDateTime'] ?? '';

        return ($comment !== '' ? '<div>' . Microsoft365MailConnector::normalizeBody($comment) . '</div>' : '')
            . '<blockquote style="border-left:3px solid #888;padding-left:8px;color:#555;">'
            . '<div><em>Weitergeleitet: ' . htmlspecialchars($from) . ' (' . htmlspecialchars($at) . ')</em></div>'
            . ($original['body']['content'] ?? '')
            . '</blockquote>';
    }

    protected function channelPath(string $teamId, string $channelId): string
    {
        return '/teams/' . Microsoft365ApiService::segment($teamId) . '/channels/' . Microsoft365ApiService::segment($channelId);
    }

    /**
     * Kombinierte ID "teamId:channelId" → [teamId, channelId]. Team-IDs sind
     * GUIDs ohne Doppelpunkt, Channel-IDs ("19:…@thread.tacv2") enthalten einen.
     */
    protected function parseChannelId(string $channelId): array
    {
        $parts = explode(':', trim($channelId), 2);

        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw new \InvalidArgumentException('channel_id muss im Format "teamId:channelId" angegeben werden (siehe teams.channels.list → id).');
        }

        return $parts;
    }
}
