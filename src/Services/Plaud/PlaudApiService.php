<?php

namespace Platform\UserConnectors\Services\Plaud;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * HTTP-Client für die Plaud Consumer/Third-Party-API.
 *
 * Rekonstruiert aus @plaud-ai/cli (v0.3.15) + lokal gegen die echte API verifiziert.
 * Plaud dokumentiert diese API nicht offiziell (nur „Embedded"/Partner). Public
 * OAuth-Client (PKCE, KEIN Secret): der Refresh braucht nur den refresh_token (als
 * form-urlencoded, ohne Auth-Header). Ein EINMAL geseedeter refresh_token (aus
 * `plaud login`) reicht; dieser Service refresht selbst und ruft die Daten-Endpoints.
 *
 * WICHTIG: Plaud sitzt hinter Cloudflare mit Browser-Signatur-Prüfung (Error 1010).
 * Default-Clients (python-urllib, GuzzleHttp/x) werden geblockt → wir setzen einen
 * Browser-User-Agent auf JEDEN Request. start_date/end_date sind epoch MILLISEKUNDEN.
 *
 * Token-basiert (keine Connection-Kopplung): der Sync-Command holt den refresh_token
 * aus der UserConnectorConnection, ruft refresh() und nutzt das access_token.
 */
class PlaudApiService
{
    protected string $base;
    protected string $refreshUrl;
    protected string $userAgent;
    protected string $clientId;

    public function __construct(?string $clientId = null, ?string $base = null, ?string $refreshUrl = null)
    {
        $this->base = rtrim($base ?? (string) config('user-connectors.plaud.api_base', 'https://platform.plaud.ai/developer/api'), '/');
        $this->refreshUrl = $refreshUrl ?? (string) config('user-connectors.plaud.refresh_url', 'https://platform.plaud.ai/developer/api/oauth/third-party/access-token/refresh');
        $this->clientId = $clientId ?: (string) config('user-connectors.plaud.client_id', 'client_f9e0b214-c11f-434b-8b95-c4497d1feb81');
        $this->userAgent = (string) config('user-connectors.plaud.user_agent', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.6 Safari/605.1.15');
    }

    /** Basis-Client mit Browser-UA (Cloudflare-Durchlass). */
    protected function http(): PendingRequest
    {
        return Http::withHeaders(['User-Agent' => $this->userAgent]);
    }

    /**
     * refresh_token → frisches Token-Set. Wie das CLI: POST form-urlencoded mit Feld
     * `refresh_token`, KEIN Authorization-Header (public client). JSON-Body würde 422
     * „refresh_token missing" geben.
     *
     * @return array{access_token:string, refresh_token:?string, token_type:string, expires_in:?int}
     */
    public function refresh(string $refreshToken): array
    {
        $res = $this->http()->asForm()->acceptJson()
            ->post($this->refreshUrl, ['refresh_token' => $refreshToken]);

        if ($res->failed()) {
            throw new RuntimeException('Plaud-Token-Refresh fehlgeschlagen (HTTP ' . $res->status() . '): ' . $res->body());
        }

        $data = $res->json();

        return [
            'access_token'  => (string) ($data['access_token'] ?? ''),
            'refresh_token' => $data['refresh_token'] ?? $refreshToken,
            'token_type'    => (string) ($data['token_type'] ?? 'Bearer'),
            'expires_in'    => isset($data['expires_in']) ? (int) $data['expires_in'] : null,
        ];
    }

    /**
     * Aufnahmen auflisten (neueste zuerst). start_date/end_date sind epoch MILLISEKUNDEN
     * (inklusive, optional).
     *
     * @return array<int, array<string,mixed>>  die `data`-Liste der Response
     */
    public function listFiles(string $accessToken, ?int $startMs = null, ?int $endMs = null, int $page = 1, int $pageSize = 50): array
    {
        $query = array_filter([
            'start_date' => $startMs,
            'end_date'   => $endMs,
            'page'       => $page,
            'page_size'  => $pageSize,
        ], fn ($v) => $v !== null);

        $res = $this->http()->withToken($accessToken)->acceptJson()
            ->get($this->base . '/open/third-party/files/', $query);

        if ($res->failed()) {
            throw new RuntimeException('Plaud listFiles fehlgeschlagen (HTTP ' . $res->status() . '): ' . $res->body());
        }

        $json = $res->json();

        return $json['data'] ?? (is_array($json) ? $json : []);
    }

    /**
     * Voll-Record einer Aufnahme: Metadaten + source_list (Transkript-Blocks) +
     * note_list (Summary etc.). Felder: id, name, start_at, duration (ms),
     * serial_number, presigned_url, source_list, note_list.
     *
     * @return array<string,mixed>
     */
    public function getFile(string $accessToken, string $fileId): array
    {
        $res = $this->http()->withToken($accessToken)->acceptJson()
            ->get($this->base . '/open/third-party/files/' . rawurlencode($fileId));

        if ($res->failed()) {
            throw new RuntimeException('Plaud getFile fehlgeschlagen (HTTP ' . $res->status() . '): ' . $res->body());
        }

        return $res->json() ?? [];
    }

    /**
     * Transkript-Block als dekodiertes Array (Utterances: content, start_time/end_time
     * in ms, speaker, original_speaker — 1:1 das inbox.plaud.sync-Segment-Format).
     * block = 'transaction' (roh, inline) | 'transaction_polish' | 'outline' | 'mark_memo'.
     *
     * @param  array<string,mixed>  $file  Ergebnis von getFile()
     * @return array<int, array<string,mixed>>
     */
    public function transcript(string $accessToken, array $file, string $block = 'transaction'): array
    {
        $source = $this->findBlock($file['source_list'] ?? [], $block);
        if (! $source) {
            return [];
        }

        $content = $this->loadBlockContent($accessToken, $source);
        if ($content === '') {
            return [];
        }

        $decoded = json_decode($content, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * AI-Summary als Markdown (note data_type = auto_sum_note), oder null.
     *
     * @param  array<string,mixed>  $file  Ergebnis von getFile()
     */
    public function summary(string $accessToken, array $file): ?string
    {
        $note = $this->findBlock($file['note_list'] ?? [], 'auto_sum_note');
        if (! $note) {
            return null;
        }

        $content = $this->loadBlockContent($accessToken, $note);

        return $content !== '' ? $content : null;
    }

    /**
     * Block-Inhalt laden: inline (data_content) bevorzugt, sonst von data_link holen.
     *
     * @param  array<string,mixed>  $block
     */
    protected function loadBlockContent(string $accessToken, array $block): string
    {
        $inline = $block['data_content'] ?? null;
        if (is_string($inline) && $inline !== '') {
            return $inline;
        }

        $link = $block['data_link'] ?? null;
        if (is_string($link) && $link !== '') {
            $res = $this->http()->withToken($accessToken)->get($link);

            return $res->successful() ? (string) $res->body() : '';
        }

        return '';
    }

    /**
     * Ersten Block mit passendem data_type aus einer Liste (source_list/note_list).
     *
     * @param  array<int, array<string,mixed>>  $list
     * @return array<string,mixed>|null
     */
    protected function findBlock(array $list, string $dataType): ?array
    {
        foreach ($list as $block) {
            if (is_array($block) && ($block['data_type'] ?? null) === $dataType) {
                return $block;
            }
        }

        return null;
    }
}
