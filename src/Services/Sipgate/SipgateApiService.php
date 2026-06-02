<?php

namespace Platform\UserConnectors\Services\Sipgate;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Platform\Core\Models\User;
use Platform\UserConnectors\Exceptions\SipgateApiException;
use Platform\UserConnectors\Models\UserConnectorConnection;
use Platform\UserConnectors\Services\ConnectionResolver;

class SipgateApiService
{
    protected ?int $connectionIdOverride = null;

    public function __construct(
        protected SipgateConnectorService $connectorService,
    ) {}

    public function forConnection(?int $connectionId): static
    {
        if ($connectionId === null) {
            return $this;
        }

        $clone = clone $this;
        $clone->connectionIdOverride = $connectionId;

        return $clone;
    }

    protected function resolveConnection(User $user): UserConnectorConnection
    {
        if ($this->connectionIdOverride) {
            $resolver = app(ConnectionResolver::class);
            $connection = $resolver->resolveById($this->connectionIdOverride, $user);
        } else {
            $connection = $this->connectorService->getConnectionForUser($user);
        }

        if (!$connection) {
            throw SipgateApiException::connectionError('Keine Sipgate-Verbindung konfiguriert.');
        }

        return $connection;
    }

    // =========================================================================
    // PUBLIC API METHODS
    // =========================================================================

    public function getUserInfo(User $user): array
    {
        return $this->get($user, '/authorization/userinfo');
    }

    public function getAccount(User $user): array
    {
        return $this->get($user, '/account');
    }

    /**
     * Get call history.
     *
     * @param array $filters types, directions, from, to, phonenumber, limit, offset, archived, starred
     */
    public function getHistory(User $user, array $filters = []): array
    {
        $query = [];

        if (!empty($filters['types'])) {
            $query['types'] = is_array($filters['types']) ? implode(',', $filters['types']) : $filters['types'];
        }
        if (!empty($filters['directions'])) {
            $query['directions'] = is_array($filters['directions']) ? implode(',', $filters['directions']) : $filters['directions'];
        }
        if (!empty($filters['from'])) $query['from'] = $filters['from'];
        if (!empty($filters['to'])) $query['to'] = $filters['to'];
        if (!empty($filters['phonenumber'])) $query['phonenumber'] = $filters['phonenumber'];
        if (isset($filters['archived'])) $query['archived'] = $filters['archived'] ? 'true' : 'false';
        if (isset($filters['starred'])) $query['starred'] = $filters['starred'] ? 'true' : 'false';

        $query['limit'] = $filters['limit'] ?? 50;
        $query['offset'] = $filters['offset'] ?? 0;

        return $this->get($user, '/history', $query);
    }

    /**
     * Initiate a call (click-to-call).
     */
    public function initiateCall(User $user, string $caller, string $callee, ?string $callerId = null): array
    {
        $body = [
            'caller' => $caller,
            'callee' => $callee,
        ];

        if ($callerId) {
            $body['callerId'] = $callerId;
        }

        return $this->post($user, '/sessions/calls', $body);
    }

    /**
     * Hang up a call.
     */
    public function hangupCall(User $user, string $sessionId): bool
    {
        return $this->deleteRequest($user, "/sessions/calls/{$sessionId}");
    }

    /**
     * Send SMS.
     */
    public function sendSms(User $user, string $smsId, string $recipient, string $message): array
    {
        return $this->post($user, '/sessions/sms', [
            'smsId' => $smsId,
            'recipient' => $recipient,
            'message' => $message,
        ]);
    }

    /**
     * Get SMS extensions for the user.
     */
    public function getSmsExtensions(User $user): array
    {
        return $this->get($user, '/sms');
    }

    /**
     * Get phone numbers.
     */
    public function getNumbers(User $user): array
    {
        return $this->get($user, '/numbers');
    }

    /**
     * Get devices.
     */
    public function getDevices(User $user): array
    {
        return $this->get($user, '/devices');
    }

    /**
     * Get voicemails.
     */
    public function getVoicemails(User $user): array
    {
        return $this->get($user, '/voicemails');
    }

    // =========================================================================
    // HTTP METHODS
    // =========================================================================

    public function get(User $user, string $path, array $query = []): array
    {
        return $this->request($user, 'GET', $path, $query);
    }

    public function post(User $user, string $path, array $body = []): array
    {
        return $this->request($user, 'POST', $path, [], $body);
    }

    public function deleteRequest(User $user, string $path): bool
    {
        $connection = $this->resolveConnection($user);
        $token = $this->connectorService->getValidAccessToken($connection);
        if (!$token) {
            throw SipgateApiException::connectionError('Kein gültiger Token.');
        }

        $baseUrl = config('user-connectors.sipgate.api_base_url', 'https://api.sipgate.com/v2');
        $response = Http::withToken($token)
            ->timeout(config('user-connectors.sipgate.timeout.default', 30))
            ->delete($baseUrl . $path);

        return $response->status() === 204 || $response->successful();
    }

    protected function request(User $user, string $method, string $path, array $query = [], array $body = []): array
    {
        $connection = $this->resolveConnection($user);
        $token = $this->connectorService->getValidAccessToken($connection);

        if (!$token) {
            throw SipgateApiException::connectionError('Kein gültiger Token.');
        }

        $baseUrl = config('user-connectors.sipgate.api_base_url', 'https://api.sipgate.com/v2');
        $url = $baseUrl . $path;
        $timeout = config('user-connectors.sipgate.timeout.default', 30);
        $connectTimeout = config('user-connectors.sipgate.timeout.connect', 10);

        try {
            $http = Http::withToken($token)
                ->timeout($timeout)
                ->connectTimeout($connectTimeout)
                ->withHeaders(['Accept' => 'application/json']);

            $response = match ($method) {
                'GET' => $http->get($url, $query),
                'POST' => $http->asJson()->post($url, $body),
                'PUT' => $http->asJson()->put($url, $body),
                default => $http->get($url, $query),
            };

            return $this->handleResponse($response, $connection);
        } catch (SipgateApiException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Sipgate UC API Verbindungsfehler', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            $connection->update([
                'status' => 'error',
                'last_error' => $e->getMessage(),
                'last_tested_at' => now(),
            ]);

            throw SipgateApiException::connectionError($e->getMessage());
        }
    }

    protected function handleResponse(Response $response, UserConnectorConnection $connection): array
    {
        $status = $response->status();
        $data = $response->json() ?? [];

        if ($status === 401) {
            $connection->update([
                'status' => 'error',
                'last_error' => 'Token ungültig oder abgelaufen',
                'last_tested_at' => now(),
            ]);
            throw SipgateApiException::fromResponse($status, $data);
        }

        if ($status === 429) {
            $retryAfter = (int) $response->header('Retry-After');
            throw SipgateApiException::rateLimited($retryAfter ?: null);
        }

        // 204 No Content (e.g. after POST /sessions/sms)
        if ($status === 204) {
            return ['status' => 'ok'];
        }

        if ($response->successful()) {
            return $data;
        }

        throw SipgateApiException::fromResponse($status, $data);
    }
}
