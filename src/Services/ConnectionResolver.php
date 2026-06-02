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
}
