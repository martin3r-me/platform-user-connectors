<?php

namespace Platform\UserConnectors\Services;

use Illuminate\Support\Collection;
use Platform\Core\Models\User;
use Platform\UserConnectors\Models\UserConnector;
use Platform\UserConnectors\Models\UserConnectorConnection;

class ConnectionResolver
{
    /**
     * Resolve default connection for a connector key + user.
     * Prefers own default, falls back to shared connections.
     */
    public function resolveForUser(string $connectorKey, User $user): ?UserConnectorConnection
    {
        $connector = UserConnector::where('key', $connectorKey)->first();
        if (!$connector) {
            return null;
        }

        // Own connection (prefer default)
        $own = UserConnectorConnection::query()
            ->where('connector_id', $connector->id)
            ->where('owner_user_id', $user->id)
            ->where('status', 'active')
            ->orderByDesc('is_default')
            ->first();

        if ($own) {
            return $own;
        }

        // Shared connections
        $userTeamIds = $user->teams()->pluck('teams.id')->toArray();

        return UserConnectorConnection::query()
            ->where('connector_id', $connector->id)
            ->where('owner_user_id', '!=', $user->id)
            ->where('status', 'active')
            ->whereHas('shares', function ($query) use ($user, $userTeamIds) {
                $query->where(function ($q) use ($user) {
                    $q->whereNull('user_id')->orWhere('user_id', $user->id);
                })->where(function ($q) use ($userTeamIds) {
                    $q->whereNull('team_id');
                    if (!empty($userTeamIds)) {
                        $q->orWhereIn('team_id', $userTeamIds);
                    }
                });
            })
            ->first();
    }

    /**
     * Resolve a specific connection by ID, checking access for the user.
     */
    public function resolveById(int $connectionId, User $user): ?UserConnectorConnection
    {
        $connection = UserConnectorConnection::find($connectionId);
        if (!$connection) {
            return null;
        }

        // Owner always has access
        if ($connection->owner_user_id === $user->id) {
            return $connection;
        }

        // Check shared access
        $userTeamIds = $user->teams()->pluck('teams.id')->toArray();

        $hasShare = $connection->shares()
            ->where(function ($q) use ($user) {
                $q->whereNull('user_id')->orWhere('user_id', $user->id);
            })
            ->where(function ($q) use ($userTeamIds) {
                $q->whereNull('team_id');
                if (!empty($userTeamIds)) {
                    $q->orWhereIn('team_id', $userTeamIds);
                }
            })
            ->exists();

        return $hasShare ? $connection : null;
    }

    /**
     * Get all connections for a user (owned + shared) for a given connector key.
     */
    public function resolveAllForUser(string $connectorKey, User $user): Collection
    {
        $connector = UserConnector::where('key', $connectorKey)->first();
        if (!$connector) {
            return collect();
        }

        $own = UserConnectorConnection::query()
            ->where('connector_id', $connector->id)
            ->where('owner_user_id', $user->id)
            ->orderByDesc('is_default')
            ->get();

        $userTeamIds = $user->teams()->pluck('teams.id')->toArray();

        $shared = UserConnectorConnection::query()
            ->where('connector_id', $connector->id)
            ->where('owner_user_id', '!=', $user->id)
            ->where('status', 'active')
            ->whereHas('shares', function ($query) use ($user, $userTeamIds) {
                $query->where(function ($q) use ($user) {
                    $q->whereNull('user_id')->orWhere('user_id', $user->id);
                })->where(function ($q) use ($userTeamIds) {
                    $q->whereNull('team_id');
                    if (!empty($userTeamIds)) {
                        $q->orWhereIn('team_id', $userTeamIds);
                    }
                });
            })
            ->get();

        return $own->merge($shared);
    }

    /**
     * Alle Connections, auf die der User Zugriff hat (eigene + geteilte),
     * optional auf Connector-Keys eingeschränkt. Eigene zuerst, Default vorn.
     *
     * @param array<int, string> $connectorKeys
     */
    public function accessibleConnections(User $user, array $connectorKeys = [], bool $activeOnly = false): Collection
    {
        $userTeamIds = $user->teams()->pluck('teams.id')->toArray();

        $query = UserConnectorConnection::query()
            ->with('connector')
            ->where(function ($q) use ($user, $userTeamIds) {
                $q->where('owner_user_id', $user->id)
                    ->orWhere(function ($shared) use ($user, $userTeamIds) {
                        $shared->where('status', 'active')
                            ->whereHas('shares', function ($share) use ($user, $userTeamIds) {
                                $share->where(fn ($s) => $s->whereNull('user_id')->orWhere('user_id', $user->id))
                                    ->where(function ($s) use ($userTeamIds) {
                                        $s->whereNull('team_id');
                                        if (!empty($userTeamIds)) {
                                            $s->orWhereIn('team_id', $userTeamIds);
                                        }
                                    });
                            });
                    });
            });

        if ($connectorKeys !== []) {
            $query->whereHas('connector', fn ($q) => $q->whereIn('key', $connectorKeys));
        }
        if ($activeOnly) {
            $query->where('status', 'active');
        }

        return $query->get()->sortBy([
            fn ($a, $b) => ((int) $b->owner_user_id === (int) $user->id) <=> ((int) $a->owner_user_id === (int) $user->id),
            fn ($a, $b) => (int) $b->is_default <=> (int) $a->is_default,
            fn ($a, $b) => $a->id <=> $b->id,
        ])->values();
    }
}
