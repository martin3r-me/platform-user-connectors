<?php

namespace Platform\UserConnectors\Livewire\Connectors;

use Livewire\Component;
use Platform\Core\Models\User;
use Platform\UserConnectors\Models\UserConnector;

class Settings extends Component
{
    public ?int $editingConnectorId = null;
    public bool $modalShow = false;

    public string $clientId = '';
    public string $clientSecret = '';
    public string $authorizeUrl = '';
    public string $tokenUrl = '';
    public string $redirectDomain = '';
    public string $scopes = '';
    public string $extraParams = '';

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
        $connectors = UserConnector::query()->orderBy('name')->get();

        $connectors->each(function (UserConnector $connector) {
            $connector->is_configured = !empty($connector->getOAuthConfig()['client_id'] ?? null);
        });

        return view('user-connectors::livewire.connectors.settings', [
            'connectors' => $connectors,
        ])->layout('platform::layouts.app');
    }

    public function editConnector(int $id): void
    {
        $connector = UserConnector::findOrFail($id);
        $oauth = $connector->getOAuthConfig() ?? [];

        $this->editingConnectorId = $id;
        $this->clientId = $oauth['client_id'] ?? '';
        $this->clientSecret = ''; // Never pre-fill secret
        $this->authorizeUrl = $oauth['authorize_url'] ?? '';
        $this->tokenUrl = $oauth['token_url'] ?? '';
        $this->redirectDomain = $oauth['redirect_domain'] ?? '';
        $this->scopes = implode(', ', $oauth['scopes'] ?? []);
        $this->extraParams = !empty($oauth['extra_params'])
            ? json_encode($oauth['extra_params'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            : '';

        $this->modalShow = true;
    }

    public function saveConnector(): void
    {
        $this->validate([
            'clientId' => 'required|string|max:500',
            'authorizeUrl' => 'required|url|max:1000',
            'tokenUrl' => 'required|url|max:1000',
            'redirectDomain' => 'nullable|url|max:1000',
            'scopes' => 'nullable|string|max:2000',
            'extraParams' => 'nullable|string|max:2000',
        ]);

        $connector = UserConnector::findOrFail($this->editingConnectorId);

        $settings = $connector->settings ?? [];

        $existingOauth = $settings['oauth'] ?? [];

        $scopes = array_values(array_filter(
            array_map('trim', explode(',', $this->scopes))
        ));

        $extraParams = [];
        if ($this->extraParams) {
            $decoded = json_decode($this->extraParams, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->addError('extraParams', 'Extra-Parameter muss gültiges JSON sein.');
                return;
            }
            $extraParams = $decoded;
        }

        $oauthConfig = [
            'authorize_url' => $this->authorizeUrl,
            'token_url' => $this->tokenUrl,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret ?: ($existingOauth['client_secret'] ?? ''),
            'redirect_domain' => $this->redirectDomain ?: null,
            'scopes' => $scopes,
            'extra_params' => $extraParams,
        ];

        $settings['oauth'] = $oauthConfig;
        $connector->settings = $settings;
        $connector->save();

        $this->modalShow = false;
        $this->reset(['editingConnectorId', 'clientId', 'clientSecret', 'authorizeUrl', 'tokenUrl', 'redirectDomain', 'scopes', 'extraParams']);

        session()->flash('status', "OAuth-Konfiguration für '{$connector->name}' gespeichert.");
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
        $this->reset(['editingConnectorId', 'clientId', 'clientSecret', 'authorizeUrl', 'tokenUrl', 'redirectDomain', 'scopes', 'extraParams']);
    }
}
