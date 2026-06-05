<?php

namespace Platform\UserConnectors\Livewire\Connections;

use Livewire\Component;
use Platform\Core\Models\User;
use Platform\UserConnectors\Models\UserConnector;
use Platform\UserConnectors\Models\UserConnectorConnection;
use Platform\UserConnectors\Models\UserConnectorCallSession;
use Platform\UserConnectors\Models\UserConnectorInboundEvent;
use Platform\UserConnectors\Models\UserConnectorMailSession;
use Platform\UserConnectors\Models\UserConnectorMeetingSession;
use Platform\UserConnectors\Models\UserConnectorMessageSession;
use Platform\UserConnectors\Models\UserConnectorOAuthApp;
use Platform\UserConnectors\Services\Microsoft365\Microsoft365ConnectorService;
use Platform\UserConnectors\Services\RingCentral\RingCentralConnectorService;
use Platform\UserConnectors\Services\Sipgate\SipgateConnectorService;
use Platform\UserConnectors\Services\Vodafone\VodafoneConnectorService;
use Platform\UserConnectors\Services\WebhookSubscriptionManager;

class Index extends Component
{
    public ?string $syncMessage = null;
    public ?string $syncError = null;

    public bool $appSelectModal = false;
    public ?int $appSelectConnectorId = null;
    public ?string $appSelectConnectorKey = null;

    public bool $settingsModal = false;
    public ?int $settingsConnectionId = null;
    public bool $settingsCrmCreateEngagement = false;
    public bool $settingsCrmCreateContact = false;
    public bool $settingsSubscriptionsEnabled = true;
    public bool $settingsRecordingsEnabled = true;

    public function render()
    {
        /** @var User $user */
        $user = auth()->user();

        $connectors = UserConnector::query()
            ->with(['oauthApps' => fn ($q) => $q->where('is_enabled', true)->orderBy('name')])
            ->where('is_enabled', true)
            ->orderBy('name')
            ->get();

        $connections = UserConnectorConnection::query()
            ->with(['connector', 'oauthApp', 'phoneNumbers', 'devices'])
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

        $connectionIds = $connections->pluck('id')->toArray();
        $connectorKeys = $connections->pluck('connector.key')->unique()->filter()->toArray();

        // Auto-expire stale ringing/active sessions (no hangup received)
        UserConnectorCallSession::whereIn('connection_id', $connectionIds)
            ->whereIn('status', ['ringing', 'active'])
            ->where('started_at', '<', now()->subMinutes(2))
            ->update([
                'status' => 'missed',
                'ended_at' => now(),
                'hangup_cause' => 'noAnswer',
            ]);

        // Call sessions (grouped call lifecycle) — only user's own connections
        $callSessions = UserConnectorCallSession::query()
            ->whereIn('connection_id', $connectionIds)
            ->recent(30)
            ->get();

        // Mail sessions — only user's own connections
        $mailSessions = UserConnectorMailSession::query()
            ->whereIn('connection_id', $connectionIds)
            ->recent(30)
            ->get();

        // Meeting sessions — auto-update status from time
        $activeMeetings = UserConnectorMeetingSession::query()
            ->whereIn('connection_id', $connectionIds)
            ->whereIn('status', ['upcoming', 'in_progress'])
            ->get();

        foreach ($activeMeetings as $meeting) {
            $meeting->updateStatusFromTime();
        }

        $meetingSessions = UserConnectorMeetingSession::query()
            ->whereIn('connection_id', $connectionIds)
            ->recent(30)
            ->get();

        // Message sessions — only user's own connections
        $messageSessions = UserConnectorMessageSession::query()
            ->whereIn('connection_id', $connectionIds)
            ->recent(30)
            ->get();

        // Inbound events — only user's own connections
        $recentEvents = UserConnectorInboundEvent::query()
            ->whereIn('connection_id', $connectionIds)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return view('user-connectors::livewire.connections.index', [
            'connectors' => $connectors,
            'connections' => $connections,
            'sharedWithMe' => $sharedWithMe,
            'callSessions' => $callSessions,
            'mailSessions' => $mailSessions,
            'meetingSessions' => $meetingSessions,
            'messageSessions' => $messageSessions,
            'recentEvents' => $recentEvents,
        ])->layout('platform::layouts.app');
    }

