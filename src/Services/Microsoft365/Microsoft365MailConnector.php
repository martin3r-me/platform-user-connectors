<?php

namespace Platform\UserConnectors\Services\Microsoft365;

use Carbon\Carbon;
use Platform\Core\Models\User;
use Platform\UserConnectors\Contracts\MessageConnector;
use Platform\UserConnectors\DTOs\Message;
use Platform\UserConnectors\DTOs\Pagination;

class Microsoft365MailConnector implements MessageConnector
{
    /** Seiten, die bei $search (kein $skip möglich) per nextLink übersprungen werden dürfen. */
    private const MAX_SEARCH_PAGE = 10;

    private const BASE_SELECT = 'id,subject,from,toRecipients,ccRecipients,receivedDateTime,isRead,conversationId,hasAttachments,webLink';

    public function __construct(
        protected Microsoft365ApiService $api,
    ) {}

    /**
     * Filters: folder, search, from, date_from, date_to, is_read, has_attachments,
     * body_format (none|preview|text|html; "full" = html), cursor.
     *
     * Graph-Eigenheiten, die hier abgefangen werden:
     *   - $filter + $orderby: receivedDateTime muss als erste Filter-Bedingung
     *     stehen, sonst "The restriction or sort order is too complex".
     *   - $search verträgt weder $filter noch $skip → Absender/Zeitraum wandern
     *     als KQL in die Suche, Blättern läuft über nextLink.
     */
    public function listMessages(User $user, array $filters = [], ?Pagination $pagination = null): array
    {
        $top = max(1, min(100, $pagination?->perPage ?? 25));
        $page = max(1, $pagination?->page ?? 1);
        $bodyFormat = $this->bodyFormat($filters['body_format'] ?? 'full');
        $headers = $this->headersFor($bodyFormat);
        $notes = [];

        if (!empty($filters['cursor'])) {
            $data = $this->api->getNextPage($user, (string) $filters['cursor'], $headers);
        } else {
            $query = [
                '$top' => $top,
                '$select' => $this->selectFor($bodyFormat),
                // Attachment-Metadaten (ohne contentBytes) für das Content-Tool.
                '$expand' => 'attachments($select=id,name,contentType,size,isInline)',
            ];

            $search = trim((string) ($filters['search'] ?? ''));
            if ($search !== '') {
                $query['$search'] = '"' . $this->buildKql($search, $filters) . '"';
                if ($this->hasValue($filters, 'is_read')) {
                    $notes[] = 'is_read wird bei search seitenweise nachgefiltert — eine Seite kann daher weniger Treffer enthalten.';
                }
            } else {
                $query['$filter'] = implode(' and ', $this->buildFilter($filters));
                $query['$orderby'] = 'receivedDateTime desc';
                $query['$count'] = 'true';
                if ($page > 1) {
                    $query['$skip'] = ($page - 1) * $top;
                }
            }

            $data = $this->api->get($user, $this->messagesPath($filters['folder'] ?? null), $query, $headers);

            // $search kennt kein $skip — Seiten per nextLink überspringen.
            if ($search !== '' && $page > 1) {
                if ($page > self::MAX_SEARCH_PAGE) {
                    throw new \InvalidArgumentException('Bei search bitte cursor (next_cursor der vorherigen Antwort) statt page > ' . self::MAX_SEARCH_PAGE . ' verwenden.');
                }
                for ($i = 1; $i < $page && !empty($data['@odata.nextLink']); $i++) {
                    $data = $this->api->getNextPage($user, $data['@odata.nextLink'], $headers);
                }
                if ($i < $page) {
                    $data = ['value' => []];
                }
            }
        }

        $rows = $data['value'] ?? [];
        if ($this->hasValue($filters, 'is_read') && !empty($filters['search'])) {
            $wanted = filter_var($filters['is_read'], FILTER_VALIDATE_BOOLEAN);
            $rows = array_values(array_filter($rows, fn ($m) => (bool) ($m['isRead'] ?? false) === $wanted));
        }

        return [
            'messages' => array_map(fn (array $m) => $this->mapMessage($m, $bodyFormat), $rows),
            'pagination' => new Pagination(
                page: $page,
                perPage: $top,
                total: isset($data['@odata.count']) ? (int) $data['@odata.count'] : null,
                nextLink: $data['@odata.nextLink'] ?? null,
            ),
            'notes' => $notes,
        ];
    }

