<?php

namespace Platform\UserConnectors\Tools\Microsoft365;

use Carbon\Carbon;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\UserConnectors\Services\Microsoft365\Microsoft365ApiService;
use Platform\UserConnectors\Services\Microsoft365\Microsoft365CalendarConnector;

/**
 * Findet gemeinsame freie Zeitfenster über mehrere Teilnehmer im
 * vorgegebenen Zeitraum. Implementiert das CalendarConnector::findMeetingTimes-
 * Contract — austauschbar gegen Gmail/Calendar/etc. sobald wir die
 * entsprechenden Provider-Connectors haben.
 */
class FindAvailabilityTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string { return 'user-connectors.microsoft365.calendar.find_availability'; }

    public function getDescription(): string
    {
        return 'Sucht gemeinsame freie Zeitslots für eine Gruppe von Teilnehmern in einem Zeitfenster '
            . '(MS Graph findMeetingTimes). Provider-agnostische Antwort-Shape — gleiche Schnittstelle '
            . 'wird später auch von Gmail / anderen Calendar-Providern bedient.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'connection_id' => ['type' => 'integer'],
                'participants' => [
                    'type' => 'array',
                    'description' => 'E-Mail-Adressen der zusätzlichen Teilnehmer (der Organisator-User ist implizit dabei).',
                    'items' => ['type' => 'string'],
                ],
                'after' => ['type' => 'string', 'description' => 'Frühester Startzeitpunkt, ISO-8601.'],
                'before' => ['type' => 'string', 'description' => 'Spätester Endzeitpunkt, ISO-8601.'],
                'duration_minutes' => ['type' => 'integer', 'description' => 'Dauer des gesuchten Slots in Minuten. Default 30.'],
                'max_candidates' => ['type' => 'integer', 'description' => 'Maximale Anzahl Vorschläge. Default 5.'],
            ],
            'required' => ['participants', 'after', 'before'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        if (!$context->user) {
            return ToolResult::error('AUTH_ERROR', 'Benutzer nicht authentifiziert.');
        }

        $participants = array_values(array_filter(array_map(
            fn ($e) => is_string($e) ? trim($e) : null,
            (array) ($arguments['participants'] ?? []),
        )));
        if (empty($participants)) {
            return ToolResult::error('VALIDATION_ERROR', 'participants[] darf nicht leer sein.');
        }

        try {
            $after = Carbon::parse((string) $arguments['after']);
            $before = Carbon::parse((string) $arguments['before']);
        } catch (\Throwable $e) {
            return ToolResult::error('VALIDATION_ERROR', 'after/before müssen gültige ISO-8601-Zeitstempel sein.');
        }

        try {
            $connector = app(Microsoft365CalendarConnector::class);
            if (!empty($arguments['connection_id'])) {
                $connector = new Microsoft365CalendarConnector(
                    app(Microsoft365ApiService::class)->forConnection((int) $arguments['connection_id'])
                );
            }

            $result = $connector->findMeetingTimes(
                $context->user,
                $participants,
                $after,
                $before,
                (int) ($arguments['duration_minutes'] ?? 30),
                (int) ($arguments['max_candidates'] ?? 5),
            );

            // Carbon-Instanzen serialisierbar machen.
            $suggestions = array_map(fn ($s) => [
                'start' => $s['start']->toIso8601String(),
                'end' => $s['end']->toIso8601String(),
                'confidence' => $s['confidence'],
                'reason' => $s['reason'],
                'attendee_availability' => $s['attendee_availability'],
            ], $result['suggestions']);

            return ToolResult::success([
                'count' => count($suggestions),
                'suggestions' => $suggestions,
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Availability-Lookup fehlgeschlagen: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'inspection',
            'tags' => ['microsoft365', 'calendar', 'availability', 'find_meeting_times', 'planning'],
            'read_only' => true,
            'requires_auth' => true,
            'risk_level' => 'read',
            'idempotent' => true,
            'cost_class' => 'external_api_free',
        ];
    }
}
