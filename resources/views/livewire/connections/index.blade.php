<div>
    <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
        <h1 class="text-2xl font-semibold text-gray-900 dark:text-white mb-6">Meine Connectoren</h1>

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
        @if ($syncError)
            <div class="mb-4 rounded-md bg-red-50 dark:bg-red-900/20 p-4">
                <p class="text-sm text-red-700 dark:text-red-400">{{ $syncError }}</p>
            </div>
        @endif
        @if ($syncMessage)
            <div class="mb-4 rounded-md bg-green-50 dark:bg-green-900/20 p-4">
                <p class="text-sm text-green-700 dark:text-green-400">{{ $syncMessage }}</p>
            </div>
        @endif

        {{-- Available Connectors --}}
        <div class="mb-8">
            <h2 class="text-lg font-medium text-gray-900 dark:text-white mb-4">Verfügbare Connectoren</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                @foreach ($connectors as $connector)
                    <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-4 border border-gray-200 dark:border-gray-700">
                        <div class="flex items-center justify-between mb-2">
                            <h3 class="text-sm font-medium text-gray-900 dark:text-white">{{ $connector->name }}</h3>
                            @if (in_array('oauth2', $connector->supported_auth_schemes ?? []))
                                <button
                                    wire:click="startOAuth('{{ $connector->key }}')"
                                    class="inline-flex items-center px-3 py-1.5 text-xs font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700"
                                >
                                    Verbinden
                                </button>
                            @endif
                        </div>
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ $connector->meta['description'] ?? '' }}</p>
                        @if ($connector->capabilities)
                            <div class="mt-2 flex flex-wrap gap-1">
                                @foreach ($connector->capabilities as $cap)
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 dark:bg-gray-700 text-gray-800 dark:text-gray-300">
                                        {{ $cap }}
                                    </span>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>

        {{-- My Connections --}}
        <div class="mb-8">
            <h2 class="text-lg font-medium text-gray-900 dark:text-white mb-4">Meine Verbindungen</h2>
            @if ($connections->isEmpty())
                <p class="text-sm text-gray-500 dark:text-gray-400">Noch keine Verbindungen eingerichtet.</p>
            @else
                <div class="space-y-3">
                    @foreach ($connections as $connection)
                        <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-4 border border-gray-200 dark:border-gray-700">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center space-x-3">
                                    <div>
                                        <h3 class="text-sm font-medium text-gray-900 dark:text-white">
                                            {{ $connection->name }}
                                            @if ($connection->is_default)
                                                <span class="ml-1 text-xs text-indigo-600 dark:text-indigo-400">(Standard)</span>
                                            @endif
                                        </h3>
                                        <p class="text-xs text-gray-500 dark:text-gray-400">
                                            {{ $connection->connector?->name ?? '?' }} &middot;
                                            <span class="@if($connection->status === 'active') text-green-600 dark:text-green-400 @elseif($connection->status === 'error') text-red-600 dark:text-red-400 @else text-yellow-600 dark:text-yellow-400 @endif">
                                                {{ $connection->status }}
                                            </span>
                                            @if ($connection->last_tested_at)
                                                &middot; Getestet: {{ $connection->last_tested_at->diffForHumans() }}
                                            @endif
                                        </p>
                                        @if ($connection->last_error)
                                            <p class="text-xs text-red-500 mt-1">{{ $connection->last_error }}</p>
                                        @endif
                                    </div>
                                </div>
                                <div class="flex items-center space-x-2">
                                    <button wire:click="testConnection({{ $connection->id }})" class="text-xs text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white">
                                        Testen
                                    </button>
                                    <button wire:click="reconnect({{ $connection->id }})" class="text-xs text-indigo-600 dark:text-indigo-400 hover:text-indigo-900 dark:hover:text-indigo-300">
                                        Reconnect
                                    </button>
                                    @if (!$connection->is_default)
                                        <button wire:click="setDefault({{ $connection->id }})" class="text-xs text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white">
                                            Standard
                                        </button>
                                    @endif
                                    <button wire:click="deleteConnection({{ $connection->id }})" wire:confirm="Verbindung wirklich löschen?" class="text-xs text-red-600 dark:text-red-400 hover:text-red-900 dark:hover:text-red-300">
                                        Löschen
                                    </button>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- Shared With Me --}}
        @if ($sharedWithMe->isNotEmpty())
            <div>
                <h2 class="text-lg font-medium text-gray-900 dark:text-white mb-4">Mit mir geteilte Verbindungen</h2>
                <div class="space-y-3">
                    @foreach ($sharedWithMe as $shared)
                        <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-4 border border-gray-200 dark:border-gray-700 opacity-75">
                            <h3 class="text-sm font-medium text-gray-900 dark:text-white">{{ $shared->name }}</h3>
                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                {{ $shared->connector?->name ?? '?' }} &middot;
                                von {{ $shared->ownerUser?->name ?? '?' }} &middot;
                                {{ $shared->status }}
                            </p>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </div>
</div>
