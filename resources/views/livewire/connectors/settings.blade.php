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

        {{-- Connector Cards --}}
        <x-ui-panel title="OAuth-Konfiguration" subtitle="Credentials werden verschlüsselt in der Datenbank gespeichert.">
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                @foreach ($connectors as $connector)
                    <div class="p-5 rounded-xl border border-[var(--ui-border)]/60 bg-white">
                        <div class="flex items-center justify-between mb-3">
                            <h3 class="text-sm font-medium text-[var(--ui-secondary)]">{{ $connector->name }}</h3>
                            @if ($connector->is_configured)
                                <x-ui-badge size="sm" variant="success">Konfiguriert</x-ui-badge>
                            @else
                                <x-ui-badge size="sm" variant="neutral">Nicht konfiguriert</x-ui-badge>
                            @endif
                        </div>

                        <p class="text-xs text-[var(--ui-muted)] mb-4">{{ $connector->meta['description'] ?? '' }}</p>

                        <div class="flex items-center justify-between">
                            {{-- Enable/Disable Toggle --}}
                            <button
                                wire:click="toggleEnabled({{ $connector->id }})"
                                class="relative inline-flex h-5 w-9 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none {{ $connector->is_enabled ? 'bg-[var(--ui-primary)]' : 'bg-[var(--ui-border)]' }}"
                                role="switch"
                                aria-checked="{{ $connector->is_enabled ? 'true' : 'false' }}"
                            >
                                <span class="pointer-events-none inline-block h-4 w-4 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out {{ $connector->is_enabled ? 'translate-x-4' : 'translate-x-0' }}"></span>
                            </button>

                            {{-- Edit Button --}}
                            <x-ui-button variant="secondary-outline" size="sm" wire:click="editConnector({{ $connector->id }})">
                                Konfigurieren
                            </x-ui-button>
                        </div>
                    </div>
                @endforeach
            </div>
        </x-ui-panel>
    </x-ui-page-container>

    {{-- Edit Modal --}}
    @if ($modalShow)
        <div class="fixed inset-0 z-50 overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true">
            <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
                {{-- Backdrop --}}
                <div class="fixed inset-0 bg-gray-500/75 transition-opacity" wire:click="closeModal"></div>

                <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>

                {{-- Modal Panel --}}
                <div class="relative inline-block align-bottom bg-white rounded-2xl text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg sm:w-full">
                    <form wire:submit="saveConnector">
                        <div class="px-6 pt-5 pb-4">
                            <h3 class="text-lg font-medium text-[var(--ui-secondary)] mb-4" id="modal-title">
                                OAuth-Konfiguration
                            </h3>

                            <div class="space-y-4">
                                {{-- Authorize URL --}}
                                <div>
                                    <label class="block text-sm font-medium text-[var(--ui-secondary)]">Authorize URL</label>
                                    <input type="url" wire:model="authorizeUrl" class="mt-1 block w-full rounded-lg border border-[var(--ui-border)] bg-white text-[var(--ui-secondary)] shadow-sm focus:border-[var(--ui-primary)] focus:ring-[var(--ui-primary)] sm:text-sm" placeholder="https://..." required>
                                    @error('authorizeUrl') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                                </div>

                                {{-- Token URL --}}
                                <div>
                                    <label class="block text-sm font-medium text-[var(--ui-secondary)]">Token URL</label>
                                    <input type="url" wire:model="tokenUrl" class="mt-1 block w-full rounded-lg border border-[var(--ui-border)] bg-white text-[var(--ui-secondary)] shadow-sm focus:border-[var(--ui-primary)] focus:ring-[var(--ui-primary)] sm:text-sm" placeholder="https://..." required>
                                    @error('tokenUrl') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
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
                                    <input type="password" wire:model="clientSecret" class="mt-1 block w-full rounded-lg border border-[var(--ui-border)] bg-white text-[var(--ui-secondary)] shadow-sm focus:border-[var(--ui-primary)] focus:ring-[var(--ui-primary)] sm:text-sm" placeholder="Leer lassen = bestehenden Wert behalten">
                                    @error('clientSecret') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                                </div>

                                {{-- Redirect Domain --}}
                                <div>
                                    <label class="block text-sm font-medium text-[var(--ui-secondary)]">Redirect Domain <span class="text-[var(--ui-muted)]">(optional)</span></label>
                                    <input type="url" wire:model="redirectDomain" class="mt-1 block w-full rounded-lg border border-[var(--ui-border)] bg-white text-[var(--ui-secondary)] shadow-sm focus:border-[var(--ui-primary)] focus:ring-[var(--ui-primary)] sm:text-sm" placeholder="https://...">
                                    @error('redirectDomain') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                                </div>

                                {{-- Scopes --}}
                                <div>
                                    <label class="block text-sm font-medium text-[var(--ui-secondary)]">Scopes <span class="text-[var(--ui-muted)]">(kommagetrennt)</span></label>
                                    <input type="text" wire:model="scopes" class="mt-1 block w-full rounded-lg border border-[var(--ui-border)] bg-white text-[var(--ui-secondary)] shadow-sm focus:border-[var(--ui-primary)] focus:ring-[var(--ui-primary)] sm:text-sm" placeholder="openid, profile, email">
                                    @error('scopes') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                                </div>

                                {{-- Extra Params --}}
                                <div>
                                    <label class="block text-sm font-medium text-[var(--ui-secondary)]">Extra-Parameter <span class="text-[var(--ui-muted)]">(JSON, optional)</span></label>
                                    <textarea wire:model="extraParams" rows="2" class="mt-1 block w-full rounded-lg border border-[var(--ui-border)] bg-white text-[var(--ui-secondary)] shadow-sm focus:border-[var(--ui-primary)] focus:ring-[var(--ui-primary)] sm:text-sm font-mono text-xs" placeholder='{"prompt": "consent"}'></textarea>
                                    @error('extraParams') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
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
