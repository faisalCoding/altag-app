<?php

namespace App\Livewire\Teacher;

use App\Models\Leaderboard;
use App\Services\LeaderboardService;
use Livewire\Attributes\Locked;
use Livewire\Component;

class LeaderboardReport extends Component
{
    #[Locked]
    public $leaderboardId;

    public function mount($leaderboardId)
    {
        $this->leaderboardId = $leaderboardId;
    }

    public function render()
    {
        $leaderboard = Leaderboard::with('criteria', 'circles')->findOrFail($this->leaderboardId);
        $service = new LeaderboardService;
        $standings = $service->getDetailedStandings($leaderboard);
        $standingsByTrack = $service->getStandingsByTrack($leaderboard);

        return view('livewire.teacher.leaderboard-report', [
            'leaderboard' => $leaderboard,
            'standings' => $standings,
            'standingsByTrack' => $standingsByTrack,
        ]);
    }
}
