<?php

use Illuminate\Support\Facades\Route;
use Platform\UserConnectors\Livewire\Connections\Index as ConnectionsIndex;

// Loaded via ModuleRouter (when module is active)
Route::get('/', ConnectionsIndex::class)->name('user-connectors.connections.index');
