<?php

namespace Platform\UserConnectors\Tools\Telephony;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolResult;
use Platform\UserConnectors\DTOs\Pagination;

class CallLogTool extends TelephonyTool
{
    public function getName(): string
    {
        return 'user-connectors.telephony.calls.log';
    }

    public function getDescription(): string
    {
        return 'Anrufprotokoll live beim Anbieter (Sipgate, RingCentral, Vodafone). Filter: direction, type, Zeitraum, phonenumber (Sipgate). '
            . 'Lokal gespeicherte Anrufe inkl. Status/Dauer: user-connectors.call-sessions.list.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => self::connectionProperties() + [
                'direction' => ['type' => 'string', 'enum' => ['inbound', 'outbound']],
                'type' => ['type' => 'string', 'enum' => ['voice', 'fax', 'sms', 'voicemail'], 'description' => 'sms/voicemail nur bei Sipgate.'],
                'date_from' => ['type' => 'string', 'description' => '2026-11-03 oder ISO-8601 (Plattform-Zeitzone).'],
                'date_to' => ['type' => 'string', 'description' => 'Reines Datum = einschließlich.'],
                'phonenumber' => ['type' => 'string', 'description' => 'Nur Sipgate: nach Nummer filtern.'],
                'page' => ['type' => 'integer'],
                'per_page' => ['type' => 'integer', 'description' => '1–100. Default 25.'],
            ],
        ];
    }

    protected function handle(array $arguments, ToolContext $context): ToolResult
    {
        $connection = $this->connection($context->user, $arguments);
        [$from, $to] = $this->dateRange($arguments);
        // Auch die Schreibweisen der alten Anbieter-Tools (Inbound, Incoming, CALL …) akzeptieren.
        $direction = match (strtolower((string) ($arguments['direction'] ?? ''))) {
            'inbound', 'incoming', 'eingehend' => 'inbound',
            'outbound', 'outgoing', 'ausgehend' => 'outbound',
            '' => null,
            default => throw new \InvalidArgumentException('direction muss inbound oder outbound sein.'),
        };
        $type = match (strtolower((string) ($arguments['type'] ?? ''))) {
            'voice', 'call' => 'voice',
            '' => null,
            default => strtolower((string) $arguments['type']),
        };

        $filters = $this->provider($connection) === 'sipgate'
            ? array_filter([
                'direction' => match ($direction) { 'inbound' => 'incoming', 'outbound' => 'outgoing', default => null },
                'type' => match ($type) { 'voice' => 'CALL', 'fax' => 'FAX', 'sms' => 'SMS', 'voicemail' => 'VOICEMAIL', default => null },
                'from' => $from?->toIso8601String(),
                'to' => $to?->toIso8601String(),
                'phonenumber' => $arguments['phonenumber'] ?? null,
            ])
            : array_filter([
                'direction' => match ($direction) { 'inbound' => 'Inbound', 'outbound' => 'Outbound', default => null },
                'type' => match ($type) { 'voice' => 'Voice', 'fax' => 'Fax', null => null, default => throw new \InvalidArgumentException("type {$type} gibt es nur bei Sipgate.") },
                'dateFrom' => $from?->format('Y-m-d\TH:i:s.v\Z'),
                'dateTo' => $to?->format('Y-m-d\TH:i:s.v\Z'),
            ]);

        $result = $this->calls($connection)->getCallLog($context->user, $filters, new Pagination(
            page: max(1, (int) ($arguments['page'] ?? 1)),
            perPage: $this->limit($arguments),
        ));

        return ToolResult::success($this->connectionInfo($connection) + [
            'count' => count($result['calls']),
            'calls' => array_map(function ($c) {
                $data = $c->toArray();
                unset($data['provider']);

                return $data;
            }, $result['calls']),
            'pagination' => $result['pagination']->toArray(),
        ]);
    }

    public function getMetadata(): array
    {
        return self::readMetadata(['calls', 'call_log', 'history', 'channel:call']);
    }
}
