<?php

use App\Services\StudentProgressReport;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;

/*
 * The student's progress in the Quran, drawn rather than listed: how much of
 * the mushaf they hold, how fast it grows and when the juz in hand will be
 * done, how well they recite, how closely they keep to their plan, their
 * exams, and this month against the last. Measured in mushaf pages.
 */
new class extends Component
{
    /** The span grades and plan-keeping are read over: 'month' or 'term'. */
    #[Url]
    public string $scope = 'month';

    public function with(): array
    {
        $student = Auth::guard('student')->user();
        $period = StudentProgressReport::period($student, $this->scope === 'term' ? 'term' : 'month');
        $mushaf = StudentProgressReport::mushaf($student);
        $pace = StudentProgressReport::pace($student);

        return [
            'period' => $period,
            'mushaf' => $mushaf,
            'pace' => $pace,
            'forecast' => StudentProgressReport::forecast($mushaf, $pace),
            'quality' => StudentProgressReport::quality($student, $period['from'], $period['to']),
            'adherence' => StudentProgressReport::adherence($student, $period['from'], $period['to']),
            'exams' => StudentProgressReport::exams($student),
            'comparison' => StudentProgressReport::comparison($student),
        ];
    }
};
?>

@php
    use App\Support\ArabicCount;
    use App\Support\HijriDate;

    $ar = fn ($n) => HijriDate::arabicDigits($n);
    // A decimal the Arabic way: ٢٫٥, and a whole number without its «.0».
    $num = fn (float|int $n) => HijriDate::arabicDigits(str_replace('.', '٫', (string) (floor($n) == $n ? (int) $n : round($n, 1))));
    $pages = fn (float|int $n) => floor($n) == $n ? HijriDate::arabicDigits(ArabicCount::of((int) $n, ArabicCount::PAGES)) : $num($n).' وجه';
    $count = fn (int $n, array $forms) => HijriDate::arabicDigits(ArabicCount::of($n, $forms));

    // One colour per grade, as on the plan and the home page; spelled out for Tailwind.
    $grades = [
        3 => ['label' => 'ممتاز', 'fill' => 'bg-emerald-500', 'ring' => 'border-emerald-500'],
        2 => ['label' => 'جيد', 'fill' => 'bg-sky-500', 'ring' => 'border-sky-500'],
        1 => ['label' => 'مقبول', 'fill' => 'bg-amber-400', 'ring' => 'border-amber-400'],
        0 => ['label' => 'لم يسمع', 'fill' => 'bg-rose-500', 'ring' => 'border-rose-500'],
    ];
    $examStates = [
        'passed' => ['label' => 'ناجح', 'icon' => 'check', 'class' => 'bg-emerald-500 text-white'],
        'failed' => ['label' => 'لم يجتز', 'icon' => 'x-mark', 'class' => 'bg-rose-500 text-white'],
        'absent' => ['label' => 'غائب', 'icon' => 'minus', 'class' => 'bg-zinc-300 text-zinc-700 dark:bg-zinc-700 dark:text-zinc-200'],
        'pending' => ['label' => 'قادم', 'icon' => 'calendar', 'class' => 'border-2 border-dashed border-amber-400 text-amber-600 dark:text-amber-400'],
    ];

    $paceTop = max(0.5, $pace['average'], collect($pace['weeks'])->max('pages'));
    $lastWeek = count($pace['weeks']) - 1;
@endphp

