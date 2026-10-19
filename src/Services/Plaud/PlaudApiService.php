<?php

namespace Platform\UserConnectors\Services\Plaud;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * HTTP-Client für die Plaud Consumer/Third-Party-API.
 *
 * Rekonstruiert aus @plaud-ai/cli (v0.3.15) — Plaud dokumentiert diese API nicht
 * offiziell (nur „Embedded"/Partner). Public OAuth-Client (PKCE, KEIN Secret):
 * der Refresh braucht nur client_id + refresh_token, kein Browser/Callback. Darum
 * reicht ein EINMAL geseedeter refresh_token (aus `plaud login`); dieser Service
 * refresht selbst und ruft die Daten-Endpoints.
 *
 * Bewusst token-basiert (keine Kopplung ans Connection-Model): der Sync-Command
 * holt den refresh_token aus der UserConnectorConnection, ruft hier refresh() und
 * nutzt das access_token für listFiles()/getFile().
 */
class PlaudApiService
{
    protected string $base;
    protected string $refreshUrl;
    protected string $clientId;

    public function __construct(?string $clientId = null, ?string $base = null, ?string $refreshUrl = null)
    {
        $this->base = rtrim($base ?? (string) config('user-connectors.plaud.api_base', 'https://platform.plaud.ai/developer/api'), '/');
        $this->refreshUrl = $refreshUrl ?? (string) config('user-connectors.plaud.refresh_url', 'https://platform.plaud.ai/developer/api/oauth/third-party/access-token/refresh');
        // Öffentliche CLI-client_id als Default (kein Secret nötig).
        $this->clientId = $clientId ?: (string) config('user-connectors.plaud.client_id', 'client_f9e0b214-c11f-434b-8b95-c4497d1feb81');
    }

    /**
     * refresh_token → frisches Token-Set. Wie das CLI: POST form-urlencoded mit
     * Feld `refresh_token`, KEIN Authorization-Header (public client, der Refresh
     * braucht keine Client-Credentials). JSON-Body würde 422 „refresh_token missing"
     * geben — der Endpoint liest nur Form-Felder.
     *
     * @return array{access_token:string, refresh_token:?string, token_type:string, expires_in:?int}
     */
    public function refresh(string $refreshToken): array
    {
        $res = Http::asForm()
            ->acceptJson()
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
     * Aufnahmen auflisten (neueste zuerst), optional nach Datum (YYYY-MM-DD).
     *
     * @return array<int, array<string,mixed>>  die `data`-Liste der Response
     */
    public function listFiles(string $accessToken, ?string $startDate = null, ?string $endDate = null, int $page = 1, int $pageSize = 50): array
    {
        $query = array_filter([
            'start_date' => $startDate,
            'end_date'   => $endDate,
            'page'       => $page,
            'page_size'  => $pageSize,
        ], fn ($v) => $v !== null);

        $res = Http::withToken($accessToken)->acceptJson()
            ->get($this->base . '/open/third-party/files/', $query);

        if ($res->failed()) {
            throw new RuntimeException('Plaud listFiles fehlgeschlagen (HTTP ' . $res->status() . '): ' . $res->body());
        }

        $json = $res->json();

        return $json['data'] ?? (is_array($json) ? $json : []);
    }

    /**
     * Voll-Record einer Aufnahme: Metadaten + source_list (Transkript-Blocks) +
     * note_list (Summary etc.).
     *
     * @return array<string,mixed>
     */
    public function getFile(string $accessToken, string $fileId): array
    {
        $res = Http::withToken($accessToken)->acceptJson()
            ->get($this->base . '/open/third-party/files/' . rawurlencode($fileId));

        if ($res->failed()) {
            throw new RuntimeException('Plaud getFile fehlgeschlagen (HTTP ' . $res->status() . '): ' . $res->body());
        }

        return $res->json() ?? [];
    }

    /**
     * Transkript-Block eines Files als dekodiertes Array (Utterances). `block` =
     * 'transaction' (roh) | 'transaction_polish' | 'outline' | 'mark_memo'.
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
            // data_link zeigt meist auf signiertes Storage (ohne Auth); Token schadet nicht.
            $res = Http::withToken($accessToken)->get($link);

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
