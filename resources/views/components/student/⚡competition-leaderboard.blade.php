<?php

use App\Models\Leaderboard;
use App\Services\LeaderboardService;
use App\Support\CompetitionWeekLeaders;
use App\Support\CompetitionWeeks;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Component;

/*
 * The leaderboard of the student's points competition, a component of its own
 * on their dashboard: picking a week draws it alone, not the whole page, and
 * it loads only once the student scrolls to it.
 */
new class extends Component {
    #[Locked]
    public int $competitionId;

    /** The week of the competition the leaderboard shows; empty for all its days. */
    public string $week = '';

    /**
     * What shows until it loads: the competition's name at once, the rest a
     * quiet shape — pulsing only for readers who have not asked for less motion.
     */
    public function placeholder(): string
    {
        return '<div class="space-y-6" aria-busy="true">'
            .'<div class="text-xl font-bold text-zinc-900 dark:text-white">'.e(Leaderboard::whereKey($this->competitionId)->value('title')).'</div>'
            .'<div class="h-11 w-2/3 rounded-xl bg-zinc-100 dark:bg-zinc-800 motion-safe:animate-pulse"></div>'
            .'<div class="h-56 rounded-2xl bg-zinc-100 dark:bg-zinc-800 motion-safe:animate-pulse"></div>'
            .'</div>';
    }

    public function with(): array
    {
        $student = Auth::guard('student')->user();

        // Only a competition of the student's own circle.
        $competition = Leaderboard::whereKey($this->competitionId)
            ->where(fn ($query) => $query->where('circle_id', $student->circle_id)
                ->orWhereHas('circles', fn ($circles) => $circles->where('circles.id', $student->circle_id)))
            ->firstOrFail();

        return [
            'competition' => $competition,
            'standings' => (new LeaderboardService)->getStandings($competition, CompetitionWeeks::find($competition, $this->week)),
            'weeks' => $weeks = CompetitionWeeks::of($competition),
            'leaders' => CompetitionWeekLeaders::for($competition, $weeks, $student->id),
            'studentId' => $student->id,
        ];
    }
};
?>

