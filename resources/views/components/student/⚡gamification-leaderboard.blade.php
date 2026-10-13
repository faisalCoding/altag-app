<?php

use App\Models\Leaderboard;
use App\Services\LeaderboardService;
use App\Support\CompetitionWeeks;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/*
 * The leaderboard of the student's gamified competition, a component of its
 * own on their dashboard: picking a week draws it alone, not the whole page,
 * and it loads only once the student reaches it.
 */
new class extends Component {
    #[Locked]
    public int $competitionId;

    /** The week of the competition the leaderboard shows; empty for all its days. */
    public string $week = '';

    /**
     * What shows until it loads: its heading at once, the rest a quiet shape —
     * pulsing only for readers who have not asked for less motion.
     */
    public function placeholder(): string
    {
        return '<div class="space-y-4" aria-busy="true">'
            .'<div class="text-lg font-black text-slate-900">'.e(__('لوحة المتصدرين')).'</div>'
            .'<div class="h-11 w-2/3 rounded-xl bg-slate-100 motion-safe:animate-pulse"></div>'
            .'<div class="h-56 rounded-2xl bg-slate-100 motion-safe:animate-pulse"></div>'
            .'</div>';
    }

    /** A reward claimed on the page moves the standings: drawn again. */
    #[On('reward-claimed')]
    public function refreshStandings(): void {}

    public function with(): array
    {
        $student = Auth::guard('student')->user();

        // Only a competition of the student's own circle.
        $competition = Leaderboard::whereKey($this->competitionId)
            ->where(fn ($query) => $query->where('circle_id', $student->circle_id)
                ->orWhereHas('circles', fn ($circles) => $circles->where('circles.id', $student->circle_id)))
            ->firstOrFail();

        $service = new LeaderboardService;
        $standings = $service->getStandings($competition, CompetitionWeeks::find($competition, $this->week));

        return [
            'standings' => $standings,
            'byTrack' => $service->getStandingsByTrack($competition, $standings),
            'weeks' => CompetitionWeeks::of($competition),
            'studentId' => $student->id,
        ];
    }
};
?>

<div class="space-y-4">
    <flux:heading size="lg" class="text-slate-900 flex items-center gap-2 font-black">
        <svg class="size-6 text-amber-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v5m-3 0h6m-6-14h6M4 7c0 2 1.5 4 3.5 4h1M20 7c0 2-1.5 4-3.5 4h-1m-7.5-4h9v6a4.5 4.5 0 01-9 0V7z"></path>
        </svg>
        {{ __('لوحة المتصدرين') }}
    </flux:heading>
    <x-competition-weeks :weeks="$weeks" :selected="$week" model="week"
        active="bg-team-primary text-white border-team-primary shadow-sm">

    @if(empty($standings))
        <div class="text-center py-12 bg-slate-50 border border-slate-200 rounded-2xl">
            <flux:icon icon="presentation-chart-line" class="size-10 mx-auto text-slate-400 mb-3" />
            <h4 class="font-bold text-slate-500 text-sm">{{ __('لا توجد نتائج لعرضها') }}</h4>
        </div>
    @elseif($byTrack->isNotEmpty())
        {{-- Standings divided into tracks, each with its own ranking --}}
        @php
            $myTrackKey = null;
            foreach ($byTrack as $gi => $g) {
                foreach ($g['standings'] as $s) {
                    if ($s['student']->id === $studentId) { $myTrackKey = $gi; break 2; }
                }
            }
        @endphp
        <div class="space-y-3">
            @foreach($byTrack as $gi => $group)
                <div x-data="{ open: {{ $gi === $myTrackKey ? 'true' : 'false' }} }" class="bg-white border {{ $gi === $myTrackKey ? 'border-team-primary' : 'border-slate-200' }} rounded-2xl overflow-hidden shadow-sm">
                    <button type="button" @click="open = !open" class="w-full flex items-center justify-between gap-3 p-4 hover:bg-slate-50/50">
                        <div class="flex items-center gap-2.5 min-w-0">
                            <div class="size-9 rounded-xl flex items-center justify-center shrink-0 {{ $gi === $myTrackKey ? 'bg-team-10 text-team-primary' : 'bg-slate-100 text-slate-500' }}"><flux:icon icon="flag" class="size-5" /></div>
                            <div class="min-w-0 text-right">
                                <div class="font-bold text-slate-900 flex items-center gap-2">
                                    <span class="truncate">{{ $group['name'] }}</span>
                                    @if($gi === $myTrackKey)<span class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-team-10 text-team-primary shrink-0">{{ __('مسارك') }}</span>@endif
                                </div>
                                @if($group['description'])<div class="text-xs text-slate-400 truncate">{{ $group['description'] }}</div>@endif
                            </div>
                        </div>
                        <div class="flex items-center gap-2 shrink-0">
                            <span class="text-xs text-slate-400">{{ count($group['standings']) }}</span>
                            <flux:icon icon="chevron-down" class="size-4 text-slate-400 transition-transform" x-bind:class="open ? 'rotate-180' : ''" />
                        </div>
                    </button>
                    <div x-show="open" x-collapse x-cloak class="border-t border-slate-100 overflow-x-auto">
                        <table class="w-full text-right">
                            <tbody class="divide-y divide-slate-100">
                                @foreach($group['standings'] as $standing)
                                    @php $rank = $standing['track_rank']; $isMe = $standing['student']->id === $studentId; @endphp
                                    <x-student.partials.leaderboard-row :standing="$standing" :rank="$rank" :is-me="$isMe" />
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endforeach
        </div>
    @else
        <!-- Themed Leaderboard Table -->
        <div class="bg-white border border-slate-200 rounded-2xl overflow-hidden shadow-sm">
            <div class="overflow-x-auto">
                <table class="w-full text-right">
                    <thead class="bg-slate-50/80 border-b border-slate-200">
                        <tr>
                            <th class="p-1 w-1 font-bold text-slate-500 text-center ">{{ __('') }}</th>
                            <th class="p-4 font-bold text-slate-500">{{ __('اسم الطالب') }}</th>
                            <th class="p-4 font-bold text-slate-500 text-center w-24">{{ __('مجموع النقاط') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($standings as $index => $standing)
                            @php $rank = $index + 1; $isMe = $standing['student']->id === $studentId; @endphp
                            <x-student.partials.leaderboard-row :standing="$standing" :rank="$rank" :is-me="$isMe" />
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
    </x-competition-weeks>
</div>
