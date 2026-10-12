<?php

namespace App\Livewire\Teacher;

use App\Models\Leaderboard;
use App\Services\LeaderboardService;
use App\Support\CompetitionWeeks;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

class LeaderboardReport extends Component
{
    #[Locked]
    public $leaderboardId;

    /** The week of the competition the standings are over; empty for all its days. */
    #[Url(as: 'week')]
    public string $week = '';

    public function mount($leaderboardId)
    {
        $this->leaderboardId = $leaderboardId;
    }

    public function render()
    {
        $leaderboard = Leaderboard::with('criteria', 'circles')->findOrFail($this->leaderboardId);
        $service = new LeaderboardService;
        $chosenWeek = CompetitionWeeks::find($leaderboard, $this->week);
        $standings = $service->getDetailedStandings($leaderboard, $chosenWeek);
        // Grouped from the standings just worked out, not worked out a second time.
        $standingsByTrack = $service->getStandingsByTrack($leaderboard, $standings);

        return view('livewire.teacher.leaderboard-report', [
            'leaderboard' => $leaderboard,
            'weeks' => CompetitionWeeks::of($leaderboard),
            'chosenWeek' => $chosenWeek,
            'standings' => $standings,
            'standingsByTrack' => $standingsByTrack,
        ]);
    }
}