<div class="space-y-6">
    <div class="flex items-center justify-between">
        <flux:heading size="xl" class="flex items-center gap-3">
            <div class="p-2 bg-amber-500 rounded-lg shadow-lg shadow-amber-500/20">
                <flux:icon icon="trophy" variant="solid" class="size-6 text-white" />
            </div>
            {{$competition->title }}
        </flux:heading>
        <div
            class="flex items-center gap-2 text-xs font-bold text-amber-600 dark:text-amber-400 bg-amber-50 dark:bg-amber-900/30 px-3 py-1.5 rounded-full border border-amber-200 dark:border-amber-800/50">
            <flux:icon icon="clock" class="size-3.5" />
            @php
                $daysRemaining = now('Asia/Riyadh')->startOfDay()->diffInDays($competition->end_date->startOfDay(), false);
            @endphp
            @if($daysRemaining > 0)
                {{ __('متبقي :days يوم', ['days' => $daysRemaining]) }}
            @elseif($daysRemaining == 0)
                {{ __('اليوم الأخير!') }}
            @else
                {{ __('انتهت المنافسة') }}
            @endif
        </div>
    </div>

    <x-competition-weeks :weeks="$weeks" :leaders="$leaders" :selected="$week" model="week">
    @php
        $top3 = $standings->take(3)->values();
        $rest = $standings->skip(3)->values();
        $currentStudentId = auth('student')->id();
        $myRank = $standings->search(fn($s) => $s['student']->id === $currentStudentId);
        $myRank = $myRank !== false ? $myRank + 1 : 0;
        $myScore =
            $myRank > 0 ? $standings->firstWhere('student.id', $currentStudentId)['score'] : 0;
    @endphp

    @if ($top3->isNotEmpty())
        <!-- Podium -->
        <div
            class="bg-gradient-to-t from-zinc-100/50 to-white dark:from-zinc-900 dark:to-zinc-800/80 p-4 md:p-6 rounded-2xl border border-zinc-200 dark:border-zinc-700 shadow-sm relative overflow-hidden mb-6">
            <div class="flex justify-center items-end gap-2 md:gap-8 pt-6">
                <!-- Second Place -->
                @if (isset($top3[1]))
                    <div class="flex flex-col items-center w-24 md:w-32 z-10">
                        <div class="relative mb-2 shrink-0">
                            <div
                                class="w-14 h-14 md:w-16 md:h-16 rounded-full bg-slate-100 dark:bg-slate-800 border-[3px] border-slate-300 dark:border-slate-600 flex items-center justify-center text-xl font-bold shadow-lg text-slate-600 dark:text-slate-300">
                                {{ mb_substr($top3[1]['student']->name, 0, 1) }}
                            </div>
                            <div
                                class="absolute -top-2 -right-2 bg-slate-400 text-white rounded-full w-6 h-6 flex items-center justify-center text-xs font-bold border-2 border-white dark:border-zinc-800 shadow">
                                2</div>
                        </div>
                        <div
                            class="text-xs md:text-sm font-bold truncate w-full text-center text-zinc-700 dark:text-zinc-300 mb-1">
                            {{ explode(' ', $top3[1]['student']->name)[0] }}
                        </div>
                        <div
                            class="bg-white dark:bg-zinc-800 text-slate-500 dark:text-slate-400 font-bold text-[10px] md:text-xs px-2 py-0.5 rounded shadow-sm border border-zinc-100 dark:border-zinc-700">
                            {{ $top3[1]['score'] }} {{ __('نقطة') }}
                        </div>
                        <div
                            class="w-16 md:w-20 h-16 md:h-20 bg-gradient-to-t from-slate-200 to-slate-100 dark:from-slate-700/50 dark:to-slate-800/50 mt-3 rounded-t-lg border-t-2 border-slate-300 dark:border-slate-600 shadow-inner">
                        </div>
                    </div>
                @endif

                <!-- First Place -->
                <div class="flex flex-col items-center w-28 md:w-36 z-20 relative">
                    <flux:icon icon="star" variant="solid"
                        class="size-6 md:size-8 text-amber-400 absolute -top-8 animate-pulse drop-shadow-md" />
                    <div class="relative mb-2 shrink-0">
                        <div
                            class="w-16 h-16 md:w-20 md:h-20 rounded-full bg-amber-50 dark:bg-amber-900/30 border-[4px] border-amber-400 flex items-center justify-center text-2xl font-bold shadow-xl text-amber-600 dark:text-amber-400 z-10 relative">
                            {{ mb_substr($top3[0]['student']->name, 0, 1) }}
                        </div>
                        <div
                            class="absolute -top-3 -right-3 bg-amber-500 text-white rounded-full w-7 h-7 flex items-center justify-center text-sm font-bold border-2 border-white dark:border-zinc-800 z-20 shadow-md">
                            1</div>
                    </div>
                    <div
                        class="text-sm md:text-base font-extrabold truncate w-full text-center text-zinc-900 dark:text-zinc-100 mb-1">
                        {{ explode(' ', $top3[0]['student']->name)[0] }}
                    </div>
                    <div
                        class="bg-amber-100 dark:bg-amber-900/50 text-amber-700 dark:text-amber-400 font-black text-xs md:text-sm px-3 py-1 rounded shadow-sm">
                        {{ $top3[0]['score'] }} {{ __('نقطة') }}
                    </div>
                    <div
                        class="w-20 md:w-24 h-20 md:h-28 bg-gradient-to-t from-amber-200/50 to-amber-100/50 dark:from-amber-900/30 dark:to-amber-900/10 mt-3 rounded-t-lg border-t-2 border-amber-400 shadow-[inset_0_4px_6px_-1px_rgba(251,191,36,0.3)]">
                    </div>
                </div>

                <!-- Third Place -->
                @if (isset($top3[2]))
                    <div class="flex flex-col items-center w-24 md:w-32 z-10">
                        <div class="relative mb-2 shrink-0">
                            <div
                                class="w-14 h-14 md:w-16 md:h-16 rounded-full bg-orange-50 dark:bg-orange-900/20 border-[3px] border-orange-300 dark:border-orange-700/80 flex items-center justify-center text-xl font-bold shadow-lg text-orange-600 dark:text-orange-500">
                                {{ mb_substr($top3[2]['student']->name, 0, 1) }}
                            </div>
                            <div
                                class="absolute -top-2 -right-2 bg-orange-400 text-white rounded-full w-6 h-6 flex items-center justify-center text-xs font-bold border-2 border-white dark:border-zinc-800 shadow">
                                3</div>
                        </div>
                        <div
                            class="text-xs md:text-sm font-bold truncate w-full text-center text-zinc-700 dark:text-zinc-300 mb-1">
                            {{ explode(' ', $top3[2]['student']->name)[0] }}
                        </div>
                        <div
                            class="bg-white dark:bg-zinc-800 text-orange-600 dark:text-orange-500 font-bold text-[10px] md:text-xs px-2 py-0.5 rounded shadow-sm border border-zinc-100 dark:border-zinc-700">
                            {{ $top3[2]['score'] }} {{ __('نقطة') }}
                        </div>
                        <div
                            class="w-16 md:w-20 h-10 md:h-16 bg-gradient-to-t from-orange-100 to-orange-50 dark:from-orange-900/40 dark:to-orange-900/10 mt-3 rounded-t-lg border-t-2 border-orange-300 dark:border-orange-700 shadow-inner">
                        </div>
                    </div>
                @endif
            </div>

            <!-- My Rank Banner -->
            @if ($myRank > 3)
                <div
                    class="mt-6 bg-indigo-50 dark:bg-indigo-900/30 border border-indigo-100 dark:border-indigo-800/50 text-indigo-700 dark:text-indigo-300 p-4 rounded-xl flex items-center justify-between shadow-sm">
                    <div class="flex items-center gap-4">
                        <div
                            class="bg-indigo-600 dark:bg-indigo-500 text-white w-10 h-10 rounded-full flex items-center justify-center font-black text-lg shadow-inner">
                            {{ $myRank }}
                        </div>
                        <div>
                            <div class="font-bold text-sm">{{ __('ترتيبك الحالي بين المتنافسين') }}</div>
                            <div class="text-xs opacity-90 mt-0.5">{{ __('مجموع نقاطك:') }} <span
                                    class="font-bold">{{ $myScore }}</span> {{ __('شد الهمة!') }}
                            </div>
                        </div>
                    </div>
                    <flux:icon icon="arrow-trending-up" class="size-6 opacity-50" />
                </div>
            @endif
        </div>

        @if ($rest->isNotEmpty())
            <!-- Students List -->
            <div
                class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-200 dark:border-zinc-800 overflow-hidden shadow-sm">
                <div class="divide-y divide-zinc-100 dark:divide-zinc-800/60">
                    @foreach ($rest as $index => $standing)
                        @php $rank = $index + 4; @endphp
                        <div
                            class="flex items-center justify-between p-3 md:p-4 hover:bg-zinc-50 dark:hover:bg-zinc-800/30   s {{ $standing['student']->id === $studentId ? 'bg-indigo-50/40 dark:bg-indigo-900/10' : '' }}">
                            <div class="flex items-center gap-3 md:gap-4">
                                <div
                                    class="w-6 h-6 md:w-8 md:h-8 flex items-center justify-center text-zinc-400 font-black text-sm md:text-base">
                                    {{ $rank }}
                                </div>
                                <div
                                    class="w-8 h-8 md:w-10 md:h-10 rounded-full bg-zinc-100 dark:bg-zinc-800 flex items-center justify-center text-sm font-bold text-zinc-600 dark:text-zinc-400 border border-zinc-200/50 dark:border-zinc-700/50">
                                    {{ mb_substr($standing['student']->name, 0, 1) }}
                                </div>
                                <div>
                                    <div
                                        class="font-semibold text-sm md:text-base {{ $standing['student']->id === $studentId ? 'text-indigo-600 dark:text-indigo-400' : 'text-zinc-800 dark:text-zinc-200' }}">
                                        {{ $standing['student']->name }}
                                        @if ($standing['student']->id === $studentId)
                                            <span
                                                class="text-[10px] font-bold ms-2 bg-indigo-100 dark:bg-indigo-900 text-indigo-700 dark:text-indigo-300 px-2 py-0.5 rounded-full">{{ __('أنت') }}</span>
                                        @endif
                                    </div>
                                </div>
                            </div>
                            <div
                                class="font-bold text-emerald-700 dark:text-emerald-400 bg-emerald-50 dark:bg-emerald-900/30 border border-emerald-100 dark:border-emerald-800/50 px-3 py-1 rounded-full text-xs md:text-sm">
                                {{ $standing['score'] }} <span
                                    class="font-normal text-[10px] mx-1 opacity-70">{{ __('نقطة') }}</span>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    @else
        <div
            class="text-center py-10 bg-zinc-50 dark:bg-zinc-900/50 rounded-2xl border border-dashed border-zinc-200 dark:border-zinc-800">
            <div
                class="bg-amber-100 dark:bg-amber-900/30 text-amber-500 w-12 h-12 rounded-full flex items-center justify-center mx-auto mb-3">
                <flux:icon icon="bolt" class="size-6" />
            </div>
            <div class="font-bold text-zinc-700 dark:text-zinc-300">{{ __('المسابقة بدأت للتو!') }}
            </div>
            <p class="text-sm text-zinc-500 mt-1">{{ __('كن أول من يحصد النقاط و يتصدر القائمة.') }}</p>
        </div>
    @endif
    </x-competition-weeks>
</div>
