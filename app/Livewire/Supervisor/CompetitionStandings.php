<?php

namespace App\Livewire\Supervisor;

use App\Models\Leaderboard;
use App\Services\LeaderboardService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

class CompetitionStandings extends Component
{
    #[Locked]
    public int $competitionId;

    #[Url]
    public int $topCount = 3;

    public function mount(int $competitionId): void
    {
        $this->competitionId = $competitionId;
    }

    public function render()
    {
        $supervisorId = Auth::guard('supervisor')->id();

        $leaderboard = Leaderboard::with('circles')
            ->where('supervisor_id', $supervisorId)
            ->findOrFail($this->competitionId);

        $service = new LeaderboardService;
        $standingsByTrack = $service->getStandingsByTrack($leaderboard);

        if ($standingsByTrack->isEmpty()) {
            $flat = $service->getDetailedStandings($leaderboard);

            if ($flat->isNotEmpty()) {
                $standingsByTrack = collect([[
                    'id' => null,
                    'name' => __('عام'),
                    'description' => null,
                    'standings' => $flat->all(),
                ]]);
            }
        }

        $topByTrack = $standingsByTrack->map(function (array $group) {
            $group['standings'] = collect($group['standings'])->take($this->topCount)->values()->all();

            return $group;
        });

        return view('livewire.supervisor.competition-standings', [
            'leaderboard' => $leaderboard,
            'topByTrack' => $topByTrack,
        ]);
    }
}
