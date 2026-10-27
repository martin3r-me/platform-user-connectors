<?php

namespace Platform\UserConnectors\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolResult;
use Platform\UserConnectors\Models\UserConnectorCallSession;

class ListCallSessionsTool extends ConnectorTool
{
    private const KEYS = ['sipgate', 'ringcentral', 'vodafone', 'microsoft365'];

    public function getName(): string
    {
        return 'user-connectors.call-sessions.list';
    }

    public function getDescription(): string
    {
        return 'Lokal gespeicherte Telefonate (Sipgate, RingCentral, Vodafone, Teams) — laufende zuerst, dann neueste. '
            . 'Richtung, Nummern, Status (ringing, active, completed, missed, failed), Dauer. Filter u. a. Zeitraum und Nummer.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => self::listProperties(self::KEYS, 'Anrufbeginn') + [
                'status' => ['type' => 'string', 'enum' => ['ringing', 'active', 'completed', 'missed', 'failed']],
                'direction' => ['type' => 'string', 'enum' => ['inbound', 'outbound']],
                'phonenumber' => ['type' => 'string', 'description' => 'Teil einer beteiligten Nummer (von/an).'],
            ],
        ];
    }

    protected function handle(array $arguments, ToolContext $context): ToolResult
    {
        $connectionIds = $this->scopedConnectionIds($context->user, $arguments, self::KEYS);

        $query = UserConnectorCallSession::query()
            ->whereIn('connection_id', $connectionIds)
            ->orderByRaw("CASE WHEN status IN ('ringing', 'active') THEN 0 ELSE 1 END")
            ->orderByDesc('started_at');

        foreach (['status', 'direction'] as $column) {
            if (!empty($arguments[$column])) {
                $query->where($column, (string) $arguments[$column]);
            }
        }
        if (!empty($arguments['phonenumber'])) {
            $this->whereLike($query, ['from_number', 'to_number', 'answering_number'], (string) $arguments['phonenumber']);
        }
        [$from, $to] = $this->dateRange($arguments);
        if ($from) {
            $query->where('started_at', '>=', $from);
        }
        if ($to) {
            $query->where('started_at', '<=', $to);
        }

        return ToolResult::success($this->paginate($query, $arguments, 'call_sessions', fn (UserConnectorCallSession $s) => array_filter([
            'id' => $s->id,
            'connection_id' => $s->connection_id,
            'connector_key' => $s->connector_key,
            'external_call_id' => $s->external_call_id,
            'direction' => $s->direction,
            'status' => $s->status,
            'from_number' => $s->from_number,
            'to_number' => $s->to_number,
            'answering_number' => $s->answering_number,
            'started_at' => $s->started_at?->toIso8601String(),
            'answered_at' => $s->answered_at?->toIso8601String(),
            'ended_at' => $s->ended_at?->toIso8601String(),
            'duration' => $s->durationForHumans(),
            'duration_seconds' => $s->duration_seconds,
            'hangup_cause' => $s->hangup_cause,
            'meta' => $s->meta ?: null,
        ], fn ($v) => $v !== null && $v !== '')));
    }

    public function getMetadata(): array
    {
        return self::readMetadata(['call-sessions', 'calls', 'telephony', 'history', 'sipgate', 'ringcentral', 'microsoft365']);
    }
}
