<?php

use Illuminate\Support\Facades\Route;
use Platform\UserConnectors\Livewire\Connections\Index as ConnectionsIndex;
use Platform\UserConnectors\Livewire\Connectors\Settings as ConnectorSettings;

// Loaded via ModuleRouter (when module is active)
Route::get('/', ConnectionsIndex::class)->name('user-connectors.connections.index');
Route::get('/settings', ConnectorSettings::class)->name('user-connectors.connectors.settings');
