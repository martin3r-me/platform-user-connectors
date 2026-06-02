<div class="space-y-8">
    <x-sidebar-module-header module-name="User Connectors" icon="heroicon-o-link" />

    <div class="space-y-3">
        <p class="text-xs font-semibold tracking-wide uppercase text-[var(--ui-muted)]">Verbindungen</p>
        <div class="space-y-1">
            @php $isActive = request()->routeIs('user-connectors.connections.*'); @endphp
            <a
                href="{{ route('user-connectors.connections.index') }}"
                wire:navigate
                class="flex items-center gap-3 px-3 py-2 rounded-md text-sm font-medium transition-colors"
                @class([
                    'bg-[var(--ui-primary)] text-[var(--ui-on-primary)] shadow-sm' => $isActive,
                    'text-[var(--ui-secondary)] hover:bg-[var(--ui-primary-5)] hover:text-[var(--ui-primary)]' => !$isActive,
                ])
            >
                @svg('heroicon-o-link', 'w-5 h-5')
                <span class="truncate">Meine Connectoren</span>
            </a>
        </div>
    </div>

    @if ($isAdmin)
        <div class="space-y-3">
            <p class="text-xs font-semibold tracking-wide uppercase text-[var(--ui-muted)]">Administration</p>
            <div class="space-y-1">
                @php $isActive = request()->routeIs('user-connectors.connectors.*'); @endphp
                <a
                    href="{{ route('user-connectors.connectors.settings') }}"
                    wire:navigate
                    class="flex items-center gap-3 px-3 py-2 rounded-md text-sm font-medium transition-colors"
                    @class([
                        'bg-[var(--ui-primary)] text-[var(--ui-on-primary)] shadow-sm' => $isActive,
                        'text-[var(--ui-secondary)] hover:bg-[var(--ui-primary-5)] hover:text-[var(--ui-primary)]' => !$isActive,
                    ])
                >
                    @svg('heroicon-o-cog-6-tooth', 'w-5 h-5')
                    <span class="truncate">Connector-Settings</span>
                </a>
            </div>
        </div>
    @endif
</div>
