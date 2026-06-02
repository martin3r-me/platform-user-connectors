<?php

namespace Platform\UserConnectors\Services\RingCentral;

use Carbon\Carbon;
use Platform\Core\Models\User;
use Platform\UserConnectors\Contracts\CallConnector;
use Platform\UserConnectors\DTOs\CallLogEntry;
use Platform\UserConnectors\DTOs\Pagination;

class RingCentralCallConnector implements CallConnector
{
    public function __construct(
        protected RingCentralApiService $api,
    ) {}

    public function getCallLog(User $user, array $filters = [], ?Pagination $pagination = null): array
    {
        $query = [];

        $perPage = $pagination?->perPage ?? 25;
        $page = $pagination?->page ?? 1;
        $query['perPage'] = $perPage;
        $query['page'] = $page;

        if (!empty($filters['dateFrom'])) {
            $query['dateFrom'] = $filters['dateFrom'];
        }
        if (!empty($filters['dateTo'])) {
            $query['dateTo'] = $filters['dateTo'];
        }
        if (!empty($filters['type'])) {
            $query['type'] = $filters['type']; // Voice, Fax
        }
        if (!empty($filters['direction'])) {
            $query['direction'] = $filters['direction']; // Inbound, Outbound
        }

        $data = $this->api->get($user, '/account/~/extension/~/call-log', $query);

        $calls = array_map(
            fn (array $r) => $this->mapCallLogEntry($r),
            $data['records'] ?? []
        );

        $paging = $data['paging'] ?? [];
        $resultPagination = new Pagination(
            page: $paging['page'] ?? $page,
            perPage: $paging['perPage'] ?? $perPage,
            total: $paging['totalElements'] ?? null,
        );

        return ['calls' => $calls, 'pagination' => $resultPagination];
    }

    public function getVoicemails(User $user, ?Pagination $pagination = null): array
    {
        $perPage = $pagination?->perPage ?? 25;
        $page = $pagination?->page ?? 1;

        $data = $this->api->get($user, '/account/~/extension/~/message-store', [
            'messageType' => 'VoiceMail',
            'perPage' => $perPage,
            'page' => $page,
        ]);

        $voicemails = array_map(fn (array $vm) => [
            'id' => $vm['id'] ?? '',
            'from' => $vm['from']['phoneNumber'] ?? ($vm['from']['name'] ?? null),
            'to' => $vm['to'][0]['phoneNumber'] ?? null,
            'created_at' => $vm['creationTime'] ?? null,
            'duration_seconds' => $vm['vmDuration'] ?? null,
            'read_status' => $vm['readStatus'] ?? 'Unread',
            'attachments' => array_map(fn ($a) => [
                'id' => $a['id'] ?? null,
                'type' => $a['contentType'] ?? null,
                'uri' => $a['uri'] ?? null,
            ], $vm['attachments'] ?? []),
        ], $data['records'] ?? []);

        $paging = $data['paging'] ?? [];
        $resultPagination = new Pagination(
            page: $paging['page'] ?? $page,
            perPage: $paging['perPage'] ?? $perPage,
            total: $paging['totalElements'] ?? null,
        );

        return ['voicemails' => $voicemails, 'pagination' => $resultPagination];
    }

    public function sendSMS(User $user, string $to, string $body): array
    {
        $data = $this->api->post($user, '/account/~/extension/~/sms', [
            'from' => ['phoneNumber' => $this->resolveFromNumber($user)],
            'to' => [['phoneNumber' => $to]],
            'text' => $body,
        ]);

        return [
            'id' => (string) ($data['id'] ?? ''),
            'status' => $data['messageStatus'] ?? 'Queued',
        ];
    }

    public function initiateCall(User $user, string $from, string $to): array
    {
        $data = $this->api->post($user, '/account/~/extension/~/ring-out', [
            'from' => ['phoneNumber' => $from],
            'to' => ['phoneNumber' => $to],
            'callerId' => ['phoneNumber' => $from],
            'playPrompt' => true,
        ]);

        return [
            'sessionId' => (string) ($data['id'] ?? ''),
            'status' => $data['status']['callStatus'] ?? 'InProgress',
        ];
    }

    protected function mapCallLogEntry(array $data): CallLogEntry
    {
        $fromNumber = null;
        if (!empty($data['from'])) {
            $fromNumber = $data['from']['phoneNumber'] ?? ($data['from']['name'] ?? null);
        }

        $toNumber = null;
        if (!empty($data['to'])) {
            $toNumber = $data['to']['phoneNumber'] ?? ($data['to']['name'] ?? null);
        }

        return new CallLogEntry(
            id: (string) ($data['id'] ?? ''),
            provider: 'ringcentral',
            direction: strtolower($data['direction'] ?? 'inbound'),
            type: strtolower($data['type'] ?? 'voice'),
            from: $fromNumber,
            to: $toNumber,
            startTime: Carbon::parse($data['startTime'] ?? now()),
            durationSeconds: $data['duration'] ?? null,
            result: strtolower($data['result'] ?? 'unknown'),
            raw: $data,
        );
    }

    /**
     * Resolve default "from" phone number for SMS.
     * Falls back to the first SMS-enabled number on the extension.
     */
    protected function resolveFromNumber(User $user): string
    {
        try {
            $data = $this->api->get($user, '/account/~/extension/~/phone-number', [
                'usageType' => 'DirectNumber',
                'perPage' => 10,
            ]);

            foreach ($data['records'] ?? [] as $number) {
                $features = $number['features'] ?? [];
                if (in_array('SmsSender', $features)) {
                    return $number['phoneNumber'];
                }
            }
        } catch (\Exception $e) {
            // Fall through
        }

        throw new \RuntimeException('Keine SMS-fähige Telefonnummer gefunden.');
    }
}