    public function getMessage(User $user, string $messageId, string $bodyFormat = 'html'): Message
    {
        $bodyFormat = $this->bodyFormat($bodyFormat);
        $data = $this->api->get($user, '/me/messages/' . Microsoft365ApiService::segment($messageId), [
            '$select' => $this->selectFor($bodyFormat === 'none' ? 'preview' : $bodyFormat) . ',sender,bccRecipients,replyTo,importance,sentDateTime,isDraft',
            '$expand' => 'attachments($select=id,name,contentType,size,isInline)',
        ], $this->headersFor($bodyFormat));

        return $this->mapMessage($data, $bodyFormat === 'none' ? 'preview' : $bodyFormat);
    }

    /**
     * Body für Graph (contentType HTML) aufbereiten. bodyType 'html' = unverändert,
     * 'text' = escapen + Zeilenumbrüche als <br>; null/auto = Text, sofern keine
     * HTML-Tags erkennbar sind (HTML ignoriert sonst \n und zieht alles zusammen, #934).
     */
    public static function normalizeBody(string $body, ?string $bodyType = null): string
    {
        $type = $bodyType !== null ? strtolower(trim($bodyType)) : 'auto';
        if ($type === 'auto' || $type === '') {
            $type = preg_match('/<\/?[a-z][a-z0-9]*(\s[^>]*)?>/i', $body) ? 'html' : 'text';
        }
        if ($type === 'html') {
            return $body;
        }

        return nl2br(htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false);
    }

    /**
     * Adressen aus String ("a@x.de, B <b@y.de>; c@z.de") oder Array.
     *
     * @return array<int, string>
     */
    public static function parseAddresses(string|array|null $value): array
    {
        if ($value === null || $value === '' || $value === []) {
            return [];
        }
        $parts = is_array($value) ? $value : preg_split('/[,;]/', $value);

        $addresses = [];
        foreach ($parts as $part) {
            $part = trim((string) $part);
            if ($part === '') {
                continue;
            }
            if (preg_match('/<([^>]+)>/', $part, $m)) {
                $part = trim($m[1]);
            }
            if (!filter_var($part, FILTER_VALIDATE_EMAIL)) {
                throw new \InvalidArgumentException("Ungültige E-Mail-Adresse: \"{$part}\".");
            }
            $addresses[strtolower($part)] = $part;
        }

        return array_values($addresses);
    }

    /**
     * Options: cc, bcc, reply_to, importance (low|normal|high), save_as_draft.
     * $to: String (komma-/semikolongetrennt) oder Array.
     */
    public function sendMessage(User $user, string|array $to, string $subject, string $body, array $attachments = [], ?string $bodyType = null, array $options = []): Message
    {
        $toList = self::parseAddresses($to);
        if ($toList === []) {
            throw new \InvalidArgumentException('Mindestens ein Empfänger (to) ist erforderlich.');
        }
        $ccList = self::parseAddresses($options['cc'] ?? null);
        $bccList = self::parseAddresses($options['bcc'] ?? null);

        $message = [
            'subject' => $subject,
            'body' => [
                'contentType' => 'HTML',
                'content' => self::normalizeBody($body, $bodyType),
            ],
            'toRecipients' => $this->recipients($toList),
        ];
        if ($ccList) {
            $message['ccRecipients'] = $this->recipients($ccList);
        }
        if ($bccList) {
            $message['bccRecipients'] = $this->recipients($bccList);
        }
        if ($replyTo = self::parseAddresses($options['reply_to'] ?? null)) {
            $message['replyTo'] = $this->recipients($replyTo);
        }
        if (in_array($options['importance'] ?? null, ['low', 'normal', 'high'], true)) {
            $message['importance'] = $options['importance'];
        }
        if (!empty($attachments)) {
            $message['attachments'] = $this->mapAttachmentsForSend($attachments);
        }

        // Entwurf: landet im Drafts-Ordner, ID + webLink zum Öffnen in Outlook.
        if (!empty($options['save_as_draft'])) {
            $data = $this->api->post($user, '/me/messages', $message);

            return new Message(
                id: (string) ($data['id'] ?? ''),
                provider: 'microsoft365',
                from: 'me',
                to: $toList,
                subject: $subject,
                body: $body,
                bodyType: 'html',
                date: now(),
                isRead: true,
                threadId: $data['conversationId'] ?? null,
                attachments: [],
                raw: [],
                cc: $ccList,
                webLink: $data['webLink'] ?? null,
            );
        }

        $data = $this->api->post($user, '/me/sendMail', [
            'message' => $message,
            'saveToSentItems' => true,
        ]);

        // sendMail antwortet mit 202 ohne Body — minimaler Stub.
        return new Message(
            id: 'sent-' . now()->timestamp,
            provider: 'microsoft365',
            from: 'me',
            to: $toList,
            subject: $subject,
            body: $body,
            bodyType: 'html',
            date: now(),
            isRead: true,
            threadId: null,
            attachments: [],
            raw: $data ?: [],
            cc: $ccList,
        );
    }

