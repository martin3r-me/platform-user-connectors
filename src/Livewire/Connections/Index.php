<?php

namespace Platform\UserConnectors\Livewire\Connections;

use Livewire\Component;
use Platform\Core\Models\User;
use Platform\UserConnectors\Models\UserConnector;
use Platform\UserConnectors\Models\UserConnectorConnection;
use Platform\UserConnectors\Services\Microsoft365\Microsoft365ConnectorService;
use Platform\UserConnectors\Services\RingCentral\RingCentralConnectorService;
use Platform\UserConnectors\Services\Sipgate\SipgateConnectorService;
use Platform\UserConnectors\Services\WebhookSubscriptionManager;

class Index extends Component
{
    public ?string $syncMessage = null;
    public ?string $syncError = null;

    public function render()
    {
        /** @var User $user */
        $user = auth()->user();

        $connectors = UserConnector::query()
            ->where('is_enabled', true)
            ->orderBy('name')
            ->get();

        $connections = UserConnectorConnection::query()
            ->with('connector')
            ->where('owner_user_id', $user->id)
            ->orderByDesc('is_default')
            ->orderByDesc('updated_at')
            ->get();

        // Shared connections
        $userTeamIds = $user->teams()->pluck('teams.id')->toArray();
        $sharedWithMe = UserConnectorConnection::query()
            ->with(['connector', 'ownerUser'])
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

        return view('user-connectors::livewire.connections.index', [
            'connectors' => $connectors,
            'connections' => $connections,
            'sharedWithMe' => $sharedWithMe,
        ])->layout('platform::layouts.app');
    }

    public function startOAuth(string $connectorKey): void
    {
        $url = route('user-connectors.oauth2.start', ['connectorKey' => $connectorKey]);
        $this->redirect($url);
    }

    public function reconnect(int $connectionId): void
    {
        $connection = UserConnectorConnection::query()
            ->with('connector')
            ->where('id', $connectionId)
            ->where('owner_user_id', auth()->id())
            ->first();

        if (!$connection) {
            session()->flash('error', 'Connection nicht gefunden.');
            return;
        }

        $url = route('user-connectors.oauth2.start', [
            'connectorKey' => $connection->connector->key,
            'connection_id' => $connectionId,
        ]);
        $this->redirect($url);
    }

    public function testConnection(int $connectionId): void
    {
        $this->syncError = null;
        $this->syncMessage = null;

        $connection = UserConnectorConnection::query()
            ->with('connector')
            ->where('id', $connectionId)
            ->where('owner_user_id', auth()->id())
            ->first();

        if (!$connection) {
            $this->syncError = 'Connection nicht gefunden.';
            return;
        }

        try {
            $key = $connection->connector->key;

            $result = match ($key) {
                'microsoft365' => app(Microsoft365ConnectorService::class)->testConnection($connection),
                'ringcentral' => app(RingCentralConnectorService::class)->testConnection($connection),
                'sipgate' => app(SipgateConnectorService::class)->testConnection($connection),
                default => ['success' => false, 'message' => "Test für '{$key}' nicht implementiert."],
            };

            if ($result['success']) {
                $this->syncMessage = $result['message'];
                session()->flash('status', $this->syncMessage);
            } else {
                $this->syncError = $result['message'];
            }
        } catch (\Exception $e) {
            $this->syncError = 'Fehler: ' . $e->getMessage();
        }
    }

    public function deleteConnection(int $connectionId): void
    {
        $connection = UserConnectorConnection::query()
            ->where('id', $connectionId)
            ->where('owner_user_id', auth()->id())
            ->first();

        if (!$connection) {
            session()->flash('error', 'Connection nicht gefunden.');
            return;
        }

        // Auto-delete API-side webhook subscriptions
        try {
            $manager = app(WebhookSubscriptionManager::class);
            $connection->loadMissing('connector');
            $manager->deleteSubscriptions($connection);
        } catch (\Throwable $e) {
            \Log::warning('UserConnectors: Auto-delete Subscriptions fehlgeschlagen', [
                'connection_id' => $connection->id,
                'error' => $e->getMessage(),
            ]);
        }

        $connection->delete();
        session()->flash('status', 'Verbindung gelöscht.');
    }

    public function setDefault(int $connectionId): void
    {
        $connection = UserConnectorConnection::query()
            ->where('id', $connectionId)
            ->where('owner_user_id', auth()->id())
            ->first();

        if ($connection) {
            $connection->makeDefault();
            session()->flash('status', "'{$connection->name}' ist jetzt die Standard-Verbindung.");
        }
    }
}
