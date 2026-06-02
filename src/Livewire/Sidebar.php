<?php

namespace Platform\UserConnectors\Livewire;

use Livewire\Component;

class Sidebar extends Component
{
    public function render()
    {
        $user = auth()->user();
        $team = $user?->currentTeam;

        $role = null;
        if ($team) {
            $role = $user->teams()
                ->where('teams.id', $team->id)
                ->first()
                ?->pivot
                ?->role;
        }

        return view('user-connectors::livewire.sidebar', [
            'isAdmin' => in_array($role, ['owner', 'admin']),
        ]);
    }
}
