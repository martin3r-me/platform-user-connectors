<?php

namespace Platform\UserConnectors\Tools\Microsoft365;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\UserConnectors\Exceptions\Microsoft365ApiException;
use Platform\UserConnectors\Services\Microsoft365\Microsoft365ApiService;
use Platform\UserConnectors\Services\Microsoft365\Microsoft365CalendarConnector;
use Platform\UserConnectors\Services\Microsoft365\Microsoft365MailConnector;
use Platform\UserConnectors\Services\Microsoft365\Microsoft365TeamsConnector;

/**
 * Gemeinsame Basis der Microsoft-365-Tools: Auth-Check, Connection-Auswahl
 * (connection_id), einheitliche Fehler-Codes und Schema-Bausteine.
 */
abstract class Microsoft365Tool implements ToolContract, ToolMetadataContract
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
        } catch (Microsoft365ApiException $e) {
            $status = $e->getCode();
            $code = match (true) {
                $e->getErrorCode() === 'RATE_LIMITED' => 'RATE_LIMITED',
                $e->getErrorCode() === 'CONNECTION_ERROR' => 'NO_CONNECTION',
                $status === 401 => 'UNAUTHORIZED',
                $status === 403 => 'FORBIDDEN',
                $status === 404 => 'NOT_FOUND',
                default => 'GRAPH_ERROR',
            };
            $hint = match ($code) {
                'UNAUTHORIZED' => ' Verbindung bitte in "Meine Connectoren" neu autorisieren.',
                'FORBIDDEN' => ' Fehlende Berechtigung (Scope) oder kein Zugriff auf dieses Objekt.',
                'NOT_FOUND' => ' ID prüfen — sie stammt evtl. aus einer anderen Connection oder wurde gelöscht/verschoben.',
                default => '',
            };

            return ToolResult::error($this->errorPrefix() . ': ' . $e->getMessage() . $hint, $code);
        } catch (\Throwable $e) {
            return ToolResult::error($this->errorPrefix() . ': ' . $e->getMessage(), 'EXECUTION_ERROR');
        }
    }

    protected function api(array $arguments): Microsoft365ApiService
    {
        $connectionId = $arguments['connection_id'] ?? null;

        return app(Microsoft365ApiService::class)->forConnection(
            $connectionId !== null && $connectionId !== '' ? (int) $connectionId : null
        );
    }

    protected function mail(array $arguments): Microsoft365MailConnector
    {
        return new Microsoft365MailConnector($this->api($arguments));
    }

    protected function calendar(array $arguments): Microsoft365CalendarConnector
    {
        return new Microsoft365CalendarConnector($this->api($arguments));
    }

    protected function teams(array $arguments): Microsoft365TeamsConnector
    {
        return new Microsoft365TeamsConnector($this->api($arguments));
    }

    protected function requireString(array $arguments, string $key): string
    {
        $value = trim((string) ($arguments[$key] ?? ''));
        if ($value === '') {
            throw new \InvalidArgumentException("{$key} ist erforderlich.");
        }

        return $value;
    }

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
            'description' => 'Optional. IANA-Zeitzone (z. B. Europe/Berlin). Default: '
                . "Plattform-Zeitzone. Zeitangaben ohne Offset werden in dieser Zone gelesen, Ausgaben erfolgen in ihr.",
        ];
    }

    protected static function cursorProperty(): array
    {
        return [
            'type' => 'string',
            'description' => 'Optional. next_cursor aus der vorherigen Antwort, um die nächste Seite zu laden (andere Filter werden dann ignoriert).',
        ];
    }

    protected static function writeMetadata(array $tags, array $extra = []): array
    {
        return array_merge([
            'category' => 'action',
            'tags' => array_merge(['microsoft365'], $tags),
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
            'tags' => array_merge(['microsoft365'], $tags),
            'read_only' => true,
            'requires_auth' => true,
            'risk_level' => 'safe',
            'idempotent' => true,
            'cost_class' => 'external_api_free',
        ], $extra);
    }
}