    /**
     * Start OAuth: if connector has 1 app → direct, if multiple → show selector.
     */
    public function startOAuth(string $connectorKey): void
    {
        $connector = UserConnector::query()
            ->with(['oauthApps' => fn ($q) => $q->where('is_enabled', true)])
            ->where('key', $connectorKey)
            ->first();

        if (!$connector) {
            session()->flash('error', "Connector '{$connectorKey}' nicht gefunden.");
            return;
        }

        $apps = $connector->oauthApps;

        if ($apps->isEmpty()) {
            session()->flash('error', "Keine OAuth-App für '{$connector->name}' konfiguriert. Bitte in den Settings einrichten.");
            return;
        }

        if ($apps->count() === 1) {
            $this->startOAuthWithApp($apps->first()->id);
            return;
        }

        // Multiple apps → show selection modal
        $this->appSelectConnectorId = $connector->id;
        $this->appSelectConnectorKey = $connectorKey;
        $this->appSelectModal = true;
    }

    public function startOAuthWithApp(int $oauthAppId): void
    {
        $app = UserConnectorOAuthApp::with('connector')
            ->where('id', $oauthAppId)
            ->where('is_enabled', true)
            ->first();

        if (!$app) {
            session()->flash('error', 'OAuth-App nicht gefunden oder nicht aktiv.');
            return;
        }

        $this->appSelectModal = false;

        $url = route('user-connectors.oauth2.start', [
            'connectorKey' => $app->connector->key,
            'oauth_app_id' => $app->id,
        ]);
        $this->redirect($url);
    }

    public function closeAppSelect(): void
    {
        $this->appSelectModal = false;
        $this->appSelectConnectorId = null;
        $this->appSelectConnectorKey = null;
    }

    public function reconnect(int $connectionId): void
    {
        $connection = UserConnectorConnection::query()
            ->with(['connector', 'oauthApp'])
            ->where('id', $connectionId)
            ->where('owner_user_id', auth()->id())
            ->first();

        if (!$connection) {
            session()->flash('error', 'Connection nicht gefunden.');
            return;
        }

        // If connection has an oauth_app, use it directly
        if ($connection->oauthApp) {
            $url = route('user-connectors.oauth2.start', [
                'connectorKey' => $connection->connector->key,
                'oauth_app_id' => $connection->oauth_app_id,
                'connection_id' => $connectionId,
            ]);
            $this->redirect($url);
            return;
        }

        // No app linked → trigger app selection
        $this->startOAuth($connection->connector->key);
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
                'vodafone' => app(VodafoneConnectorService::class)->testConnection($connection),
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

    public function openSettings(int $connectionId): void
    {
        $connection = UserConnectorConnection::query()
            ->where('id', $connectionId)
            ->where('owner_user_id', auth()->id())
            ->first();

        if (!$connection) {
            session()->flash('error', 'Connection nicht gefunden.');
            return;
        }

        $settings = $connection->credentials['settings'] ?? [];

        $this->settingsConnectionId = $connectionId;
        $this->settingsSubscriptionsEnabled = $settings['subscriptions_enabled'] ?? true;
        $this->settingsRecordingsEnabled = $settings['recordings_enabled'] ?? true;
        $this->settingsCrmCreateEngagement = $settings['crm_create_engagement'] ?? false;
        $this->settingsCrmCreateContact = $settings['crm_create_contact'] ?? false;
        $this->settingsModal = true;
    }

    public function saveSettings(): void
    {
        $connection = UserConnectorConnection::query()
            ->where('id', $this->settingsConnectionId)
            ->where('owner_user_id', auth()->id())
            ->first();

        if (!$connection) {
            session()->flash('error', 'Connection nicht gefunden.');
            $this->closeSettings();
            return;
        }

        $credentials = $connection->credentials;
        $credentials['settings'] = array_merge($credentials['settings'] ?? [], [
            'subscriptions_enabled' => $this->settingsSubscriptionsEnabled,
            'recordings_enabled' => $this->settingsRecordingsEnabled,
            'crm_create_engagement' => $this->settingsCrmCreateEngagement,
            'crm_create_contact' => $this->settingsCrmCreateContact,
        ]);
        $connection->credentials = $credentials;
        $connection->save();

        session()->flash('status', 'Einstellungen gespeichert.');
        $this->closeSettings();
    }

    public function closeSettings(): void
    {
        $this->settingsModal = false;
        $this->settingsConnectionId = null;
        $this->settingsCrmCreateEngagement = false;
        $this->settingsCrmCreateContact = false;
        $this->settingsSubscriptionsEnabled = true;
        $this->settingsRecordingsEnabled = true;
    }
}
