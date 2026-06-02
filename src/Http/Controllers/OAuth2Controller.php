<?php

namespace Platform\UserConnectors\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Platform\UserConnectors\Models\UserConnectorOAuthApp;
use Platform\UserConnectors\Services\OAuth2Service;

class OAuth2Controller extends Controller
{
    public function __construct(
        protected OAuth2Service $oauth2,
    ) {}

    public function start(Request $request, string $connectorKey)
    {
        $user = $request->user();
        $ownerUserId = $user->id;

        $oauthAppId = (int) $request->query('oauth_app_id', 0);
        if ($oauthAppId <= 0) {
            return redirect()
                ->route('user-connectors.connections.index')
                ->with('error', 'Keine OAuth-App ausgewählt.');
        }

        $oauthApp = UserConnectorOAuthApp::with('connector')
            ->where('id', $oauthAppId)
            ->where('is_enabled', true)
            ->first();

        if (!$oauthApp || $oauthApp->connector->key !== $connectorKey) {
            return redirect()
                ->route('user-connectors.connections.index')
                ->with('error', 'OAuth-App nicht gefunden oder nicht aktiv.');
        }

        $state = $this->oauth2->newState();
        $request->session()->put('user-connectors.oauth2.state', $state);
        $request->session()->put('user-connectors.oauth2.owner_user_id', $ownerUserId);
        $request->session()->put('user-connectors.oauth2.oauth_app_id', $oauthApp->id);

        // Optional: connection_id for reconnect
        $connectionId = $request->query('connection_id');
        if ($connectionId) {
            $request->session()->put('user-connectors.oauth2.connection_id', (int) $connectionId);
        } else {
            $request->session()->forget('user-connectors.oauth2.connection_id');
        }

        $request->session()->save();

        try {
            $authorizeUrl = $this->oauth2->buildAuthorizeUrl($oauthApp, $state);

            \Log::info('UserConnectors OAuth2 Start', [
                'connector_key' => $connectorKey,
                'oauth_app_id' => $oauthApp->id,
                'user_id' => $ownerUserId,
            ]);

            return redirect()->away($authorizeUrl);
        } catch (\Exception $e) {
            \Log::error('UserConnectors OAuth2 Start Error', [
                'connector_key' => $connectorKey,
                'oauth_app_id' => $oauthApp->id,
                'error' => $e->getMessage(),
            ]);

            return redirect()
                ->route('user-connectors.connections.index')
                ->with('error', 'Fehler beim Starten des OAuth-Flows: ' . $e->getMessage());
        }
    }

    public function callback(Request $request, string $connectorKey)
    {
        try {
            $connection = $this->oauth2->handleCallback($request, $connectorKey);

            \Log::info('UserConnectors OAuth2 Callback Success', [
                'connector_key' => $connectorKey,
                'connection_id' => $connection->id,
            ]);

            return redirect()
                ->route('user-connectors.connections.index')
                ->with('status', "Verbindung für '{$connectorKey}' gespeichert (Connection #{$connection->id}).");
        } catch (\Exception $e) {
            \Log::error('UserConnectors OAuth2 Callback Error', [
                'connector_key' => $connectorKey,
                'error' => $e->getMessage(),
            ]);

            return redirect()
                ->route('user-connectors.connections.index')
                ->with('error', 'Fehler beim OAuth-Callback: ' . $e->getMessage());
        }
    }
}
