<?php

namespace Platform\UserConnectors\Tools;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Models\User;
use Platform\UserConnectors\Exceptions\ConnectorException;
use Platform\UserConnectors\Models\UserConnectorConnection;
use Platform\UserConnectors\Services\ConnectionResolver;
use Platform\UserConnectors\Services\Microsoft365\Microsoft365EventTime;

/**
 * Gemeinsame Basis aller user-connectors-Tools: Auth-Check, einheitliche
 * Fehler-Codes, Connection-Zugriff (eigene + geteilte) und Argument-Helfer.
 */
abstract class ConnectorTool implements ToolContract, ToolMetadataContract
{
    abstract protected function handle(array $arguments, ToolContext $context): ToolResult;

    /** Präfix für Fehlermeldungen ("Senden fehlgeschlagen: …"). */
    protected function errorPrefix(): string
    {
        return 'Fehler';
    }

    final public function execute(array $arguments, ToolContext $context): ToolResult
    {
        if (!$context->user) {
            return ToolResult::error('Benutzer nicht authentifiziert.', 'AUTH_ERROR');
        }

        try {
            return $this->handle($arguments, $context);
        } catch (\InvalidArgumentException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        } catch (ConnectorException $e) {
            $code = $this->errorCodeFor($e);

            return ToolResult::error($this->errorPrefix() . ': ' . $e->getMessage() . $this->errorHint($code), $code);
        } catch (\Throwable $e) {
            report($e);

            return ToolResult::error($this->errorPrefix() . ': ' . $e->getMessage(), 'EXECUTION_ERROR');
        }
    }

    protected function errorCodeFor(ConnectorException $e): string
    {
        $status = $e->getCode();

        return match (true) {
            $e->getErrorCode() === 'RATE_LIMITED' || $status === 429 => 'RATE_LIMITED',
            in_array($e->getErrorCode(), ['CONNECTION_ERROR', 'NO_CONNECTION'], true) => 'NO_CONNECTION',
            $status === 401 || $e->getErrorCode() === 'UNAUTHORIZED' => 'UNAUTHORIZED',
            $status === 403 => 'FORBIDDEN',
            $status === 404 => 'NOT_FOUND',
            default => 'API_ERROR',
        };
    }

    protected function errorHint(string $code): string
    {
        return match ($code) {
            'UNAUTHORIZED' => ' Verbindung bitte in "Meine Connectoren" neu autorisieren.',
            'FORBIDDEN' => ' Fehlende Berechtigung (Scope) oder kein Zugriff auf dieses Objekt.',
            'NOT_FOUND' => ' ID prüfen — sie stammt evtl. aus einer anderen Connection oder wurde gelöscht.',
            'NO_CONNECTION' => ' Verfügbare Verbindungen: user-connectors.connections.list.',
            default => '',
        };
    }

    // ── Connections ─────────────────────────────────────────────────────

    /**
     * Zugängliche Connections (eigene + geteilte), optional gefiltert.
     *
     * @param array<int, string> $connectorKeys
     */
    protected function accessibleConnections(User $user, array $connectorKeys = [], bool $activeOnly = false): Collection
    {
        return app(ConnectionResolver::class)->accessibleConnections($user, $connectorKeys, $activeOnly);
    }

