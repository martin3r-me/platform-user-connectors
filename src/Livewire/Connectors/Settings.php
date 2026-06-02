<?php

namespace Platform\UserConnectors\Livewire\Connectors;

use Livewire\Component;
use Platform\Core\Models\User;
use Platform\UserConnectors\Models\UserConnector;
use Platform\UserConnectors\Models\UserConnectorOAuthApp;

class Settings extends Component
{
    public bool $modalShow = false;
    public ?int $editingAppId = null;
    public ?int $editingConnectorId = null;

    public string $appName = '';
    public string $clientId = '';
    public string $clientSecret = '';

    public function mount(): void
    {
        /** @var User $user */
        $user = auth()->user();
        $team = $user->currentTeam;

        if (!$team) {
            abort(403);
        }

        $role = $user->teams()
            ->where('teams.id', $team->id)
            ->first()
            ?->pivot
            ?->role;

        if (!in_array($role, ['owner', 'admin'])) {
            abort(403, 'Nur Admins können Connector-Settings verwalten.');
        }
    }

    public function render()
    {
        $connectors = UserConnector::query()
            ->with(['oauthApps' => fn ($q) => $q->orderBy('name')])
            ->orderBy('name')
            ->get();

        return view('user-connectors::livewire.connectors.settings', [
            'connectors' => $connectors,
        ])->layout('platform::layouts.app');
    }

    public function addApp(int $connectorId): void
    {
        $connector = UserConnector::findOrFail($connectorId);

        $this->editingAppId = null;
        $this->editingConnectorId = $connectorId;
        $this->appName = $connector->name;
        $this->clientId = '';
        $this->clientSecret = '';
        $this->modalShow = true;
    }

    public function editApp(int $appId): void
    {
        $app = UserConnectorOAuthApp::findOrFail($appId);

        $this->editingAppId = $appId;
        $this->editingConnectorId = $app->connector_id;
        $this->appName = $app->name;
        $this->clientId = $app->settings['client_id'] ?? '';
        $this->clientSecret = '';
        $this->modalShow = true;
    }

    public function saveApp(): void
    {
        $this->validate([
            'appName' => 'required|string|max:255',
            'clientId' => 'required|string|max:500',
        ]);

        if ($this->editingAppId) {
            // Update existing
            $app = UserConnectorOAuthApp::findOrFail($this->editingAppId);
            $app->name = $this->appName;

            $settings = $app->settings ?? [];
            $settings['client_id'] = $this->clientId;
            if ($this->clientSecret !== '') {
                $settings['client_secret'] = $this->clientSecret;
            }
            $app->settings = $settings;
            $app->save();

            session()->flash('status', "OAuth-App '{$app->name}' aktualisiert.");
        } else {
            // Create new
            if ($this->clientSecret === '') {
                $this->addError('clientSecret', 'Client Secret ist bei neuen Apps erforderlich.');
                return;
            }

            $app = UserConnectorOAuthApp::create([
                'connector_id' => $this->editingConnectorId,
                'name' => $this->appName,
                'settings' => [
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                ],
                'is_enabled' => true,
            ]);

            session()->flash('status', "OAuth-App '{$app->name}' erstellt.");
        }

        $this->closeModal();
    }

    public function deleteApp(int $appId): void
    {
        $app = UserConnectorOAuthApp::findOrFail($appId);
        $name = $app->name;
        $app->delete();

        session()->flash('status', "OAuth-App '{$name}' gelöscht.");
    }

    public function toggleAppEnabled(int $appId): void
    {
        $app = UserConnectorOAuthApp::findOrFail($appId);
        $app->is_enabled = !$app->is_enabled;
        $app->save();

        $status = $app->is_enabled ? 'aktiviert' : 'deaktiviert';
        session()->flash('status', "'{$app->name}' wurde {$status}.");
    }

    public function toggleEnabled(int $id): void
    {
        $connector = UserConnector::findOrFail($id);
        $connector->is_enabled = !$connector->is_enabled;
        $connector->save();

        $status = $connector->is_enabled ? 'aktiviert' : 'deaktiviert';
        session()->flash('status', "'{$connector->name}' wurde {$status}.");
    }

    public function closeModal(): void
    {
        $this->modalShow = false;
        $this->reset(['editingAppId', 'editingConnectorId', 'appName', 'clientId', 'clientSecret']);
    }
}
