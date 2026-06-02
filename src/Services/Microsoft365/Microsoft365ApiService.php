<?php

namespace Platform\UserConnectors\Services\Microsoft365;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Platform\Core\Models\User;
use Platform\UserConnectors\Exceptions\Microsoft365ApiException;
use Platform\UserConnectors\Models\UserConnectorConnection;
use Platform\UserConnectors\Services\ConnectionResolver;

class Microsoft365ApiService
{
    protected ?int $connectionIdOverride = null;

    public function __construct(
        protected Microsoft365ConnectorService $connectorService,
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
            throw Microsoft365ApiException::connectionError('Keine Microsoft 365 Verbindung konfiguriert.');
        }

        return $connection;
    }

    /**
     * GET request to Graph API.
     *
     * @throws Microsoft365ApiException
     */
    public function get(User $user, string $path, array $query = []): array
    {
        return $this->request($user, 'GET', $path, $query);
    }

    /**
     * POST request to Graph API.
     *
     * @throws Microsoft365ApiException
     */
    public function post(User $user, string $path, array $body = []): array
    {
        return $this->request($user, 'POST', $path, [], $body);
    }

    /**
     * PATCH request to Graph API.
     *
     * @throws Microsoft365ApiException
     */
    public function patch(User $user, string $path, array $body = []): array
    {
        return $this->request($user, 'PATCH', $path, [], $body);
    }

    /**
     * DELETE request to Graph API.
     *
     * @throws Microsoft365ApiException
     */
    public function delete(User $user, string $path): bool
    {
        $connection = $this->resolveConnection($user);
        $token = $this->connectorService->getValidAccessToken($connection);

        if (!$token) {
            throw Microsoft365ApiException::connectionError('Kein gültiger Token.');
        }

        $baseUrl = config('user-connectors.microsoft365.graph_base_url', 'https://graph.microsoft.com/v1.0');
        $timeout = config('user-connectors.microsoft365.timeout.default', 30);
        $connectTimeout = config('user-connectors.microsoft365.timeout.connect', 10);

        $response = Http::withToken($token)
            ->timeout($timeout)
            ->connectTimeout($connectTimeout)
            ->delete($baseUrl . $path);

        if ($response->status() === 204 || $response->successful()) {
            return true;
        }

        $this->handleErrorResponse($response, $connection);

        return false;
    }

    protected function request(User $user, string $method, string $path, array $query = [], array $body = []): array
    {
        $connection = $this->resolveConnection($user);
        $token = $this->connectorService->getValidAccessToken($connection);

        if (!$token) {
            throw Microsoft365ApiException::connectionError('Kein gültiger Token.');
        }

        $baseUrl = config('user-connectors.microsoft365.graph_base_url', 'https://graph.microsoft.com/v1.0');
        $url = $baseUrl . $path;
        $timeout = config('user-connectors.microsoft365.timeout.default', 30);
        $connectTimeout = config('user-connectors.microsoft365.timeout.connect', 10);

        try {
            $http = Http::withToken($token)
                ->timeout($timeout)
                ->connectTimeout($connectTimeout)
                ->withHeaders(['Accept' => 'application/json', 'Content-Type' => 'application/json']);

            $response = match ($method) {
                'GET' => $http->get($url, $query),
                'POST' => $http->post($url, $body),
                'PATCH' => $http->patch($url, $body),
                'DELETE' => $http->delete($url),
                default => $http->get($url, $query),
            };

            return $this->handleResponse($response, $connection);
        } catch (Microsoft365ApiException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Microsoft365 API Verbindungsfehler', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            $connection->update([
                'status' => 'error',
                'last_error' => $e->getMessage(),
                'last_tested_at' => now(),
            ]);

            throw Microsoft365ApiException::connectionError($e->getMessage());
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
            throw Microsoft365ApiException::apiError($status, $data);
        }

        if ($status === 429) {
            $retryAfter = (int) $response->header('Retry-After');
            throw Microsoft365ApiException::rateLimited($retryAfter ?: null);
        }

        if ($response->successful()) {
            return $data;
        }

        Log::warning('Microsoft365 API Fehler', ['status' => $status, 'response' => $data]);

        throw Microsoft365ApiException::apiError($status, $data);
    }

    protected function handleErrorResponse(Response $response, UserConnectorConnection $connection): void
    {
        $status = $response->status();
        $data = $response->json() ?? [];

        throw Microsoft365ApiException::apiError($status, $data);
    }
}
