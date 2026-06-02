<div>
    <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
        <h1 class="text-2xl font-semibold text-gray-900 dark:text-white mb-6">Connector-Settings</h1>

        {{-- Flash Messages --}}
        @if (session()->has('status'))
            <div class="mb-4 rounded-md bg-green-50 dark:bg-green-900/20 p-4">
                <p class="text-sm text-green-700 dark:text-green-400">{{ session('status') }}</p>
            </div>
        @endif
        @if (session()->has('error'))
            <div class="mb-4 rounded-md bg-red-50 dark:bg-red-900/20 p-4">
                <p class="text-sm text-red-700 dark:text-red-400">{{ session('error') }}</p>
            </div>
        @endif

        {{-- Connector Cards --}}
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            @foreach ($connectors as $connector)
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-5 border border-gray-200 dark:border-gray-700">
                    <div class="flex items-center justify-between mb-3">
                        <h3 class="text-sm font-medium text-gray-900 dark:text-white">{{ $connector->name }}</h3>
                        @if ($connector->is_configured)
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 dark:bg-green-900/30 text-green-800 dark:text-green-400">
                                Konfiguriert
                            </span>
                        @else
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-400">
                                Nicht konfiguriert
                            </span>
                        @endif
                    </div>

                    <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">{{ $connector->meta['description'] ?? '' }}</p>

                    <div class="flex items-center justify-between">
                        {{-- Enable/Disable Toggle --}}
                        <button
                            wire:click="toggleEnabled({{ $connector->id }})"
                            class="relative inline-flex h-5 w-9 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none {{ $connector->is_enabled ? 'bg-indigo-600' : 'bg-gray-300 dark:bg-gray-600' }}"
                            role="switch"
                            aria-checked="{{ $connector->is_enabled ? 'true' : 'false' }}"
                        >
                            <span class="pointer-events-none inline-block h-4 w-4 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out {{ $connector->is_enabled ? 'translate-x-4' : 'translate-x-0' }}"></span>
                        </button>

                        {{-- Edit Button --}}
                        <button
                            wire:click="editConnector({{ $connector->id }})"
                            class="inline-flex items-center px-3 py-1.5 text-xs font-medium rounded-md text-gray-700 dark:text-gray-300 bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600"
                        >
                            Konfigurieren
                        </button>
                    </div>
                </div>
            @endforeach
        </div>

        <p class="mt-4 text-xs text-gray-400 dark:text-gray-500">
            Credentials werden verschlüsselt in der Datenbank gespeichert.
        </p>
    </div>

    {{-- Edit Modal --}}
    @if ($modalShow)
        <div class="fixed inset-0 z-50 overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true">
            <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
                {{-- Backdrop --}}
                <div class="fixed inset-0 bg-gray-500/75 dark:bg-gray-900/75 transition-opacity" wire:click="closeModal"></div>

                <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>

                {{-- Modal Panel --}}
                <div class="relative inline-block align-bottom bg-white dark:bg-gray-800 rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg sm:w-full">
                    <form wire:submit="saveConnector">
                        <div class="px-6 pt-5 pb-4">
                            <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-4" id="modal-title">
                                OAuth-Konfiguration
                            </h3>

                            <div class="space-y-4">
                                {{-- Authorize URL --}}
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Authorize URL</label>
                                    <input type="url" wire:model="authorizeUrl" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm" placeholder="https://..." required>
                                    @error('authorizeUrl') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                                </div>

                                {{-- Token URL --}}
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Token URL</label>
                                    <input type="url" wire:model="tokenUrl" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm" placeholder="https://..." required>
                                    @error('tokenUrl') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                                </div>

                                {{-- Client ID --}}
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Client ID</label>
                                    <input type="text" wire:model="clientId" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm" required>
                                    @error('clientId') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                                </div>

                                {{-- Client Secret --}}
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Client Secret</label>
                                    <input type="password" wire:model="clientSecret" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm" placeholder="Leer lassen = bestehenden Wert behalten">
                                    @error('clientSecret') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                                </div>

                                {{-- Redirect Domain --}}
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Redirect Domain <span class="text-gray-400">(optional)</span></label>
                                    <input type="url" wire:model="redirectDomain" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm" placeholder="https://...">
                                    @error('redirectDomain') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                                </div>

                                {{-- Scopes --}}
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Scopes <span class="text-gray-400">(kommagetrennt)</span></label>
                                    <input type="text" wire:model="scopes" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm" placeholder="openid, profile, email">
                                    @error('scopes') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                                </div>

                                {{-- Extra Params --}}
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Extra-Parameter <span class="text-gray-400">(JSON, optional)</span></label>
                                    <textarea wire:model="extraParams" rows="2" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm font-mono text-xs" placeholder='{"prompt": "consent"}'></textarea>
                                    @error('extraParams') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                                </div>
                            </div>
                        </div>

                        <div class="bg-gray-50 dark:bg-gray-700/50 px-6 py-3 flex justify-end space-x-3">
                            <button type="button" wire:click="closeModal" class="inline-flex items-center px-4 py-2 text-sm font-medium rounded-md text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-600">
                                Abbrechen
                            </button>
                            <button type="submit" class="inline-flex items-center px-4 py-2 text-sm font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700 border border-transparent">
                                Speichern
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif
</div>
