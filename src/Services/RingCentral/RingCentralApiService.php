<?php

namespace Platform\UserConnectors\Services\RingCentral;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Platform\Core\Models\User;
use Platform\UserConnectors\Exceptions\RingCentralApiException;
use Platform\UserConnectors\Models\UserConnectorConnection;
use Platform\UserConnectors\Services\ConnectionResolver;

class RingCentralApiService
{
    protected ?int $connectionIdOverride = null;

    public function __construct(
        protected RingCentralConnectorService $connectorService,
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
            throw RingCentralApiException::connectionError('Keine RingCentral-Verbindung konfiguriert.');
        }

        return $connection;
    }

    public function get(User $user, string $path, array $query = []): array
    {
        return $this->request($user, 'GET', $path, $query);
    }

    public function post(User $user, string $path, array $body = []): array
    {
        return $this->request($user, 'POST', $path, [], $body);
    }

    public function delete(User $user, string $path): bool
    {
        $connection = $this->resolveConnection($user);
        $token = $this->connectorService->getValidAccessToken($connection);
        if (!$token) {
            throw RingCentralApiException::connectionError('Kein gültiger Token.');
        }

        $configKey = $this->connectorService->getConnectorKey();
        $baseUrl = config("user-connectors.{$configKey}.api_base_url", 'https://platform.ringcentral.com/restapi/v1.0');
        $response = Http::withToken($token)
            ->timeout(config("user-connectors.{$configKey}.timeout.default", 30))
            ->delete($baseUrl . $path);

        return $response->status() === 204 || $response->successful();
    }

    protected function request(User $user, string $method, string $path, array $query = [], array $body = []): array
    {
        $connection = $this->resolveConnection($user);
        $token = $this->connectorService->getValidAccessToken($connection);

        if (!$token) {
            throw RingCentralApiException::connectionError('Kein gültiger Token.');
        }

        $configKey = $this->connectorService->getConnectorKey();
        $baseUrl = config("user-connectors.{$configKey}.api_base_url", 'https://platform.ringcentral.com/restapi/v1.0');
        $url = $baseUrl . $path;
        $timeout = config("user-connectors.{$configKey}.timeout.default", 30);
        $connectTimeout = config("user-connectors.{$configKey}.timeout.connect", 10);

        try {
            $http = Http::withToken($token)
                ->timeout($timeout)
                ->connectTimeout($connectTimeout)
                ->withHeaders(['Accept' => 'application/json']);

            $response = match ($method) {
                'GET' => $http->get($url, $query),
                'POST' => $http->asJson()->post($url, $body),
                default => $http->get($url, $query),
            };

            return $this->handleResponse($response, $connection);
        } catch (RingCentralApiException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('RingCentral UC API Verbindungsfehler', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            $connection->update([
                'status' => 'error',
                'last_error' => $e->getMessage(),
                'last_tested_at' => now(),
            ]);

            throw RingCentralApiException::connectionError($e->getMessage());
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
            throw RingCentralApiException::fromResponse($status, $data);
        }

        if ($status === 429) {
            $retryAfter = (int) $response->header('Retry-After');
            throw RingCentralApiException::rateLimited($retryAfter ?: null);
        }

        if ($response->successful()) {
            return $data;
        }

        throw RingCentralApiException::fromResponse($status, $data);
    }
}
