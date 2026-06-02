<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="Meine Connectoren" icon="heroicon-o-link" />
    </x-slot>

    <x-ui-page-container spacing="space-y-8">
        {{-- Flash Messages --}}
        @if (session()->has('status'))
            <div class="bg-green-50 border border-green-200 rounded-xl p-4">
                <div class="flex items-center gap-2">
                    @svg('heroicon-o-check-circle', 'w-5 h-5 text-green-600')
                    <p class="text-sm font-medium text-green-800">{{ session('status') }}</p>
                </div>
            </div>
        @endif
        @if (session()->has('error'))
            <div class="bg-red-50 border border-red-200 rounded-xl p-4">
                <div class="flex items-center gap-2">
                    @svg('heroicon-o-exclamation-circle', 'w-5 h-5 text-red-600')
                    <p class="text-sm font-medium text-red-800">{{ session('error') }}</p>
                </div>
            </div>
        @endif
        @if ($syncError)
            <div class="bg-red-50 border border-red-200 rounded-xl p-4">
                <div class="flex items-center gap-2">
                    @svg('heroicon-o-exclamation-circle', 'w-5 h-5 text-red-600')
                    <p class="text-sm font-medium text-red-800">{{ $syncError }}</p>
                </div>
            </div>
        @endif
        @if ($syncMessage)
            <div class="bg-green-50 border border-green-200 rounded-xl p-4">
                <div class="flex items-center gap-2">
                    @svg('heroicon-o-check-circle', 'w-5 h-5 text-green-600')
                    <p class="text-sm font-medium text-green-800">{{ $syncMessage }}</p>
                </div>
            </div>
        @endif

        {{-- Available Connectors --}}
        <x-ui-panel title="Verfügbare Connectoren" subtitle="OAuth2-Verbindungen einrichten">
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                @foreach ($connectors as $connector)
                    <div class="p-4 rounded-xl border border-[var(--ui-border)]/60 bg-white">
                        <div class="flex items-center justify-between mb-2">
                            <h3 class="text-sm font-medium text-[var(--ui-secondary)]">{{ $connector->name }}</h3>
                            @if (in_array('oauth2', $connector->supported_auth_schemes ?? []))
                                @if ($connector->oauthApps->isNotEmpty())
                                    <x-ui-button variant="primary" size="sm" wire:click="startOAuth('{{ $connector->key }}')">
                                        Verbinden
                                    </x-ui-button>
                                @else
                                    <x-ui-badge size="sm" variant="warning">Nicht konfiguriert</x-ui-badge>
                                @endif
                            @endif
                        </div>
                        <p class="text-xs text-[var(--ui-muted)]">{{ $connector->meta['description'] ?? '' }}</p>
                        @if ($connector->capabilities)
                            <div class="mt-2 flex flex-wrap gap-1">
                                @foreach ($connector->capabilities as $cap)
                                    <x-ui-badge size="sm" variant="neutral">{{ $cap }}</x-ui-badge>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        </x-ui-panel>

        {{-- My Connections --}}
        <x-ui-panel title="Meine Verbindungen">
            @if ($connections->isEmpty())
                <p class="text-sm text-[var(--ui-muted)]">Noch keine Verbindungen eingerichtet.</p>
            @else
                <div class="space-y-3">
                    @foreach ($connections as $connection)
                        <div class="p-4 rounded-xl border border-[var(--ui-border)]/60 bg-white">
                            <div class="flex items-center justify-between">
                                <div>
                                    <h3 class="text-sm font-medium text-[var(--ui-secondary)]">
                                        {{ $connection->name }}
                                        @if ($connection->is_default)
                                            <x-ui-badge size="sm" variant="primary">Standard</x-ui-badge>
                                        @endif
                                    </h3>
                                    <p class="text-xs text-[var(--ui-muted)] mt-1">
                                        {{ $connection->connector?->name ?? '?' }}
                                        @if ($connection->oauthApp)
                                            &middot; {{ $connection->oauthApp->name }}
                                        @endif
                                        &middot;
                                        <x-ui-badge size="sm" variant="{{ $connection->status === 'active' ? 'success' : ($connection->status === 'error' ? 'danger' : 'warning') }}">
                                            {{ $connection->status }}
                                        </x-ui-badge>
                                        @if ($connection->last_tested_at)
                                            &middot; Getestet: {{ $connection->last_tested_at->diffForHumans() }}
                                        @endif
                                    </p>
                                    @if ($connection->last_error)
                                        <p class="text-xs text-red-500 mt-1">{{ $connection->last_error }}</p>
                                    @endif
                                </div>
                                <div class="flex items-center gap-2">
                                    <x-ui-button variant="secondary-outline" size="sm" wire:click="testConnection({{ $connection->id }})">
                                        Testen
                                    </x-ui-button>
                                    <x-ui-button variant="secondary-outline" size="sm" wire:click="reconnect({{ $connection->id }})">
                                        Reconnect
                                    </x-ui-button>
                                    @if (!$connection->is_default)
                                        <x-ui-button variant="secondary-outline" size="sm" wire:click="setDefault({{ $connection->id }})">
                                            Standard
                                        </x-ui-button>
                                    @endif
                                    <x-ui-button variant="danger-outline" size="sm" wire:click="deleteConnection({{ $connection->id }})" wire:confirm="Verbindung wirklich löschen?">
                                        Löschen
                                    </x-ui-button>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-ui-panel>

        {{-- Shared With Me --}}
        @if ($sharedWithMe->isNotEmpty())
            <x-ui-panel title="Mit mir geteilte Verbindungen">
                <div class="space-y-3">
                    @foreach ($sharedWithMe as $shared)
                        <div class="p-4 rounded-xl border border-[var(--ui-border)]/60 bg-white opacity-75">
                            <h3 class="text-sm font-medium text-[var(--ui-secondary)]">{{ $shared->name }}</h3>
                            <p class="text-xs text-[var(--ui-muted)] mt-1">
                                {{ $shared->connector?->name ?? '?' }} &middot;
                                von {{ $shared->ownerUser?->name ?? '?' }} &middot;
                                {{ $shared->status }}
                            </p>
                        </div>
                    @endforeach
                </div>
            </x-ui-panel>
        @endif
    </x-ui-page-container>

    {{-- App Selection Modal (when connector has multiple OAuth apps) --}}
    @if ($appSelectModal)
        <div class="fixed inset-0 z-50 overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true">
            <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
                <div class="fixed inset-0 bg-gray-500/75 transition-opacity" wire:click="closeAppSelect"></div>
                <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>

                <div class="relative inline-block align-bottom bg-white rounded-2xl text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-md sm:w-full">
                    <div class="px-6 pt-5 pb-4">
                        <h3 class="text-lg font-medium text-[var(--ui-secondary)] mb-2" id="modal-title">
                            OAuth-App auswählen
                        </h3>
                        <p class="text-sm text-[var(--ui-muted)] mb-4">
                            Mehrere OAuth-Apps verfügbar. Bitte auswählen:
                        </p>

                        <div class="space-y-2">
                            @php
                                $selectableApps = $appSelectConnectorId
                                    ? \Platform\UserConnectors\Models\UserConnectorOAuthApp::where('connector_id', $appSelectConnectorId)->where('is_enabled', true)->orderBy('name')->get()
                                    : collect();
                            @endphp
                            @foreach ($selectableApps as $app)
                                <button
                                    wire:click="startOAuthWithApp({{ $app->id }})"
                                    class="w-full text-left p-3 rounded-lg border border-[var(--ui-border)]/60 hover:border-[var(--ui-primary)] hover:bg-[var(--ui-primary)]/5 transition-colors"
                                >
                                    <span class="text-sm font-medium text-[var(--ui-secondary)]">{{ $app->name }}</span>
                                </button>
                            @endforeach
                        </div>
                    </div>

                    <div class="border-t border-[var(--ui-border)] px-6 py-3 flex justify-end">
                        <x-ui-button variant="secondary-outline" wire:click="closeAppSelect">
                            Abbrechen
                        </x-ui-button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</x-ui-page>