    /**
     * Connection-IDs für Session-/Listen-Abfragen aus connection_id,
     * connector_key und include_shared.
     *
     * @param array<int, string> $allowedKeys
     * @return array<int, int>
     */
    protected function scopedConnectionIds(User $user, array $arguments, array $allowedKeys = []): array
    {
        $keys = $allowedKeys;
        if (!empty($arguments['connector_key'])) {
            $key = (string) $arguments['connector_key'];
            if ($allowedKeys !== [] && !in_array($key, $allowedKeys, true)) {
                throw new \InvalidArgumentException("connector_key \"{$key}\" hier nicht möglich — erlaubt: " . implode(', ', $allowedKeys) . '.');
            }
            $keys = [$key];
        }

        $connections = $this->accessibleConnections($user, $keys);
        if (($this->optionalBool($arguments, 'include_shared') ?? true) === false) {
            $connections = $connections->filter(fn (UserConnectorConnection $c) => (int) $c->owner_user_id === (int) $user->id);
        }

        if (!empty($arguments['connection_id'])) {
            $id = (int) $arguments['connection_id'];
            if (!$connections->contains('id', $id)) {
                throw new \InvalidArgumentException("Connection {$id} nicht gefunden oder kein Zugriff (user-connectors.connections.list).");
            }

            return [$id];
        }

        return $connections->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Eine Connection: explizit per connection_id, sonst die erste passende
     * (eigene Default-Verbindung vor geteilten).
     *
     * @param array<int, string> $allowedKeys
     */
    protected function resolveConnection(User $user, array $arguments, array $allowedKeys): UserConnectorConnection
    {
        $keys = !empty($arguments['connector_key']) ? [(string) $arguments['connector_key']] : $allowedKeys;
        if (array_diff($keys, $allowedKeys) !== []) {
            throw new \InvalidArgumentException('connector_key muss einer von ' . implode(', ', $allowedKeys) . ' sein.');
        }

        $connections = $this->accessibleConnections($user, $keys);

        if (!empty($arguments['connection_id'])) {
            $connection = $connections->firstWhere('id', (int) $arguments['connection_id']);
            if (!$connection) {
                throw new \InvalidArgumentException('Connection ' . (int) $arguments['connection_id'] . ' nicht gefunden, kein Zugriff oder falscher Typ (erlaubt: ' . implode(', ', $keys) . ').');
            }

            return $connection;
        }

        $connection = $connections->firstWhere('status', 'active') ?? $connections->first();
        if (!$connection) {
            throw new \InvalidArgumentException('Keine Verbindung vom Typ ' . implode('/', $keys) . ' vorhanden.');
        }

        return $connection;
    }

    // ── Argumente ───────────────────────────────────────────────────────

    protected function requireString(array $arguments, string $key): string
    {
        $value = trim((string) ($arguments[$key] ?? ''));
        if ($value === '') {
            throw new \InvalidArgumentException("{$key} ist erforderlich.");
        }

        return $value;
    }

    /**
     * Bool aus true/false/"true"/"false"/1/0 — (bool) "false" wäre true.
     */
    protected function optionalBool(array $arguments, string $key): ?bool
    {
        if (!array_key_exists($key, $arguments) || $arguments[$key] === null || $arguments[$key] === '') {
            return null;
        }

        return filter_var($arguments[$key], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    /**
     * Liste aus Array oder komma-/semikolongetrenntem String.
     *
     * @return array<int, string>
     */
    protected function stringList(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        $items = is_array($value) ? $value : preg_split('/[,;]/', (string) $value);

        return array_values(array_filter(array_map(fn ($v) => is_scalar($v) ? trim((string) $v) : '', $items), fn ($v) => $v !== ''));
    }

    protected function limit(array $arguments, int $default = 25, int $max = 100): int
    {
        return max(1, min($max, (int) ($arguments['limit'] ?? $arguments['per_page'] ?? $default)));
    }

    protected function offset(array $arguments): int
    {
        return max(0, (int) ($arguments['offset'] ?? 0));
    }

    /**
     * date_from/date_to → UTC-Grenzen. Ohne Offset in der Plattform-Zeitzone;
     * ein reines Datum als date_to schließt den ganzen Tag ein.
     *
     * @return array{0: ?Carbon, 1: ?Carbon}
     */
    protected function dateRange(array $arguments, string $fromKey = 'date_from', string $toKey = 'date_to'): array
    {
        $tz = Microsoft365EventTime::resolveTimezone($arguments['timezone'] ?? null);
        $from = !empty($arguments[$fromKey]) ? Microsoft365EventTime::parse((string) $arguments[$fromKey], $tz)->utc() : null;
        $to = null;
        if (!empty($arguments[$toKey])) {
            $to = Microsoft365EventTime::parse((string) $arguments[$toKey], $tz);
            if (Microsoft365EventTime::isDateOnly((string) $arguments[$toKey])) {
                $to = $to->copy()->endOfDay();
            }
            $to = $to->utc();
        }

        return [$from, $to];
    }

    /**
     * limit/offset auf eine Query anwenden; liefert Liste + total/has_more.
     */
    protected function paginate($query, array $arguments, string $key, callable $map): array
    {
        $limit = $this->limit($arguments);
        $offset = $this->offset($arguments);
        $total = (clone $query)->toBase()->getCountForPagination();
        $rows = $query->offset($offset)->limit($limit)->get();

        return [
            $key => $rows->map($map)->values()->all(),
            'count' => $rows->count(),
            'total' => $total,
            'offset' => $offset,
            'has_more' => $offset + $rows->count() < $total,
        ];
    }

    /**
     * LIKE-Suche über mehrere Spalten (Wildcards im Suchbegriff escaped).
     */
    protected function whereLike($query, array $columns, string $needle): void
    {
        $needle = '%' . addcslashes(trim($needle), '%_\\') . '%';
        $query->where(function ($q) use ($columns, $needle) {
            foreach ($columns as $column) {
                $q->orWhere($column, 'like', $needle);
            }
        });
    }

    // ── Schema-Bausteine ────────────────────────────────────────────────

    protected static function connectionProperty(): array
    {
        return [
            'type' => 'integer',
            'description' => 'Optional. Connection-ID (user-connectors.connections.list). Ohne: eigene Standard-Verbindung, sonst geteilte.',
        ];
    }

    protected static function timezoneProperty(): array
    {
        return [
            'type' => 'string',
            'description' => 'Optional. IANA-Zeitzone (z. B. Europe/Berlin). Default: Plattform-Zeitzone. '
                . 'Zeitangaben ohne Offset werden in dieser Zone gelesen.',
        ];
    }

    /**
     * Standard-Filter für lokale Listen (Sessions, Events).
     */
    protected static function listProperties(array $connectorKeys, string $dateLabel): array
    {
        return [
            'connection_id' => ['type' => 'integer', 'description' => 'Optional. Nur diese Connection.'],
            'connector_key' => ['type' => 'string', 'enum' => $connectorKeys, 'description' => 'Optional. Nur dieser Connector.'],
            'include_shared' => ['type' => 'boolean', 'description' => 'Default true: auch mit dir geteilte Verbindungen.'],
            'date_from' => ['type' => 'string', 'description' => "{$dateLabel} ab (2026-11-03 oder ISO-8601, Plattform-Zeitzone)."],
            'date_to' => ['type' => 'string', 'description' => "{$dateLabel} bis (reines Datum = einschließlich)."],
            'limit' => ['type' => 'integer', 'description' => 'Anzahl (1–100). Default 25.'],
            'offset' => ['type' => 'integer', 'description' => 'Überspringen (Paging). Default 0.'],
        ];
    }

    protected static function writeMetadata(array $tags, array $extra = []): array
    {
        return array_merge([
            'category' => 'action',
            'tags' => array_merge(['user-connectors'], $tags),
            'read_only' => false,
            'requires_auth' => true,
            'risk_level' => 'write',
            'confirmation_required' => true,
            'cost_class' => 'external_api_free',
        ], $extra);
    }

    protected static function readMetadata(array $tags, array $extra = []): array
    {
        return array_merge([
            'category' => 'query',
            'tags' => array_merge(['user-connectors'], $tags),
            'read_only' => true,
            'requires_auth' => true,
            'risk_level' => 'safe',
            'idempotent' => true,
            'cost_class' => 'database',
        ], $extra);
    }
}
