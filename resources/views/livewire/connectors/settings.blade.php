<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="Connector-Settings" icon="heroicon-o-cog-6-tooth" />
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

        {{-- Connector Cards with OAuth Apps --}}
        @foreach ($connectors as $connector)
            <x-ui-panel>
                <div class="flex items-center justify-between mb-4">
                    <div>
                        <h3 class="text-sm font-medium text-[var(--ui-secondary)]">{{ $connector->name }}</h3>
                        <p class="text-xs text-[var(--ui-muted)] mt-1">{{ $connector->meta['description'] ?? '' }}</p>
                    </div>
                    <div class="flex items-center gap-3">
                        {{-- Enable/Disable Toggle --}}
                        <button
                            wire:click="toggleEnabled({{ $connector->id }})"
                            class="relative inline-flex h-5 w-9 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none {{ $connector->is_enabled ? 'bg-[var(--ui-primary)]' : 'bg-[var(--ui-border)]' }}"
                            role="switch"
                            aria-checked="{{ $connector->is_enabled ? 'true' : 'false' }}"
                        >
                            <span class="pointer-events-none inline-block h-4 w-4 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out {{ $connector->is_enabled ? 'translate-x-4' : 'translate-x-0' }}"></span>
                        </button>

                        <x-ui-button variant="primary" size="sm" wire:click="addApp({{ $connector->id }})">
                            + OAuth-App
                        </x-ui-button>
                    </div>
                </div>

                {{-- OAuth Apps List --}}
                @if ($connector->oauthApps->isNotEmpty())
                    <div class="space-y-2">
                        @foreach ($connector->oauthApps as $app)
                            <div class="flex items-center justify-between p-3 rounded-lg border border-[var(--ui-border)]/60 bg-[var(--ui-bg)]">
                                <div class="flex items-center gap-3">
                                    <div>
                                        <span class="text-sm font-medium text-[var(--ui-secondary)]">{{ $app->name }}</span>
                                        @if (!empty($app->settings['client_id']))
                                            <span class="ml-2 text-xs text-[var(--ui-muted)]">{{ Str::limit($app->settings['client_id'], 20) }}</span>
                                        @endif
                                    </div>
                                    @if ($app->is_enabled)
                                        <x-ui-badge size="sm" variant="success">Aktiv</x-ui-badge>
                                    @else
                                        <x-ui-badge size="sm" variant="neutral">Deaktiviert</x-ui-badge>
                                    @endif
                                    @php $connCount = $app->connections()->count(); @endphp
                                    @if ($connCount > 0)
                                        <x-ui-badge size="sm" variant="neutral">{{ $connCount }} {{ $connCount === 1 ? 'Verbindung' : 'Verbindungen' }}</x-ui-badge>
                                    @endif
                                </div>
                                <div class="flex items-center gap-2">
                                    <button
                                        wire:click="toggleAppEnabled({{ $app->id }})"
                                        class="relative inline-flex h-4 w-7 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none {{ $app->is_enabled ? 'bg-[var(--ui-primary)]' : 'bg-[var(--ui-border)]' }}"
                                        role="switch"
                                    >
                                        <span class="pointer-events-none inline-block h-3 w-3 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out {{ $app->is_enabled ? 'translate-x-3' : 'translate-x-0' }}"></span>
                                    </button>
                                    <x-ui-button variant="secondary-outline" size="sm" wire:click="editApp({{ $app->id }})">
                                        Bearbeiten
                                    </x-ui-button>
                                    <x-ui-button variant="danger-outline" size="sm" wire:click="deleteApp({{ $app->id }})" wire:confirm="OAuth-App '{{ $app->name }}' wirklich löschen?{{ $connCount > 0 ? ' Es gibt ' . $connCount . ' aktive Verbindung(en)!' : '' }}">
                                        Löschen
                                    </x-ui-button>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <p class="text-sm text-[var(--ui-muted)]">Noch keine OAuth-Apps konfiguriert.</p>
                @endif

                {{-- Show defaults info --}}
                @php $defaults = config("user-connectors.oauth_defaults.{$connector->key}", []); @endphp
                @if (!empty($defaults))
                    <div class="mt-3 pt-3 border-t border-[var(--ui-border)]/40">
                        <p class="text-xs text-[var(--ui-muted)]">
                            Redirect-URI: <code class="text-xs bg-[var(--ui-bg)] px-1 py-0.5 rounded">{{ route('user-connectors.oauth2.callback', ['connectorKey' => $connector->key]) }}</code>
                        </p>
                        @if (!empty($defaults['scopes']))
                            <p class="text-xs text-[var(--ui-muted)] mt-1">
                                Scopes: <code class="text-xs bg-[var(--ui-bg)] px-1 py-0.5 rounded">{{ implode(', ', $defaults['scopes']) }}</code>
                            </p>
                        @endif
                    </div>
                @endif
            </x-ui-panel>
        @endforeach
    </x-ui-page-container>

    {{-- Add/Edit OAuth App Modal --}}
    @if ($modalShow)
        <div class="fixed inset-0 z-50 overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true">
            <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
                <div class="fixed inset-0 bg-gray-500/75 transition-opacity" wire:click="closeModal"></div>
                <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>

                <div class="relative inline-block align-bottom bg-white rounded-2xl text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg sm:w-full">
                    <form wire:submit="saveApp">
                        <div class="px-6 pt-5 pb-4">
                            <h3 class="text-lg font-medium text-[var(--ui-secondary)] mb-4" id="modal-title">
                                {{ $editingAppId ? 'OAuth-App bearbeiten' : 'Neue OAuth-App' }}
                            </h3>

                            <div class="space-y-4">
                                {{-- App Name --}}
                                <div>
                                    <label class="block text-sm font-medium text-[var(--ui-secondary)]">Name</label>
                                    <input type="text" wire:model="appName" class="mt-1 block w-full rounded-lg border border-[var(--ui-border)] bg-white text-[var(--ui-secondary)] shadow-sm focus:border-[var(--ui-primary)] focus:ring-[var(--ui-primary)] sm:text-sm" placeholder="z.B. Microsoft 365 – Firma A" required>
                                    @error('appName') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                                </div>

                                {{-- Client ID --}}
                                <div>
                                    <label class="block text-sm font-medium text-[var(--ui-secondary)]">Client ID</label>
                                    <input type="text" wire:model="clientId" class="mt-1 block w-full rounded-lg border border-[var(--ui-border)] bg-white text-[var(--ui-secondary)] shadow-sm focus:border-[var(--ui-primary)] focus:ring-[var(--ui-primary)] sm:text-sm" required>
                                    @error('clientId') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                                </div>

                                {{-- Client Secret --}}
                                <div>
                                    <label class="block text-sm font-medium text-[var(--ui-secondary)]">Client Secret</label>
                                    <input type="password" wire:model="clientSecret" class="mt-1 block w-full rounded-lg border border-[var(--ui-border)] bg-white text-[var(--ui-secondary)] shadow-sm focus:border-[var(--ui-primary)] focus:ring-[var(--ui-primary)] sm:text-sm" placeholder="{{ $editingAppId ? 'Leer lassen = bestehenden Wert behalten' : '' }}">
                                    @error('clientSecret') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                                </div>
                            </div>
                        </div>

                        <div class="border-t border-[var(--ui-border)] px-6 py-3 flex justify-end gap-3">
                            <x-ui-button variant="secondary-outline" type="button" wire:click="closeModal">
                                Abbrechen
                            </x-ui-button>
                            <x-ui-button variant="primary" type="submit">
                                Speichern
                            </x-ui-button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif
</x-ui-page>
