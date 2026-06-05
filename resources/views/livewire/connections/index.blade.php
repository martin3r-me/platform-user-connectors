<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="Meine Verbindungen" icon="heroicon-o-link" />
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
                                    <x-ui-button variant="secondary-outline" size="sm" wire:click="openSettings({{ $connection->id }})">
                                        @svg('heroicon-o-cog-6-tooth', 'w-4 h-4')
                                        Einstellungen
                                    </x-ui-button>
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

        {{-- Mail Sessions --}}
        <x-ui-panel title="E-Mails" subtitle="Eingehende und ausgehende E-Mails deiner Verbindungen" wire:poll.5s>
            @if ($mailSessions->isEmpty())
                <p class="text-sm text-[var(--ui-muted)]">Noch keine E-Mails erfasst.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-[var(--ui-border)]/60">
                                <th class="text-left py-2 px-2 text-xs font-medium text-[var(--ui-muted)]">Zeit</th>
                                <th class="text-left py-2 px-2 text-xs font-medium text-[var(--ui-muted)]">Connector</th>
                                <th class="text-left py-2 px-2 text-xs font-medium text-[var(--ui-muted)]">Richtung</th>
                                <th class="text-left py-2 px-2 text-xs font-medium text-[var(--ui-muted)]">Von</th>
                                <th class="text-left py-2 px-2 text-xs font-medium text-[var(--ui-muted)]">Betreff</th>
                                <th class="text-left py-2 px-2 text-xs font-medium text-[var(--ui-muted)]">Status</th>
                                <th class="text-left py-2 px-2 text-xs font-medium text-[var(--ui-muted)]"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[var(--ui-border)]/40">
                            @foreach ($mailSessions as $mail)
                                <tr class="hover:bg-[var(--ui-bg)] {{ $mail->isUnread() ? 'font-medium' : '' }}">
                                    <td class="py-2 px-2 text-xs text-[var(--ui-muted)] whitespace-nowrap">
                                        {{ $mail->received_at?->format('d.m. H:i') ?? $mail->created_at->format('d.m. H:i') }}
                                    </td>
                                    <td class="py-2 px-2 text-xs">
                                        <x-ui-badge size="sm" variant="neutral">{{ $mail->connector_key }}</x-ui-badge>
                                    </td>
                                    <td class="py-2 px-2 text-xs">
                                        @if ($mail->direction === 'inbound')
                                            <span class="text-green-600">&#8592; eingehend</span>
                                        @elseif ($mail->direction === 'outbound')
                                            <span class="text-blue-600">&#8594; ausgehend</span>
                                        @else
                                            <span class="text-[var(--ui-muted)]">-</span>
                                        @endif
                                    </td>
                                    <td class="py-2 px-2 text-xs text-[var(--ui-secondary)] max-w-[150px] truncate" title="{{ $mail->from_address }}">
                                        {{ $mail->from_name ?? $mail->from_address ?? '-' }}
                                    </td>
                                    <td class="py-2 px-2 text-xs text-[var(--ui-secondary)] max-w-[250px] truncate" title="{{ $mail->subject }}">
                                        @if ($mail->shared_mailbox)
                                            <span class="text-[var(--ui-muted)]">[{{ $mail->shared_mailbox }}]</span>
                                        @endif
                                        {{ $mail->subject ?? '-' }}
                                    </td>
                                    <td class="py-2 px-2 text-xs">
                                        @if ($mail->isUnread())
                                            <x-ui-badge size="sm" variant="primary">Neu</x-ui-badge>
                                        @else
                                            <x-ui-badge size="sm" variant="neutral">Gelesen</x-ui-badge>
                                        @endif
                                    </td>
                                    <td class="py-2 px-2 text-xs">
                                        @if ($mail->has_attachments)
                                            @svg('heroicon-o-paper-clip', 'w-4 h-4 text-[var(--ui-muted)]')
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-ui-panel>

        {{-- Meeting Sessions --}}
        <x-ui-panel title="Termine" subtitle="Kalender-Termine deiner Verbindungen" wire:poll.5s>
            @if ($meetingSessions->isEmpty())
                <p class="text-sm text-[var(--ui-muted)]">Noch keine Termine erfasst.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-[var(--ui-border)]/60">
                                <th class="text-left py-2 px-2 text-xs font-medium text-[var(--ui-muted)]">Zeit</th>
                                <th class="text-left py-2 px-2 text-xs font-medium text-[var(--ui-muted)]">Connector</th>
                                <th class="text-left py-2 px-2 text-xs font-medium text-[var(--ui-muted)]">Richtung</th>
                                <th class="text-left py-2 px-2 text-xs font-medium text-[var(--ui-muted)]">Organizer</th>
                                <th class="text-left py-2 px-2 text-xs font-medium text-[var(--ui-muted)]">Betreff</th>
                                <th class="text-left py-2 px-2 text-xs font-medium text-[var(--ui-muted)]">Ort</th>
                                <th class="text-left py-2 px-2 text-xs font-medium text-[var(--ui-muted)]">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[var(--ui-border)]/40">
                            @foreach ($meetingSessions as $meeting)
                                <tr class="hover:bg-[var(--ui-bg)]">
                                    <td class="py-2 px-2 text-xs text-[var(--ui-muted)] whitespace-nowrap">
                                        @if ($meeting->start_at)
                                            {{ $meeting->start_at->format('d.m. H:i') }}
                                            @if ($meeting->end_at)
                                                – {{ $meeting->end_at->format('H:i') }}
                                            @endif
                                        @else
                                            {{ $meeting->created_at->format('d.m. H:i') }}
                                        @endif
                                    </td>
                                    <td class="py-2 px-2 text-xs">
                                        <x-ui-badge size="sm" variant="neutral">{{ $meeting->connector_key }}</x-ui-badge>
                                    </td>
                                    <td class="py-2 px-2 text-xs">
                                        @if ($meeting->direction === 'inbound')
                                            <span class="text-green-600">&#8592; Teilnehmer</span>
                                        @elseif ($meeting->direction === 'outbound')
                                            <span class="text-blue-600">&#8594; Organizer</span>
                                        @else
                                            <span class="text-[var(--ui-muted)]">-</span>
                                        @endif
                                    </td>
                                    <td class="py-2 px-2 text-xs text-[var(--ui-secondary)] max-w-[150px] truncate" title="{{ $meeting->organizer_address }}">
                                        {{ $meeting->organizer_name ?? $meeting->organizer_address ?? '-' }}
                                    </td>
                                    <td class="py-2 px-2 text-xs text-[var(--ui-secondary)] max-w-[200px] truncate" title="{{ $meeting->subject }}">
                                        {{ $meeting->subject ?? '-' }}
                                    </td>
                                    <td class="py-2 px-2 text-xs text-[var(--ui-muted)] max-w-[150px] truncate" title="{{ $meeting->location }}">
                                        @if ($meeting->is_online_meeting)
                                            @svg('heroicon-o-video-camera', 'w-3.5 h-3.5 inline text-blue-500')
                                        @endif
                                        {{ $meeting->location ?? ($meeting->is_online_meeting ? 'Online' : '-') }}
                                    </td>
                                    <td class="py-2 px-2 text-xs">
                                        @switch($meeting->status)
                                            @case('upcoming')
                                                <x-ui-badge size="sm" variant="primary">Bevorstehend</x-ui-badge>
                                                @break
                                            @case('in_progress')
                                                <x-ui-badge size="sm" variant="success" class="animate-pulse">Läuft</x-ui-badge>
                                                @break
                                            @case('completed')
                                                <x-ui-badge size="sm" variant="success">Abgeschlossen</x-ui-badge>
                                                @break
                                            @case('cancelled')
                                                <x-ui-badge size="sm" variant="warning">Abgesagt</x-ui-badge>
                                                @break
                                            @case('deleted')
                                                <x-ui-badge size="sm" variant="danger">Gelöscht</x-ui-badge>
                                                @break
                                            @default
                                                <x-ui-badge size="sm" variant="neutral">{{ $meeting->status }}</x-ui-badge>
                                        @endswitch
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-ui-panel>

        {{-- Message Sessions --}}
        <x-ui-panel title="Nachrichten" subtitle="Teams Chat & SMS deiner Verbindungen" wire:poll.5s>
            @if ($messageSessions->isEmpty())
                <p class="text-sm text-[var(--ui-muted)]">Noch keine Nachrichten erfasst.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-[var(--ui-border)]/60">
                                <th class="text-left py-2 px-2 text-xs font-medium text-[var(--ui-muted)]">Zeit</th>
                                <th class="text-left py-2 px-2 text-xs font-medium text-[var(--ui-muted)]">Connector</th>
                                <th class="text-left py-2 px-2 text-xs font-medium text-[var(--ui-muted)]">Typ</th>
                                <th class="text-left py-2 px-2 text-xs font-medium text-[var(--ui-muted)]">Richtung</th>
                                <th class="text-left py-2 px-2 text-xs font-medium text-[var(--ui-muted)]">Von</th>
                                <th class="text-left py-2 px-2 text-xs font-medium text-[var(--ui-muted)]">An</th>
                                <th class="text-left py-2 px-2 text-xs font-medium text-[var(--ui-muted)]">Nachricht</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[var(--ui-border)]/40">
                            @foreach ($messageSessions as $msg)
                                <tr class="hover:bg-[var(--ui-bg)]">
                                    <td class="py-2 px-2 text-xs text-[var(--ui-muted)] whitespace-nowrap">
                                        {{ $msg->sent_at?->format('d.m. H:i') ?? $msg->created_at->format('d.m. H:i') }}
                                    </td>
                                    <td class="py-2 px-2 text-xs">
                                        <x-ui-badge size="sm" variant="neutral">{{ $msg->connector_key }}</x-ui-badge>
                                    </td>
                                    <td class="py-2 px-2 text-xs">
                                        @if ($msg->isTeamsChat())
                                            <x-ui-badge size="sm" variant="primary">Teams</x-ui-badge>
                                        @else
                                            <x-ui-badge size="sm" variant="warning">SMS</x-ui-badge>
                                        @endif
                                    </td>
                                    <td class="py-2 px-2 text-xs">
                                        @if ($msg->direction === 'inbound')
                                            <span class="text-green-600">&#8592; eingehend</span>
                                        @elseif ($msg->direction === 'outbound')
                                            <span class="text-blue-600">&#8594; ausgehend</span>
                                        @else
                                            <span class="text-[var(--ui-muted)]">-</span>
                                        @endif
                                    </td>
                                    <td class="py-2 px-2 text-xs text-[var(--ui-secondary)]">
                                        {{ $msg->from_identifier ?? '-' }}
                                    </td>
                                    <td class="py-2 px-2 text-xs text-[var(--ui-secondary)] max-w-[150px] truncate" title="{{ $msg->to_identifier }}">
                                        {{ $msg->to_identifier ?? '-' }}
                                    </td>
                                    <td class="py-2 px-2 text-xs text-[var(--ui-muted)] max-w-[300px] truncate" title="{{ $msg->body_preview }}">
                                        {{ \Illuminate\Support\Str::limit($msg->body_preview, 80) ?? '-' }}
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
                                                if (!empty($meta['sharedMailbox'])) {
                                                    $context = '[' . $meta['sharedMailbox'] . '] ' . $context;
                                                }
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

    {{-- Settings Modal --}}
    @if ($settingsModal)
        @php
            $settingsConnection = \Platform\UserConnectors\Models\UserConnectorConnection::with('connector')->find($settingsConnectionId);
        @endphp
        <div class="fixed inset-0 z-50 overflow-y-auto" aria-labelledby="settings-modal-title" role="dialog" aria-modal="true">
            <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
                <div class="fixed inset-0 bg-gray-500/75 transition-opacity" wire:click="closeSettings"></div>
                <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>

                <div class="relative inline-block align-bottom bg-white rounded-2xl text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg sm:w-full">
                    <div class="px-6 pt-5 pb-4">
                        <h3 class="text-lg font-medium text-[var(--ui-secondary)] mb-1" id="settings-modal-title">
                            Verbindungs-Einstellungen
                        </h3>
                        @if ($settingsConnection)
                            <p class="text-sm text-[var(--ui-muted)] mb-5">
                                {{ $settingsConnection->name }} ({{ $settingsConnection->connector?->name ?? '?' }})
                            </p>
                        @endif

                        <div class="space-y-6">
                            {{-- Webhooks --}}
                            <div>
                                <h4 class="text-sm font-medium text-[var(--ui-secondary)] mb-3">Webhooks</h4>
                                <label class="flex items-center justify-between cursor-pointer">
                                    <span class="text-sm text-[var(--ui-secondary)]">Webhook-Subscriptions aktiv</span>
                                    <button
                                        type="button"
                                        wire:click="$toggle('settingsSubscriptionsEnabled')"
                                        class="relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-[var(--ui-primary)] focus:ring-offset-2 {{ $settingsSubscriptionsEnabled ? 'bg-[var(--ui-primary)]' : 'bg-gray-200' }}"
                                        role="switch"
                                        aria-checked="{{ $settingsSubscriptionsEnabled ? 'true' : 'false' }}"
                                    >
                                        <span class="pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out {{ $settingsSubscriptionsEnabled ? 'translate-x-5' : 'translate-x-0' }}"></span>
                                    </button>
                                </label>
                            </div>

                            {{-- Aufnahmen --}}
                            <div>
                                <h4 class="text-sm font-medium text-[var(--ui-secondary)] mb-3">Aufnahmen</h4>
                                <label class="flex items-center justify-between cursor-pointer">
                                    <span class="text-sm text-[var(--ui-secondary)]">Anruf-Aufnahmen automatisch speichern</span>
                                    <button
                                        type="button"
                                        wire:click="$toggle('settingsRecordingsEnabled')"
                                        class="relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-[var(--ui-primary)] focus:ring-offset-2 {{ $settingsRecordingsEnabled ? 'bg-[var(--ui-primary)]' : 'bg-gray-200' }}"
                                        role="switch"
                                        aria-checked="{{ $settingsRecordingsEnabled ? 'true' : 'false' }}"
                                    >
                                        <span class="pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out {{ $settingsRecordingsEnabled ? 'translate-x-5' : 'translate-x-0' }}"></span>
                                    </button>
                                </label>
                            </div>

                            {{-- CRM Integration --}}
                            <div>
                                <h4 class="text-sm font-medium text-[var(--ui-secondary)] mb-3">CRM-Integration</h4>
                                <div class="space-y-3">
                                    <label class="flex items-center justify-between cursor-pointer">
                                        <span class="text-sm text-[var(--ui-secondary)]">Engagement anlegen wenn Kontakt im CRM vorhanden</span>
                                        <button
                                            type="button"
                                            wire:click="$toggle('settingsCrmCreateEngagement')"
                                            class="relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-[var(--ui-primary)] focus:ring-offset-2 {{ $settingsCrmCreateEngagement ? 'bg-[var(--ui-primary)]' : 'bg-gray-200' }}"
                                            role="switch"
                                            aria-checked="{{ $settingsCrmCreateEngagement ? 'true' : 'false' }}"
                                        >
                                            <span class="pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out {{ $settingsCrmCreateEngagement ? 'translate-x-5' : 'translate-x-0' }}"></span>
                                        </button>
                                    </label>
                                    <label class="flex items-center justify-between cursor-pointer">
                                        <span class="text-sm text-[var(--ui-secondary)]">Kontakt anlegen wenn nicht im CRM gefunden</span>
                                        <button
                                            type="button"
                                            wire:click="$toggle('settingsCrmCreateContact')"
                                            class="relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-[var(--ui-primary)] focus:ring-offset-2 {{ $settingsCrmCreateContact ? 'bg-[var(--ui-primary)]' : 'bg-gray-200' }}"
                                            role="switch"
                                            aria-checked="{{ $settingsCrmCreateContact ? 'true' : 'false' }}"
                                        >
                                            <span class="pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out {{ $settingsCrmCreateContact ? 'translate-x-5' : 'translate-x-0' }}"></span>
                                        </button>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="border-t border-[var(--ui-border)] px-6 py-3 flex justify-end gap-2">
                        <x-ui-button variant="secondary-outline" wire:click="closeSettings">
                            Abbrechen
                        </x-ui-button>
                        <x-ui-button variant="primary" wire:click="saveSettings">
                            Speichern
                        </x-ui-button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</x-ui-page>