<div class="space-y-5" dir="rtl">
    <div>
        <flux:heading size="xl" class="font-bold text-zinc-900 dark:text-white">التقارير</flux:heading>
        <flux:subheading class="mt-1">تقدّمك في الحفظ، وجودة تسميعك، والتزامك بخطتك.</flux:subheading>
    </div>

    {{-- ─────────── خريطة المصحف ─────────── --}}
    <section class="rounded-2xl border border-zinc-100 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-5" aria-label="خريطة المصحف">
        <div class="flex flex-col md:flex-row md:items-center gap-5">
            <div class="md:w-48 shrink-0">
                <div class="text-sm font-bold text-zinc-500 dark:text-zinc-400">محفوظك من المصحف</div>
                <div class="mt-1 flex items-baseline gap-1.5">
                    <span class="text-4xl font-black text-zinc-900 dark:text-white">{{ $num($mushaf['juz']) }}</span>
                    <span class="text-sm font-bold text-zinc-500">جزء</span>
                </div>
                <div class="text-sm text-zinc-500 dark:text-zinc-400">{{ $pages($mushaf['pages']) }} · {{ $num($mushaf['percentage']) }}٪</div>
            </div>

            <div class="flex-1">
                <div class="grid grid-cols-10 gap-1.5" data-mushaf-map>
                    @foreach ($mushaf['juzs'] as $juz)
                        @php
                            $isCurrent = $mushaf['current'] && $mushaf['current']['juz'] === $juz['juz'];
                        @endphp
                        <div wire:key="juz-{{ $juz['juz'] }}" data-juz="{{ $juz['juz'] }}" data-fraction="{{ $juz['fraction'] }}"
                            title="الجزء {{ $ar($juz['juz']) }}: {{ $ar((int) round($juz['fraction'] * 100)) }}٪"
                            class="relative h-10 overflow-hidden rounded-md bg-zinc-100 dark:bg-zinc-800 {{ $isCurrent ? 'ring-2 ring-amber-400' : '' }}">
                            {{-- Filled from the bottom as far as the juz is held. --}}
                            <span class="absolute inset-x-0 bottom-0 {{ $juz['fraction'] >= 1 ? 'bg-emerald-500' : 'bg-emerald-300 dark:bg-emerald-700' }}" style="height: {{ round($juz['fraction'] * 100) }}%"></span>
                            <span class="relative flex h-full items-center justify-center text-xs font-bold {{ $juz['fraction'] >= 0.5 ? 'text-white' : 'text-zinc-500 dark:text-zinc-400' }}">{{ $ar($juz['juz']) }}</span>
                        </div>
                    @endforeach
                </div>
                <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-zinc-500 dark:text-zinc-400">
                    <span class="flex items-center gap-1.5"><span class="size-2.5 rounded-sm bg-emerald-500"></span>محفوظ</span>
                    <span class="flex items-center gap-1.5"><span class="size-2.5 rounded-sm bg-emerald-300 dark:bg-emerald-700"></span>بعضه</span>
                    @if ($mushaf['current'])
                        <span class="flex items-center gap-1.5"><span class="size-2.5 rounded-sm ring-2 ring-amber-400"></span>تحفظ فيه الآن</span>
                    @endif
                </div>
            </div>
        </div>
    </section>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 items-start">

        {{-- ─────────── وتيرة الحفظ ─────────── --}}
        <section class="rounded-2xl border border-zinc-100 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-5" aria-label="وتيرة الحفظ">
            <div class="flex items-baseline justify-between gap-2">
                <div class="font-bold text-zinc-800 dark:text-zinc-100">وتيرة الحفظ</div>
                <div class="text-xs text-zinc-400">أوجه جديدة في الأسبوع</div>
            </div>

            <div class="relative mt-4 flex h-28 items-end gap-1.5" role="img" aria-label="الأوجه الجديدة المحفوظة في كل أسبوع">
                @if ($pace['average'] > 0)
                    <div class="pointer-events-none absolute inset-x-0 border-t border-dashed border-zinc-400 dark:border-zinc-500" style="bottom: {{ round($pace['average'] / $paceTop * 100) }}%"></div>
                @endif
                @foreach ($pace['weeks'] as $week)
                    <div wire:key="week-{{ $week['start'] }}" class="flex h-full flex-1 items-end" title="أسبوع {{ $week['label'] }}: {{ $pages($week['pages']) }}">
                        <span class="w-full rounded-t {{ $loop->index === $lastWeek ? 'bg-emerald-300 dark:bg-emerald-700' : 'bg-emerald-500' }}"
                            style="height: {{ $week['pages'] > 0 ? max(4, round($week['pages'] / $paceTop * 100)) : 0 }}%"></span>
                    </div>
                @endforeach
            </div>
            <div class="mt-1.5 flex justify-between text-[11px] text-zinc-400">
                <span>{{ $pace['weeks'][0]['label'] ?? '' }}</span>
                <span>هذا الأسبوع</span>
            </div>

            <p class="mt-3 text-sm text-zinc-700 dark:text-zinc-200" data-pace>
                @if ($pace['average'] > 0)
                    معدلك {{ $pages($pace['average']) }} في الأسبوع.
                    @if ($forecast)
                        <span class="text-zinc-500 dark:text-zinc-400">بهذا المعدل تُتم الجزء {{ $ar($forecast['juz']) }} بعد نحو {{ $count($forecast['weeks'], ArabicCount::WEEKS_GEN) }}.</span>
                    @endif
                @else
                    <span class="text-zinc-400">لم يُسجَّل لك حفظ في الأسابيع الأخيرة.</span>
                @endif
            </p>
        </section>

        {{-- ─────────── مقارنة بالشهر الماضي ─────────── --}}
        <section class="rounded-2xl border border-zinc-100 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-5" aria-label="مقارنة بالشهر الماضي">
            <div class="font-bold text-zinc-800 dark:text-zinc-100">هذا الشهر مقارنة بالشهر الماضي</div>
            <div class="text-xs text-zinc-400 mt-0.5">أول {{ $count($comparison['days'], ArabicCount::DAYS) }} من كلٍّ منهما</div>

            @php
                $metrics = [
                    ['label' => 'حفظ جديد', 'now' => $comparison['pages']['now'], 'before' => $comparison['pages']['before'], 'show' => $pages],
                    ['label' => 'تقدير ممتاز', 'now' => $comparison['excellent']['now'], 'before' => $comparison['excellent']['before'], 'show' => fn ($n) => $ar($n).'٪'],
                    ['label' => 'أيام حضور', 'now' => $comparison['attended']['now'], 'before' => $comparison['attended']['before'], 'show' => fn ($n) => $ar($n)],
                ];
            @endphp
            <div class="mt-4 grid grid-cols-3 gap-3 text-center">
                @foreach ($metrics as $metric)
                    @php
                        $delta = $metric['now'] === null || $metric['before'] === null ? null : $metric['now'] - $metric['before'];
                    @endphp
                    <div wire:key="metric-{{ $loop->index }}" class="rounded-xl bg-zinc-50 dark:bg-zinc-800/60 px-2 py-3">
                        <div class="flex items-center justify-center gap-1 text-lg font-black {{ $delta === null || $delta == 0 ? 'text-zinc-800 dark:text-zinc-100' : ($delta > 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400') }}">
                            @if ($delta !== null && $delta != 0)
                                <flux:icon :icon="$delta > 0 ? 'arrow-up' : 'arrow-down'" variant="micro" />
                            @endif
                            {{ $metric['now'] === null ? '—' : ($metric['show'])($metric['now']) }}
                        </div>
                        <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ $metric['label'] }}</div>
                        <div class="mt-1 text-[11px] text-zinc-400">كان {{ $metric['before'] === null ? '—' : ($metric['show'])($metric['before']) }}</div>
                    </div>
                @endforeach
            </div>
        </section>
    </div>

    {{-- ─────────── الجودة والالتزام، في الفترة المختارة ─────────── --}}
    <div class="flex flex-wrap items-center justify-between gap-3 pt-1">
        <div class="font-bold text-zinc-800 dark:text-zinc-100">جودة التسميع والالتزام بالخطة</div>
        <div class="inline-flex rounded-xl bg-zinc-100 dark:bg-zinc-800 p-1 text-sm" role="group" aria-label="الفترة">
            @foreach (['month' => 'هذا الشهر', 'term' => 'هذا الفصل'] as $value => $label)
                <button type="button" wire:click="$set('scope', '{{ $value }}')" aria-pressed="{{ $scope === $value ? 'true' : 'false' }}"
                    class="rounded-lg px-3 py-1 font-bold transition-colors {{ $scope === $value ? 'bg-white dark:bg-zinc-900 text-zinc-900 dark:text-white shadow-xs' : 'text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200' }}">
                    {{ $label }}
                </button>
            @endforeach
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 items-start">

        {{-- ─────────── جودة التسميع ─────────── --}}
        <section class="rounded-2xl border border-zinc-100 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-5 space-y-4" aria-label="جودة التسميع">
            @foreach (['hifz' => 'الحفظ', 'review' => 'المراجعة'] as $part => $partLabel)
                @php
                    $total = array_sum($quality[$part]);
                @endphp
                <div wire:key="quality-{{ $part }}" data-quality="{{ $part }}">
                    <div class="flex items-baseline justify-between text-sm">
                        <span class="font-bold text-zinc-700 dark:text-zinc-200">{{ $partLabel }}</span>
                        <span class="text-xs text-zinc-400">{{ $total > 0 ? $count($total, ArabicCount::SESSIONS_GEN) : 'لا تسميع في '.$period['label'] }}</span>
                    </div>
                    <div class="mt-1.5 flex h-3.5 overflow-hidden rounded-md bg-zinc-100 dark:bg-zinc-800">
                        @if ($total > 0)
                            @foreach ($grades as $grade => $style)
                                @if ($quality[$part][$grade] > 0)
                                    <span class="{{ $style['fill'] }}" style="width: {{ $quality[$part][$grade] / $total * 100 }}%" title="{{ $style['label'] }}: {{ $ar($quality[$part][$grade]) }}"></span>
                                @endif
                            @endforeach
                        @endif
                    </div>
                    @if ($total > 0)
                        <div class="mt-1 flex flex-wrap gap-x-3 text-[11px] text-zinc-500 dark:text-zinc-400">
                            @foreach ($grades as $grade => $style)
                                @if ($quality[$part][$grade] > 0)
                                    <span>{{ $style['label'] }} {{ $ar((int) round($quality[$part][$grade] / $total * 100)) }}٪</span>
                                @endif
                            @endforeach
                        </div>
                    @endif
                </div>
            @endforeach

            @if ($quality['recent'] !== [])
                <div class="border-t border-zinc-100 dark:border-zinc-800 pt-4">
                    <div class="text-sm font-bold text-zinc-700 dark:text-zinc-200">آخر تسميعاتك</div>
                    <div class="mt-2 flex flex-wrap gap-1.5" data-recent>
                        @foreach ($quality['recent'] as $session)
                            {{-- Memorising filled, review a ring: the colour is the grade. --}}
                            <span wire:key="recent-{{ $loop->index }}" title="{{ $session['label'] }} · {{ $session['part'] === 'hifz' ? 'حفظ' : 'مراجعة' }} · {{ $grades[$session['grade']]['label'] ?? '' }}"
                                class="size-4 rounded-full {{ $session['part'] === 'hifz' ? ($grades[$session['grade']]['fill'] ?? 'bg-zinc-300') : 'border-[3px] '.($grades[$session['grade']]['ring'] ?? 'border-zinc-300') }}"></span>
                        @endforeach
                    </div>
                    <div class="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-[11px] text-zinc-500 dark:text-zinc-400">
                        @foreach ($grades as $style)
                            <span class="flex items-center gap-1"><span class="size-2.5 rounded-full {{ $style['fill'] }}"></span>{{ $style['label'] }}</span>
                        @endforeach
                        <span class="flex items-center gap-1"><span class="size-2.5 rounded-full bg-zinc-400"></span>حفظ</span>
                        <span class="flex items-center gap-1"><span class="size-2.5 rounded-full border-2 border-zinc-400"></span>مراجعة</span>
                    </div>
                </div>
            @endif
        </section>

        {{-- ─────────── الالتزام بالخطة ─────────── --}}
        <section class="rounded-2xl border border-zinc-100 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-5" aria-label="الالتزام بالخطة" data-adherence="{{ $adherence['rate'] ?? 'none' }}">
            @if ($adherence['total'] === 0)
                <p class="text-sm text-zinc-400">لا جلسات حفظ من خطتك في {{ $period['label'] }}.</p>
            @else
                <div class="flex items-center gap-4">
                    <x-student.partials.progress-ring :percentage="$adherence['rate']" :size="84" progress-class="text-emerald-500">
                        <span class="text-lg font-black text-zinc-800 dark:text-zinc-100">{{ $ar($adherence['rate']) }}٪</span>
                    </x-student.partials.progress-ring>
                    <div class="min-w-0">
                        <div class="font-bold text-zinc-800 dark:text-zinc-100">الالتزام بالخطة</div>
                        <div class="text-sm text-zinc-600 dark:text-zinc-300">
                            أتممت الورد كاملاً في {{ $ar($adherence['whole']) }} من {{ $count($adherence['total'], ArabicCount::SESSIONS_GEN) }} حفظ.
                        </div>
                    </div>
                </div>
                {{-- How the sessions went, in proportion. --}}
                <div class="mt-4 flex h-2.5 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800">
                    <span class="bg-emerald-500" style="width: {{ $adherence['whole'] / $adherence['total'] * 100 }}%"></span>
                    <span class="bg-sky-400" style="width: {{ $adherence['short'] / $adherence['total'] * 100 }}%"></span>
                    <span class="bg-rose-500" style="width: {{ $adherence['not_heard'] / $adherence['total'] * 100 }}%"></span>
                </div>
                <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-zinc-500 dark:text-zinc-400">
                    <span class="flex items-center gap-1.5"><span class="size-2.5 rounded-full bg-emerald-500"></span>كاملاً {{ $ar($adherence['whole']) }}</span>
                    <span class="flex items-center gap-1.5"><span class="size-2.5 rounded-full bg-sky-400"></span>نقص حُمل لما بعده {{ $ar($adherence['short']) }}</span>
                    <span class="flex items-center gap-1.5"><span class="size-2.5 rounded-full bg-rose-500"></span>لم يسمع {{ $ar($adherence['not_heard']) }}</span>
                </div>
            @endif
        </section>
    </div>

    {{-- ─────────── الاختبارات ─────────── --}}
    @if ($exams !== [])
        <section class="rounded-2xl border border-zinc-100 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-5" aria-label="الاختبارات">
            <div class="flex items-center justify-between gap-2">
                <div class="font-bold text-zinc-800 dark:text-zinc-100">مسار اختباراتك</div>
                <a href="{{ route('student.exams') }}" wire:navigate class="text-xs font-bold text-emerald-700 dark:text-emerald-400 hover:underline">كل الاختبارات</a>
            </div>
            <ol class="mt-4 flex items-start">
                @foreach ($exams as $exam)
                    @php
                        $state = $examStates[$exam['status']] ?? $examStates['absent'];
                    @endphp
                    <li wire:key="exam-{{ $loop->index }}" class="relative flex flex-1 flex-col items-center text-center min-w-0" data-exam="{{ $exam['status'] }}">
                        @unless ($loop->last)
                            {{-- The line to the next exam, drawn from this one's centre. --}}
                            <span class="absolute top-4 left-0 h-0.5 w-1/2 {{ $exam['status'] === 'passed' ? 'bg-emerald-400' : 'bg-zinc-200 dark:bg-zinc-700' }}"></span>
                        @endunless
                        @unless ($loop->first)
                            <span class="absolute top-4 right-0 h-0.5 w-1/2 {{ $exams[$loop->index - 1]['status'] === 'passed' ? 'bg-emerald-400' : 'bg-zinc-200 dark:bg-zinc-700' }}"></span>
                        @endunless
                        <span class="relative z-10 flex size-8 items-center justify-center rounded-full bg-white dark:bg-zinc-900">
                            <span class="flex size-8 items-center justify-center rounded-full {{ $state['class'] }}">
                                <flux:icon :icon="$state['icon']" variant="micro" />
                            </span>
                        </span>
                        <span class="mt-1.5 w-full truncate px-1 text-xs font-bold text-zinc-700 dark:text-zinc-200">{{ $exam['level'] }}</span>
                        <span class="text-[11px] text-zinc-500 dark:text-zinc-400">
                            {{ $exam['score'] !== null ? $ar($exam['score']).'٪' : ($exam['status'] === 'pending' ? $exam['date'] : $state['label']) }}
                        </span>
                    </li>
                @endforeach
            </ol>
        </section>
    @endif
</div>
