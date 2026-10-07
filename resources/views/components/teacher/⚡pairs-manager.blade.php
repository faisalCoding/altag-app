<?php

use App\Models\PeerPair;
use App\Models\Student;
use App\Models\StudentPlanDay;
use App\Services\PeerPairing;
use App\Services\TeacherSyncSnapshot;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * Mutual recitation: the day's pairs of a circle, made from the students
 * present, kept for every teacher of the circle and the teacher app, with
 * students swapped by hand and each recitation's outcome recorded.
 */
new class extends Component
{
    public ?int $circleId = null;

    public string $date = '';

    /** The student picked to swap, waiting for the one to swap with. */
    public ?int $swapping = null;

    public function mount(): void
    {
        $this->circleId = $this->circles()->first()?->id;
        // The academy's day, not the server's.
        $this->date = TeacherSyncSnapshot::today();
    }

    public function updatedCircleId(): void
    {
        $this->swapping = null;
    }

    public function updatedDate(): void
    {
        $this->swapping = null;
    }

    public function generatePairs(): void
    {
        $circle = $this->circle();

        PeerPairing::generate($circle, $this->day(), Auth::guard('teacher')->id());
        $this->swapping = null;

        Flux::toast('وُزّعت الثنائيات', variant: 'success');
    }

    /** Pick a student to swap, then the one to swap with; the same student again lets go. */
    public function pick(int $studentId): void
    {
        if ($this->swapping === null) {
            $this->swapping = $studentId;

            return;
        }

        if ($this->swapping === $studentId) {
            $this->swapping = null;

            return;
        }

        $circle = $this->circle();
        $inCircle = Student::where('circle_id', $circle->id)->whereKey([$this->swapping, $studentId])->count();

        if ($inCircle === 2) {
            PeerPairing::swap($circle, $this->day(), $this->swapping, $studentId);
        }

        $this->swapping = null;
    }

    public function adjustMistakes(int $pairId, string $place, int $step): void
    {
        $pair = $this->pair($pairId, $place);
        $mistakes = max(0, min(99, ($pair->{"{$place}_mistakes"} ?? 0) + ($step < 0 ? -1 : 1)));

        PeerPairing::record($pair, $place, $mistakes, $pair->{"{$place}_ready"});
    }

    public function toggleReady(int $pairId, string $place): void
    {
        $pair = $this->pair($pairId, $place);

        PeerPairing::record($pair, $place, $pair->{"{$place}_mistakes"}, ! $pair->{"{$place}_ready"});
    }

    public function with(): array
    {
        $circle = $this->circleId ? $this->circles()->firstWhere('id', $this->circleId) : null;
        $pairs = $circle ? PeerPairing::forDay($circle->id, $this->day()) : collect();

        return [
            'circles' => $this->circles(),
            'pairs' => $pairs,
            'unpaired' => $circle ? PeerPairing::unpaired($circle->id, $this->day(), $pairs) : collect(),
        ];
    }

    private function circles()
    {
        return Auth::guard('teacher')->user()->circles()->orderBy('circles.id')->get();
    }

    private function circle()
    {
        return $this->circles()->firstWhere('id', $this->circleId) ?? abort(403);
    }

    /** The day picked, never one after today. */
    private function day(): string
    {
        return min($this->date ?: TeacherSyncSnapshot::today(), TeacherSyncSnapshot::today());
    }

    /** One of the circle's pairs of the day, and a place in it that recites. */
    private function pair(int $pairId, string $place): PeerPair
    {
        abort_unless(in_array($place, PeerPair::PLACES, true), 422);

        return PeerPair::whereKey($pairId)
            ->where('circle_id', $this->circle()->id)
            ->where('date', $this->day())
            ->firstOrFail();
    }

    public function portion(PeerPair $pair, string $place): ?string
    {
        return StudentPlanDay::formatAyahRange($pair->{"{$place}FromAyah"}, $pair->{"{$place}ToAyah"});
    }
};
?>

