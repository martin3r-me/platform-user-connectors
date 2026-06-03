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
                                    {{-- Phonelines with Numbers & Devices --}}
                                    @if ($connection->phoneNumbers->isNotEmpty() || $connection->devices->isNotEmpty())
                                        @php
                                            $phonelines = $connection->phoneNumbers->groupBy(fn ($p) => $p->external_id ?? '_none');
                                            $devicesByPhoneline = $connection->devices->groupBy(fn ($d) => collect($d->meta['activePhonelines'] ?? [])->pluck('id')->first() ?? '_none');
                                        @endphp
                                        <div class="mt-2 space-y-1.5">
                                            @foreach ($phonelines as $plId => $numbers)
                                                @php $plAlias = $numbers->first()->meta['phonelineAlias'] ?? $plId; @endphp
                                                <div class="flex flex-wrap items-center gap-1">
                                                    <span class="text-xs font-medium text-[var(--ui-secondary)]">@svg('heroicon-o-phone', 'w-3.5 h-3.5 inline') {{ $plAlias }}:</span>
                                                    @foreach ($numbers as $phone)
                                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-700">
                                                            {{ $phone->number }}
                                                            @foreach ($phone->capabilities ?? [] as $cap)
                                                                @if ($cap === 'sms')
                                                                    @svg('heroicon-o-chat-bubble-left', 'w-3 h-3 text-blue-500')
                                                                @elseif ($cap === 'fax')
                                                                    @svg('heroicon-o-printer', 'w-3 h-3 text-orange-500')
                                                                @endif
                                                            @endforeach
                                                            @if ($phone->is_default)
                                                                @svg('heroicon-o-star', 'w-3 h-3 text-yellow-500')
                                                            @endif
                                                        </span>
                                                    @endforeach
                                                    {{-- Devices on this phoneline --}}
                                                    @foreach ($devicesByPhoneline->get($plId, collect()) as $device)
                                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-gray-50 text-gray-600 border border-gray-200">
                                                            @if ($device->is_online === true)
                                                                <span class="w-1.5 h-1.5 rounded-full bg-green-500"></span>
                                                            @elseif ($device->is_online === false)
                                                                <span class="w-1.5 h-1.5 rounded-full bg-red-400"></span>
                                                            @else
                                                                <span class="w-1.5 h-1.5 rounded-full bg-gray-300"></span>
                                                            @endif
                                                            {{ $device->name }}
                                                        </span>
                                                    @endforeach
                                                </div>
                                            @endforeach
                                            {{-- Devices not assigned to any phoneline --}}
                                            @foreach ($devicesByPhoneline->get('_none', collect()) as $device)
                                                <div class="flex items-center gap-1">
                                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-gray-50 text-gray-600 border border-gray-200">
                                                        @if ($device->is_online === true)
                                                            <span class="w-1.5 h-1.5 rounded-full bg-green-500"></span>
                                                        @elseif ($device->is_online === false)
                                                            <span class="w-1.5 h-1.5 rounded-full bg-red-400"></span>
                                                        @else
                                                            <span class="w-1.5 h-1.5 rounded-full bg-gray-300"></span>
                                                        @endif
                                                        {{ $device->name }}
                                                        <span class="text-[var(--ui-muted)]">({{ $device->type }})</span>
                                                    </span>
                                                </div>
                                            @endforeach
                                        </div>
                                    @endif
                                    {{-- Sync timestamp --}}
                                    @php $syncedAt = $connection->credentials['profile']['synced_at'] ?? null; @endphp
                                    @if ($syncedAt)
                                        <p class="mt-1 text-xs text-[var(--ui-muted)]">
                                            Sync: {{ \Carbon\Carbon::parse($syncedAt)->diffForHumans() }}
                                        </p>
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
        {{-- Call Sessions --}}
        <x-ui-panel title="Anrufe" subtitle="Gruppierte Anruf-Sessions deiner Verbindungen" wire:poll.5s>
            @if ($callSessions->isEmpty())
                <p class="text-sm text-[var(--ui-muted)]">Noch keine Anrufe erfasst.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-[var(--ui-border)]/60">
                                <th class="text-left py-2 px-2 text-xs font-medium text-[var(--ui-muted)]">Zeit</th>
                                <th class="text-left py-2 px-2 text-xs font-medium text-[var(--ui-muted)]">Connector</th>
                                <th class="text-left py-2 px-2 text-xs font-medium text-[var(--ui-muted)]">Richtung</th>
                                <th class="text-left py-2 px-2 text-xs font-medium text-[var(--ui-muted)]">Von</th>
                                <th class="text-left py-2 px-2 text-xs font-medium text-[var(--ui-muted)]">An</th>
                                <th class="text-left py-2 px-2 text-xs font-medium text-[var(--ui-muted)]">Status</th>
                                <th class="text-left py-2 px-2 text-xs font-medium text-[var(--ui-muted)]">Dauer</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[var(--ui-border)]/40">
                            @foreach ($callSessions as $session)
                                <tr class="hover:bg-[var(--ui-bg)]">
                                    <td class="py-2 px-2 text-xs text-[var(--ui-muted)] whitespace-nowrap">
                                        {{ $session->started_at?->format('d.m. H:i:s') ?? $session->created_at->format('d.m. H:i:s') }}
                                    </td>
                                    <td class="py-2 px-2 text-xs">
                                        <x-ui-badge size="sm" variant="neutral">{{ $session->connector_key }}</x-ui-badge>
                                    </td>
                                    <td class="py-2 px-2 text-xs">
                                        @if ($session->direction === 'inbound')
                                            <span class="text-green-600">&#8592; eingehend</span>
                                        @elseif ($session->direction === 'outbound')
                                            <span class="text-blue-600">&#8594; ausgehend</span>
                                        @else
                                            <span class="text-[var(--ui-muted)]">-</span>
                                        @endif
                                    </td>
                                    <td class="py-2 px-2 text-xs text-[var(--ui-secondary)]">{{ $session->from_number ?? '-' }}</td>
                                    <td class="py-2 px-2 text-xs text-[var(--ui-secondary)]">{{ $session->to_number ?? '-' }}</td>
                                    <td class="py-2 px-2 text-xs">
                                        @switch($session->status)
                                            @case('ringing')
                                                <x-ui-badge size="sm" variant="warning" class="animate-pulse">Klingelt</x-ui-badge>
                                                @break
                                            @case('active')
                                                <x-ui-badge size="sm" variant="success" class="animate-pulse">Aktiv</x-ui-badge>
                                                @break
                                            @case('completed')
                                                <x-ui-badge size="sm" variant="success">Abgeschlossen</x-ui-badge>
                                                @break
                                            @case('missed')
                                                <x-ui-badge size="sm" variant="danger">Verpasst</x-ui-badge>
                                                @break
                                            @case('busy')
                                                <x-ui-badge size="sm" variant="warning">Besetzt</x-ui-badge>
                                                @break
                                            @case('cancelled')
                                                <x-ui-badge size="sm" variant="neutral">Abgebrochen</x-ui-badge>
                                                @break
                                            @default
                                                <x-ui-badge size="sm" variant="neutral">{{ $session->status }}</x-ui-badge>
                                        @endswitch
                                    </td>
                                    <td class="py-2 px-2 text-xs text-[var(--ui-secondary)]">
                                        {{ $session->durationForHumans() ?? '-' }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-ui-panel>

        {{-- Inbound Event Log --}}
        <x-ui-panel title="Event-Log" subtitle="Eingehende Webhook-Events deiner Verbindungen" wire:poll.5s>
            @if ($recentEvents->isEmpty())
                <p class="text-sm text-[var(--ui-muted)]">Noch keine Events empfangen. Teste z.B. einen eingehenden Anruf.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-[var(--ui-border)]/60">
                                <th class="text-left py-2 px-2 text-xs font-medium text-[var(--ui-muted)]">Zeit</th>
                                <th class="text-left py-2 px-2 text-xs font-medium text-[var(--ui-muted)]">Connector</th>
                                <th class="text-left py-2 px-2 text-xs font-medium text-[var(--ui-muted)]">Event</th>
                                <th class="text-left py-2 px-2 text-xs font-medium text-[var(--ui-muted)]">Kontext</th>
                                <th class="text-left py-2 px-2 text-xs font-medium text-[var(--ui-muted)]">Richtung</th>
                                <th class="text-left py-2 px-2 text-xs font-medium text-[var(--ui-muted)]">Von</th>
                                <th class="text-left py-2 px-2 text-xs font-medium text-[var(--ui-muted)]">An</th>
                                <th class="text-left py-2 px-2 text-xs font-medium text-[var(--ui-muted)]">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[var(--ui-border)]/40">
                            @foreach ($recentEvents as $event)
                                <tr class="hover:bg-[var(--ui-bg)]">
                                    <td class="py-2 px-2 text-xs text-[var(--ui-muted)] whitespace-nowrap">
                                        {{ $event->created_at->format('d.m. H:i:s') }}
                                    </td>
                                    <td class="py-2 px-2 text-xs">
                                        <x-ui-badge size="sm" variant="neutral">{{ $event->connector_key }}</x-ui-badge>
                                    </td>
                                    <td class="py-2 px-2 text-xs font-medium text-[var(--ui-secondary)]">
                                        {{ $event->event_type }}
                                    </td>
                                    <td class="py-2 px-2 text-xs text-[var(--ui-muted)] max-w-[200px] truncate" title="{{ $event->meta['subject'] ?? $event->meta['bodyPreview'] ?? '' }}">
                                        @php
                                            $meta = $event->meta ?? [];
                                            $context = null;
                                            if (str_starts_with($event->event_type, 'mail.')) {
                                                $context = \Illuminate\Support\Str::limit($meta['subject'] ?? '', 60);
                                            } elseif (str_starts_with($event->event_type, 'calendar.')) {
                                                $context = \Illuminate\Support\Str::limit($meta['subject'] ?? '', 40);
                                                if (!empty($meta['start'])) {
                                                    $context .= ' · ' . \Carbon\Carbon::parse($meta['start'])->format('d.m. H:i');
                                                    if (!empty($meta['end'])) {
                                                        $context .= '–' . \Carbon\Carbon::parse($meta['end'])->format('H:i');
                                                    }
                                                }
                                            } elseif (str_starts_with($event->event_type, 'teams.')) {
                                                $context = \Illuminate\Support\Str::limit($meta['bodyPreview'] ?? '', 60);
                                            }
                                        @endphp
                                        {{ $context ?? '-' }}
                                    </td>
                                    <td class="py-2 px-2 text-xs">
                                        @if ($event->direction === 'inbound')
                                            <span class="text-green-600">&#8592; eingehend</span>
                                        @elseif ($event->direction === 'outbound')
                                            <span class="text-blue-600">&#8594; ausgehend</span>
                                        @else
                                            <span class="text-[var(--ui-muted)]">-</span>
                                        @endif
                                    </td>
                                    <td class="py-2 px-2 text-xs text-[var(--ui-secondary)]">{{ $event->from_identifier ?? '-' }}</td>
                                    <td class="py-2 px-2 text-xs text-[var(--ui-secondary)]">{{ $event->to_identifier ?? '-' }}</td>
                                    <td class="py-2 px-2 text-xs">
                                        @if ($event->processing_status === 'processed')
                                            <x-ui-badge size="sm" variant="success">OK</x-ui-badge>
                                        @elseif ($event->processing_status === 'failed')
                                            <x-ui-badge size="sm" variant="danger">Fehler</x-ui-badge>
                                        @else
                                            <x-ui-badge size="sm" variant="warning">{{ $event->processing_status }}</x-ui-badge>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-ui-panel>

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
