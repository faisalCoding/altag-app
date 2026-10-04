@props(['student'])

@php
    $juzMap = \App\Services\MemorizationJourneyService::juzMap($student);
    $surahMap = \App\Services\MemorizationJourneyService::surahMap($student);
    $scoreTrend = \App\Services\MemorizationJourneyService::scoreTrend($student);
    $attendanceTrend = \App\Services\MemorizationJourneyService::attendanceTrend($student);

    $fullCount = collect($juzMap)->where('status', 'full')->count();
    $partialCount = collect($juzMap)->where('status', 'partial')->count();
    $fullSurahCount = collect($surahMap)->where('status', 'full')->count();
@endphp

<div class="space-y-6" dir="rtl">

    {{-- Memorization journey: 30-juz mushaf map --}}
    <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 p-5">
        <div class="flex items-center justify-between mb-4 flex-wrap gap-2">
            <h2 class="font-semibold text-zinc-900 dark:text-white flex items-center gap-2">
                <flux:icon icon="book-open" class="size-5 text-emerald-500" />
                رحلة الحفظ
            </h2>
            <span class="text-xs text-zinc-500">
                <span class="font-bold text-emerald-600 dark:text-emerald-400">{{ $fullCount }}</span> جزء مكتمل
                @if($partialCount > 0)
                    · <span class="font-bold text-amber-600 dark:text-amber-400">{{ $partialCount }}</span> قيد الحفظ
                @endif
            </span>
        </div>

        <div class="grid grid-cols-6 sm:grid-cols-10 gap-1.5">
            @foreach($juzMap as $juz)
                @php
                    $cellClass = match ($juz['status']) {
                        'full' => 'bg-emerald-500 text-white border-emerald-600',
                        'partial' => 'bg-amber-100 dark:bg-amber-500/20 text-amber-700 dark:text-amber-300 border-amber-300 dark:border-amber-500/40',
                        default => 'bg-zinc-50 dark:bg-zinc-900 text-zinc-400 border-zinc-200 dark:border-zinc-700',
                    };
                    $label = match ($juz['status']) {
                        'full' => 'مكتمل',
                        'partial' => 'قيد الحفظ',
                        default => 'لم يبدأ',
                    };
                @endphp
                <div class="aspect-square rounded-lg border flex items-center justify-center text-xs font-bold {{ $cellClass }}"
                    title="الجزء {{ $juz['juz'] }} — {{ $label }}">
                    {{ $juz['juz'] }}
                </div>
            @endforeach
        </div>

        <div class="flex items-center gap-4 mt-4 text-[11px] text-zinc-500">
            <span class="flex items-center gap-1.5"><span class="size-3 rounded bg-emerald-500"></span> مكتمل</span>
            <span class="flex items-center gap-1.5"><span class="size-3 rounded bg-amber-200 dark:bg-amber-500/30 border border-amber-300"></span> قيد الحفظ</span>
            <span class="flex items-center gap-1.5"><span class="size-3 rounded bg-zinc-100 dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700"></span> لم يبدأ</span>
        </div>

        {{-- Surah map: highlights every surah the student has completed --}}
        <div x-data="{ showSurahs: false }" class="mt-4 pt-4 border-t border-neutral-100 dark:border-neutral-700">
            <button type="button" @click="showSurahs = !showSurahs"
                class="w-full flex items-center justify-between text-sm text-neutral-600 dark:text-neutral-300 hover:text-neutral-900 dark:hover:text-white">
                <span class="flex items-center gap-2">
                    <flux:icon icon="queue-list" class="size-4 text-emerald-500" />
                    خريطة السور
                    <span class="text-xs text-neutral-400">(<span class="font-bold text-emerald-600 dark:text-emerald-400">{{ $fullSurahCount }}</span> سورة مكتملة)</span>
                </span>
                <flux:icon icon="chevron-down" class="size-4 transition-transform" x-bind:class="showSurahs ? 'rotate-180' : ''" />
            </button>
            <div x-show="showSurahs" x-collapse x-cloak>
                <div class="flex flex-wrap gap-1.5 mt-3">
                    @foreach($surahMap as $surah)
                        @php
                            $chipClass = match ($surah['status']) {
                                'full' => 'bg-emerald-500 text-white border-emerald-600',
                                'partial' => 'bg-amber-100 dark:bg-amber-500/20 text-amber-700 dark:text-amber-300 border-amber-300 dark:border-amber-500/40',
                                default => 'bg-neutral-50 dark:bg-neutral-900 text-neutral-400 border-neutral-200 dark:border-neutral-700',
                            };
                            $chipLabel = match ($surah['status']) {
                                'full' => 'مكتملة',
                                'partial' => 'قيد الحفظ',
                                default => 'لم تبدأ',
                            };
                        @endphp
                        <span class="px-2 py-1 rounded-lg border text-[11px] font-medium {{ $chipClass }}"
                            title="سورة {{ $surah['name'] }} — {{ $chipLabel }}">
                            {{ $surah['name'] }}
                        </span>
                    @endforeach
                </div>
            </div>
        </div>
    </div>

    <div class="grid md:grid-cols-2 gap-6">

        {{-- Evaluation trend --}}
        <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 p-5">
            <h2 class="font-semibold text-zinc-900 dark:text-white mb-4 flex items-center gap-2">
                <flux:icon icon="chart-bar" class="size-5 text-violet-500" />
                تطوّر التقييم
            </h2>
            @if(count($scoreTrend) > 0)
                <div class="flex items-end justify-between gap-1.5 h-28">
                    @foreach($scoreTrend as $point)
                        @php
                            $barClass = match ($point['achievement']) {
                                3 => 'bg-emerald-500',
                                2 => 'bg-amber-400',
                                1 => 'bg-orange-400',
                                default => 'bg-rose-500',
                            };
                            $barLabel = match ($point['achievement']) {
                                3 => 'ممتاز',
                                2 => 'جيد',
                                1 => 'مقبول',
                                default => 'لم يسمع',
                            };
                            $heightPct = (int) round(($point['achievement'] / 3) * 100);
                        @endphp
                        <div class="flex-1 flex flex-col items-center justify-end h-full" title="{{ $point['date'] }} — {{ $barLabel }}">
                            <div class="w-full rounded-t {{ $barClass }} transition-all duration-500" style="height: {{ max($heightPct, 8) }}%"></div>
                        </div>
                    @endforeach
                </div>
                <p class="text-[11px] text-zinc-400 mt-2 text-center">آخر {{ count($scoreTrend) }} تقييم</p>
            @else
                <p class="text-sm text-zinc-400 text-center py-8">لا توجد تقييمات بعد</p>
            @endif
        </div>

        {{-- Attendance trend --}}
        <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 p-5">
            <h2 class="font-semibold text-zinc-900 dark:text-white mb-4 flex items-center gap-2">
                <flux:icon icon="calendar-days" class="size-5 text-blue-500" />
                حضور آخر 8 أسابيع
            </h2>
            <div class="flex items-end justify-between gap-1.5 h-28">
                @foreach($attendanceTrend as $week)
                    @php
                        $ratio = $week['total'] > 0 ? $week['present'] / $week['total'] : 0;
                        $heightPct = (int) round($ratio * 100);
                        $barClass = $week['total'] === 0
                            ? 'bg-zinc-200 dark:bg-zinc-700'
                            : ($ratio >= 0.8 ? 'bg-emerald-500' : ($ratio >= 0.5 ? 'bg-amber-400' : 'bg-rose-400'));
                    @endphp
                    <div class="flex-1 flex flex-col items-center justify-end h-full gap-1"
                        title="أسبوع {{ $week['label'] }} — {{ $week['present'] }}/{{ $week['total'] }}">
                        <div class="w-full rounded-t {{ $barClass }} transition-all duration-500" style="height: {{ $week['total'] > 0 ? max($heightPct, 6) : 4 }}%"></div>
                        <span class="text-[9px] text-zinc-400">{{ $week['label'] }}</span>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</div>