<div class="space-y-6">
    <div class="flex flex-col lg:flex-row lg:items-end justify-between gap-4">
        <div>
            <flux:heading size="xl">{{ __('التسميع المتبادل') }}</flux:heading>
            <flux:subheading>{{ __('يُسمّع كل طالب مراجعته لزميل يحفظها، ويسجّل المعلم أخطاءه ومتى يصبح جاهزاً له.') }}</flux:subheading>
        </div>

        <div class="flex flex-wrap items-end gap-2">
            @if ($circles->count() > 1)
                <div class="w-44">
                    <flux:select wire:model.live="circleId" label="{{ __('الحلقة') }}">
                        @foreach ($circles as $circle)
                            <flux:select.option value="{{ $circle->id }}">{{ $circle->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>
            @endif
            <div class="w-44">
                <livewire:shared.hijri-datepicker wire:model.live="date" label="" />
            </div>
            <flux:button wire:click="generatePairs" variant="primary" icon="sparkles"
                :wire:confirm="$pairs->isNotEmpty() ? __('تُستبدل ثنائيات هذا اليوم وما سُجّل فيها. متابعة؟') : null">
                {{ $pairs->isEmpty() ? __('توليد الثنائيات') : __('إعادة التوليد') }}
            </flux:button>
        </div>
    </div>

    @if ($swapping)
        <div class="flex items-center justify-between gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-300">
            <span>{{ __('اختر الطالب الذي يأخذ مكانه — من ثنائية أخرى أو ممن بلا زميل.') }}</span>
            <flux:button size="sm" variant="ghost" wire:click="pick({{ $swapping }})">{{ __('إلغاء') }}</flux:button>
        </div>
    @endif

    @if ($pairs->isEmpty())
        <div class="flex flex-col items-center justify-center p-12 bg-white dark:bg-zinc-900 rounded-3xl border border-zinc-100 dark:border-zinc-800 shadow-xs text-center">
            <div class="w-16 h-16 bg-indigo-50 dark:bg-indigo-500/10 text-indigo-500 rounded-full flex items-center justify-center mb-5">
                <flux:icon icon="users" class="size-8" />
            </div>
            <flux:heading size="lg" class="mb-2">{{ __('لا ثنائيات لهذا اليوم بعد') }}</flux:heading>
            <p class="text-sm text-zinc-500 max-w-md">
                {{ __('حضّر الطلاب ثم اضغط «توليد الثنائيات»: يقابل النظام مراجعة كل طالب حاضر بمحفوظ زملائه، وتظهر الثنائيات نفسها لكل معلمي الحلقة وفي تطبيق المعلم.') }}
            </p>
        </div>
    @else
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <div class="lg:col-span-2 space-y-3">
                @foreach ($pairs as $pair)
                    <div wire:key="pair-{{ $pair->id }}"
                        class="grid grid-cols-1 sm:grid-cols-[1fr_auto_1fr] items-stretch gap-3 p-4 bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-2xl shadow-xs">
                        @foreach (\App\Models\PeerPair::PLACES as $place)
                            @php
                                $student = $pair->{$place};
                                $recites = $pair->recites($place);
                                $mistakes = $pair->{"{$place}_mistakes"};
                                $ready = $pair->{"{$place}_ready"};
                            @endphp

                            @if ($place === 'second')
                                <div class="hidden sm:flex items-center justify-center text-zinc-400">
                                    <flux:icon :icon="$pair->mutual ? 'arrows-right-left' : 'arrow-left'" class="size-5" />
                                </div>
                            @endif

                            <div @class([
                                'rounded-xl p-3 space-y-2',
                                'bg-amber-50 ring-2 ring-amber-400 dark:bg-amber-500/10' => $swapping === $student?->id,
                                'bg-zinc-50 dark:bg-zinc-800/50' => $swapping !== $student?->id,
                            ])>
                                <div class="flex items-center justify-between gap-2">
                                    <button type="button" wire:click="pick({{ $student?->id }})"
                                        class="font-bold text-zinc-900 dark:text-zinc-100 truncate text-start hover:underline"
                                        title="{{ __('تبديل') }}">{{ $student?->name }}</button>
                                    <flux:button size="xs" variant="subtle" icon="arrows-up-down" wire:click="pick({{ $student?->id }})"
                                        aria-label="{{ __('تبديل :name', ['name' => $student?->name]) }}" />
                                </div>

                                @if ($recites)
                                    <div class="text-xs text-zinc-500">
                                        {{ __('يُسمّع:') }}
                                        <span class="font-medium text-indigo-700 dark:text-indigo-300">{{ $this->portion($pair, $place) ?? __('لا ورد مراجعة له') }}</span>
                                    </div>

                                    <div class="flex items-center justify-between gap-2">
                                        <div class="flex items-center gap-1" role="group" aria-label="{{ __('الأخطاء') }}">
                                            <flux:button size="xs" variant="ghost" icon="minus" wire:click="adjustMistakes({{ $pair->id }}, '{{ $place }}', -1)"
                                                :disabled="! $mistakes" aria-label="{{ __('خطأ أقل') }}" />
                                            <span class="min-w-14 text-center text-sm font-semibold text-zinc-700 dark:text-zinc-200">
                                                {{ $mistakes === null ? __('الأخطاء') : $mistakes.' '.__('أخطاء') }}
                                            </span>
                                            <flux:button size="xs" variant="ghost" icon="plus" wire:click="adjustMistakes({{ $pair->id }}, '{{ $place }}', 1)"
                                                aria-label="{{ __('خطأ زائد') }}" />
                                        </div>
                                        <flux:button size="xs" wire:click="toggleReady({{ $pair->id }}, '{{ $place }}')"
                                            :variant="$ready ? 'primary' : 'outline'" :icon="$ready ? 'check' : null">
                                            {{ __('جاهز للمعلم') }}
                                        </flux:button>
                                    </div>
                                @else
                                    <div class="text-xs text-zinc-500">{{ __('يستمع لزميله ويصحح له') }}</div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endforeach
            </div>

            <div class="space-y-3">
                <flux:heading size="lg">{{ __('بلا زميل') }} ({{ $unpaired->count() }})</flux:heading>
                @forelse ($unpaired as $item)
                    <button type="button" wire:key="unpaired-{{ $item['student']->id }}" wire:click="pick({{ $item['student']->id }})"
                        @class([
                            'w-full text-start p-3 rounded-xl border transition-colors',
                            'border-amber-400 bg-amber-50 dark:bg-amber-500/10' => $swapping === $item['student']->id,
                            'border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 hover:border-zinc-300' => $swapping !== $item['student']->id,
                        ])>
                        <div class="font-semibold text-zinc-900 dark:text-zinc-100">{{ $item['student']->name }}</div>
                        <div class="text-xs text-zinc-500 mt-0.5">{{ $item['reason'] }}</div>
                    </button>
                @empty
                    <div class="p-4 text-center bg-zinc-50 dark:bg-zinc-800/50 rounded-xl text-sm text-zinc-500">
                        {{ __('كل الحاضرين في ثنائيات.') }}
                    </div>
                @endforelse

                <p class="text-xs text-zinc-500 leading-relaxed">
                    {{ __('اضغط اسمين لتبديلهما، أو اسمين بلا زميل لتجعلهما ثنائية. ومن بقي بلا زميل يُسمّع للمعلم مباشرة.') }}
                </p>
            </div>
        </div>
    @endif
</div>