    /**
     * Antwort über Graph. Default replyAll — alle ursprünglichen Empfänger
     * bleiben dran; replyAll=false antwortet nur dem Absender.
     *
     * Options: to (zusätzliche Empfänger), cc, bcc, exclude (Adressen entfernen),
     * importance, save_as_draft. Mit Options oder Anhängen läuft die Antwort
     * über einen Entwurf (createReply → ergänzen → senden), weil /reply
     * übergebene Empfängerlisten ersetzt statt ergänzt.
     */
    public function replyToMessage(User $user, string $messageId, string $body, array $attachments = [], bool $replyAll = true, ?string $bodyType = null, array $options = []): Message
    {
        $endpoint = $replyAll ? 'replyAll' : 'reply';
        $path = '/me/messages/' . Microsoft365ApiService::segment($messageId);
        $comment = self::normalizeBody($body, $bodyType);

        if ($attachments === [] && !$this->hasComposeOptions($options)) {
            $this->api->post($user, "{$path}/{$endpoint}", ['comment' => $comment]);

            // reply / replyAll antworten mit 202 — minimaler Stub.
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

        $draft = $this->api->post($user, "{$path}/create" . ucfirst($endpoint), ['comment' => $comment]);

        return $this->completeDraft($user, $draft, $attachments, $options, $body);
    }

    /**
     * Weiterleiten über Graphs nativen /forward — Anhänge und Original-Header
     * bleiben erhalten; comment steht in Outlook über der Original-Mail.
     *
     * Options: cc, bcc, importance, save_as_draft. Mit Options oder
     * zusätzlichen Anhängen läuft es über createForward (Entwurf).
     *
     * @param array<int, string>|string $toAddresses
     */
    public function forwardMessage(User $user, string $messageId, array|string $toAddresses, string $comment = '', ?string $bodyType = null, array $attachments = [], array $options = []): Message
    {
        $toList = self::parseAddresses($toAddresses);
        if ($toList === []) {
            throw new \InvalidArgumentException('Mindestens ein Empfänger (to) ist erforderlich.');
        }

        $path = '/me/messages/' . Microsoft365ApiService::segment($messageId);
        $payload = [
            'comment' => self::normalizeBody($comment, $bodyType),
            'toRecipients' => $this->recipients($toList),
        ];

        if ($attachments === [] && !$this->hasComposeOptions($options)) {
            $this->api->post($user, "{$path}/forward", $payload);

            return new Message(
                id: 'forward-' . now()->timestamp,
                provider: 'microsoft365',
                from: 'me',
                to: $toList,
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

        $draft = $this->api->post($user, "{$path}/createForward", $payload);

        return $this->completeDraft($user, $draft, $attachments, $options, $comment);
    }

    /**
     * Bestehenden Entwurf ändern. Changes: subject, body (+ body_type),
     * to/cc/bcc (ersetzen die Liste), importance; Anhänge werden ergänzt.
     */
    public function updateDraft(User $user, string $draftId, array $changes, array $attachments = []): Message
    {
        $path = '/me/messages/' . Microsoft365ApiService::segment($draftId);
        $patch = [];
        if (isset($changes['subject'])) {
            $patch['subject'] = (string) $changes['subject'];
        }
        if (isset($changes['body'])) {
            $patch['body'] = ['contentType' => 'HTML', 'content' => self::normalizeBody((string) $changes['body'], $changes['body_type'] ?? null)];
        }
        foreach (['to' => 'toRecipients', 'cc' => 'ccRecipients', 'bcc' => 'bccRecipients'] as $key => $field) {
            if (array_key_exists($key, $changes) && $changes[$key] !== null) {
                $patch[$field] = $this->recipients(self::parseAddresses($changes[$key]));
            }
        }
        if (in_array($changes['importance'] ?? null, ['low', 'normal', 'high'], true)) {
            $patch['importance'] = $changes['importance'];
        }
        if ($patch === [] && $attachments === []) {
            throw new \InvalidArgumentException('Keine Änderungen am Entwurf übergeben.');
        }

        $data = $patch !== [] ? $this->api->patch($user, $path, $patch) : $this->api->get($user, $path, ['$select' => self::BASE_SELECT]);
        $this->addAttachments($user, $draftId, $attachments);

        return $this->mapMessage($data, 'none');
    }

    /**
     * Entwurf senden. Nur Mails im Drafts-Ordner (isDraft=true).
     */
    public function sendDraft(User $user, string $draftId): void
    {
        $this->api->post($user, '/me/messages/' . Microsoft365ApiService::segment($draftId) . '/send');
    }

    /**
     * Entwurf ergänzen (Empfänger, Anhänge) und — sofern nicht save_as_draft —
     * senden. Graph hat die Empfänger für Antworten bereits vorbelegt; hier
     * wird ergänzt bzw. per exclude entfernt, nicht ersetzt.
     */
    protected function completeDraft(User $user, array $draft, array $attachments, array $options, string $body): Message
    {
        $draftId = (string) ($draft['id'] ?? '');
        if ($draftId === '') {
            throw new \RuntimeException('Graph hat keinen Entwurf angelegt.');
        }

        $current = fn (string $field) => array_map(
            fn (array $r) => (string) ($r['emailAddress']['address'] ?? ''),
            $draft[$field] ?? [],
        );
        $exclude = array_map('strtolower', self::parseAddresses($options['exclude'] ?? null));
        $merge = function (array $existing, array $added) use ($exclude): array {
            $out = [];
            foreach (array_merge($existing, $added) as $addr) {
                if ($addr !== '' && !in_array(strtolower($addr), $exclude, true)) {
                    $out[strtolower($addr)] = $addr;
                }
            }

            return array_values($out);
        };

        $to = $merge($current('toRecipients'), self::parseAddresses($options['to'] ?? null));
        $cc = $merge($current('ccRecipients'), self::parseAddresses($options['cc'] ?? null));
        $bcc = $merge($current('bccRecipients'), self::parseAddresses($options['bcc'] ?? null));
        if ($to === [] && $cc === [] && $bcc === []) {
            throw new \InvalidArgumentException('Nach exclude bleibt kein Empfänger übrig.');
        }

        $patch = [
            'toRecipients' => $this->recipients($to),
            'ccRecipients' => $this->recipients($cc),
            'bccRecipients' => $this->recipients($bcc),
        ];
        if (in_array($options['importance'] ?? null, ['low', 'normal', 'high'], true)) {
            $patch['importance'] = $options['importance'];
        }

        $path = '/me/messages/' . Microsoft365ApiService::segment($draftId);
        $this->api->patch($user, $path, $patch);
        $this->addAttachments($user, $draftId, $attachments);

        $isDraft = !empty($options['save_as_draft']);
        if (!$isDraft) {
            $this->sendDraft($user, $draftId);
        }

        return new Message(
            id: $isDraft ? $draftId : 'sent-' . now()->timestamp,
            provider: 'microsoft365',
            from: 'me',
            to: $to,
            subject: $draft['subject'] ?? null,
            body: $body,
            bodyType: 'html',
            date: now(),
            isRead: true,
            threadId: $draft['conversationId'] ?? null,
            attachments: [],
            raw: ['bcc' => $bcc],
            cc: $cc,
            webLink: $isDraft ? ($draft['webLink'] ?? null) : null,
        );
    }

    protected function addAttachments(User $user, string $messageId, array $attachments): void
    {
        foreach ($this->mapAttachmentsForSend($attachments) as $attachment) {
            $this->api->post($user, '/me/messages/' . Microsoft365ApiService::segment($messageId) . '/attachments', $attachment);
        }
    }

    protected function hasComposeOptions(array $options): bool
    {
        foreach (['to', 'cc', 'bcc', 'exclude', 'importance', 'save_as_draft'] as $key) {
            if (!empty($options[$key])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Inhalt eines Anhangs. fileAttachments bringen contentBytes (Base64) direkt mit.
     */
    public function getAttachmentContent(User $user, string $messageId, string $attachmentId): array
    {
        $data = $this->api->get(
            $user,
            '/me/messages/' . Microsoft365ApiService::segment($messageId) . '/attachments/' . Microsoft365ApiService::segment($attachmentId),
        );

        $type = $data['@odata.type'] ?? '';
        if ($type !== '' && $type !== '#microsoft.graph.fileAttachment') {
            throw new \InvalidArgumentException('Anhang ist keine Datei (' . ltrim($type, '#') . ') — z. B. eine eingebettete Mail oder ein Link; kein Base64-Inhalt verfügbar.');
        }

        return [
            'id' => $data['id'] ?? $attachmentId,
            'name' => $data['name'] ?? null,
            'content_type' => $data['contentType'] ?? null,
            'size' => $data['size'] ?? null,
            'is_inline' => $data['isInline'] ?? false,
            'content_base64' => $data['contentBytes'] ?? null,
        ];
    }

    public function searchMessages(User $user, string $query, ?Pagination $pagination = null): array
    {
        return $this->listMessages($user, ['search' => $query], $pagination);
    }

    public function deleteMessage(User $user, string $messageId): bool
    {
        return $this->api->delete($user, '/me/messages/' . Microsoft365ApiService::segment($messageId));
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    protected function buildFilter(array $filters): array
    {
        $tz = Microsoft365EventTime::defaultTimezone();

        // receivedDateTime MUSS vorne stehen (wegen $orderby) — notfalls als No-Op.
        $parts = [
            'receivedDateTime ge ' . (!empty($filters['date_from'])
                ? $this->utc((string) $filters['date_from'], $tz)
                : '1900-01-01T00:00:00Z'),
        ];
        if (!empty($filters['date_to'])) {
            $parts[] = 'receivedDateTime le ' . $this->utc((string) $filters['date_to'], $tz, true);
        }
        if ($this->hasValue($filters, 'is_read')) {
            $parts[] = 'isRead eq ' . (filter_var($filters['is_read'], FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false');
        }
        if ($this->hasValue($filters, 'has_attachments')) {
            $parts[] = 'hasAttachments eq ' . (filter_var($filters['has_attachments'], FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false');
        }
        if (!empty($filters['from'])) {
            $parts[] = "from/emailAddress/address eq '" . str_replace("'", "''", trim((string) $filters['from'])) . "'";
        }

        return $parts;
    }

    /**
     * Suchbegriff + Filter als KQL (from:, received>=, hasattachment:).
     */
    protected function buildKql(string $search, array $filters): string
    {
        $tz = Microsoft365EventTime::defaultTimezone();
        $parts = [str_replace('"', '\\"', $search)];
        if (!empty($filters['from'])) {
            $parts[] = 'from:' . preg_replace('/\s+/', '', (string) $filters['from']);
        }
        if (!empty($filters['date_from'])) {
            $parts[] = 'received>=' . Microsoft365EventTime::parse((string) $filters['date_from'], $tz)->format('Y-m-d');
        }
        if (!empty($filters['date_to'])) {
            $parts[] = 'received<=' . Microsoft365EventTime::parse((string) $filters['date_to'], $tz)->format('Y-m-d');
        }
        if ($this->hasValue($filters, 'has_attachments')) {
            $parts[] = 'hasattachment:' . (filter_var($filters['has_attachments'], FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false');
        }

        return implode(' ', $parts);
    }

    /**
     * Datum/Zeitpunkt → UTC-Literal für $filter. Ein reines Datum als date_to
     * meint "bis Ende dieses Tages".
     */
    protected function utc(string $value, string $tz, bool $endOfDay = false): string
    {
        $time = Microsoft365EventTime::parse($value, $tz);
        if ($endOfDay && Microsoft365EventTime::isDateOnly($value)) {
            $time = $time->copy()->endOfDay();
        }

        return $time->copy()->utc()->format('Y-m-d\TH:i:s\Z');
    }

    protected function messagesPath(?string $folder): string
    {
        $folder = trim((string) $folder);
        if ($folder === '') {
            return '/me/messages';
        }
        $wellKnown = [
            'inbox' => 'inbox', 'posteingang' => 'inbox',
            'sentitems' => 'sentitems', 'sent' => 'sentitems', 'gesendet' => 'sentitems',
            'drafts' => 'drafts', 'entwürfe' => 'drafts', 'entwuerfe' => 'drafts',
            'deleteditems' => 'deleteditems', 'deleted' => 'deleteditems', 'papierkorb' => 'deleteditems',
            'junkemail' => 'junkemail', 'junk' => 'junkemail', 'spam' => 'junkemail',
            'archive' => 'archive', 'archiv' => 'archive',
        ];
        $folder = $wellKnown[mb_strtolower($folder)] ?? $folder;

        return '/me/mailFolders/' . Microsoft365ApiService::segment($folder) . '/messages';
    }

    protected function bodyFormat(?string $format): string
    {
        return match (strtolower((string) $format)) {
            'none' => 'none',
            'preview' => 'preview',
            'text' => 'text',
            default => 'html',
        };
    }

    protected function selectFor(string $bodyFormat): string
    {
        return self::BASE_SELECT . match ($bodyFormat) {
            'none' => '',
            'preview' => ',bodyPreview',
            default => ',bodyPreview,body',
        };
    }

    protected function headersFor(string $bodyFormat): array
    {
        return $bodyFormat === 'text' ? ['Prefer' => 'outlook.body-content-type="text"'] : [];
    }

    protected function hasValue(array $filters, string $key): bool
    {
        return array_key_exists($key, $filters) && $filters[$key] !== null && $filters[$key] !== '';
    }

    protected function recipients(array $addresses): array
    {
        return array_map(fn (string $addr) => ['emailAddress' => ['address' => $addr]], array_values($addresses));
    }

    protected function mapMessage(array $data, string $bodyFormat = 'html'): Message
    {
        $from = $data['from']['emailAddress']['address'] ?? ($data['from']['emailAddress']['name'] ?? 'unknown');
        $addresses = fn (array $list) => array_values(array_filter(array_map(
            fn (array $r) => $r['emailAddress']['address'] ?? $r['emailAddress']['name'] ?? '',
            $list,
        )));

        $body = match ($bodyFormat) {
            'none' => '',
            'preview' => $data['bodyPreview'] ?? '',
            default => $data['body']['content'] ?? $data['bodyPreview'] ?? '',
        };
        $bodyType = match ($bodyFormat) {
            'none' => 'none',
            'preview' => 'text',
            default => strtolower($data['body']['contentType'] ?? 'text') === 'html' ? 'html' : 'text',
        };

        $attachments = array_map(fn (array $att) => [
            'id' => $att['id'] ?? null,
            'name' => $att['name'] ?? null,
            'content_type' => $att['contentType'] ?? null,
            'size' => $att['size'] ?? null,
            'is_inline' => $att['isInline'] ?? false,
        ], $data['attachments'] ?? []);

        return new Message(
            id: $data['id'] ?? '',
            provider: 'microsoft365',
            from: $from,
            to: $addresses($data['toRecipients'] ?? []),
            subject: $data['subject'] ?? null,
            body: (string) $body,
            bodyType: $bodyType,
            date: Carbon::parse($data['receivedDateTime'] ?? now()),
            isRead: $data['isRead'] ?? false,
            threadId: $data['conversationId'] ?? null,
            attachments: $attachments,
            raw: $data,
            cc: $addresses($data['ccRecipients'] ?? []),
            fromName: $data['from']['emailAddress']['name'] ?? null,
            webLink: $data['webLink'] ?? null,
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
