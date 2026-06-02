<?php

namespace Platform\UserConnectors\Services\Sipgate;

use Carbon\Carbon;
use Platform\Core\Models\User;
use Platform\UserConnectors\Contracts\CallConnector;
use Platform\UserConnectors\DTOs\CallLogEntry;
use Platform\UserConnectors\DTOs\Pagination;

class SipgateCallConnector implements CallConnector
{
    public function __construct(
        protected SipgateApiService $api,
    ) {}

    public function getCallLog(User $user, array $filters = [], ?Pagination $pagination = null): array
    {
        $perPage = $pagination?->perPage ?? 25;
        $page = $pagination?->page ?? 1;
        $offset = ($page - 1) * $perPage;

        $apiFilters = [
            'limit' => $perPage,
            'offset' => $offset,
        ];

        if (!empty($filters['direction'])) {
            $apiFilters['directions'] = ucfirst(strtolower($filters['direction']));
        }
        if (!empty($filters['type'])) {
            $apiFilters['types'] = $filters['type'];
        }
        if (!empty($filters['from'])) {
            $apiFilters['from'] = $filters['from'];
        }
        if (!empty($filters['to'])) {
            $apiFilters['to'] = $filters['to'];
        }
        if (!empty($filters['phonenumber'])) {
            $apiFilters['phonenumber'] = $filters['phonenumber'];
        }

        $data = $this->api->getHistory($user, $apiFilters);

        $calls = array_map(
            fn (array $entry) => $this->mapCallLogEntry($entry),
            $data['items'] ?? []
        );

        $total = $data['totalCount'] ?? null;
        $resultPagination = new Pagination(
            page: $page,
            perPage: $perPage,
            total: $total,
        );

        return ['calls' => $calls, 'pagination' => $resultPagination];
    }

    public function getVoicemails(User $user, ?Pagination $pagination = null): array
    {
        $data = $this->api->getVoicemails($user);

        $voicemails = array_map(fn (array $vm) => [
            'id' => $vm['id'] ?? '',
            'from' => $vm['source'] ?? $vm['from'] ?? null,
            'to' => $vm['target'] ?? $vm['to'] ?? null,
            'created_at' => $vm['created'] ?? $vm['createdAt'] ?? null,
            'duration_seconds' => $vm['duration'] ?? null,
            'read_status' => ($vm['read'] ?? false) ? 'Read' : 'Unread',
            'transcription' => $vm['transcription'] ?? null,
        ], $data['items'] ?? []);

        $resultPagination = new Pagination(
            page: $pagination?->page ?? 1,
            perPage: $pagination?->perPage ?? 25,
            total: count($voicemails),
        );

        return ['voicemails' => $voicemails, 'pagination' => $resultPagination];
    }

    public function sendSMS(User $user, string $to, string $body): array
    {
        $smsId = $this->resolveSmsId($user);

        $result = $this->api->sendSms($user, $smsId, $to, $body);

        return [
            'id' => $result['sessionId'] ?? '',
            'status' => $result['status'] ?? 'ok',
        ];
    }

    public function initiateCall(User $user, string $from, string $to): array
    {
        $data = $this->api->initiateCall($user, $from, $to);

        return [
            'sessionId' => $data['sessionId'] ?? '',
            'status' => $data['status'] ?? 'InProgress',
        ];
    }

    protected function mapCallLogEntry(array $data): CallLogEntry
    {
        $direction = strtolower($data['direction'] ?? 'incoming');
        $normalizedDirection = match ($direction) {
            'in', 'incoming', 'inbound' => 'inbound',
            'out', 'outgoing', 'outbound' => 'outbound',
            default => $direction,
        };

        $type = strtolower($data['type'] ?? 'CALL');
        $normalizedType = match ($type) {
            'call', 'voip' => 'voice',
            'sms' => 'sms',
            'fax' => 'fax',
            'voicemail' => 'voicemail',
            default => $type,
        };

        $result = 'unknown';
        if (isset($data['hangupCause'])) {
            $result = match ($data['hangupCause']) {
                'normalClearing' => 'answered',
                'cancel', 'busy' => 'missed',
                default => strtolower($data['hangupCause']),
            };
        } elseif (isset($data['answeringNumber']) && !empty($data['answeringNumber'])) {
            $result = 'answered';
        }

        return new CallLogEntry(
            id: (string) ($data['id'] ?? ''),
            provider: 'sipgate',
            direction: $normalizedDirection,
            type: $normalizedType,
            from: $data['source'] ?? $data['from'] ?? null,
            to: $data['target'] ?? $data['to'] ?? null,
            startTime: Carbon::parse($data['created'] ?? $data['createdAt'] ?? now()),
            durationSeconds: $data['duration'] ?? null,
            result: $result,
            raw: $data,
        );
    }

    /**
     * Resolve the first available SMS extension ID for this user.
     */
    protected function resolveSmsId(User $user): string
    {
        try {
            $data = $this->api->getSmsExtensions($user);

            $items = $data['items'] ?? [];
            if (!empty($items)) {
                return $items[0]['id'] ?? $items[0]['smsId'] ?? '';
            }
        } catch (\Exception $e) {
            // Fall through
        }

        throw new \RuntimeException('Keine SMS-Extension bei Sipgate gefunden.');
    }
}
