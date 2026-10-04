<x-layouts.role-shell :title="__('تفاصيل الابن')">
    <x-slot:sidebar>
        @include('guardian.sidebar-nav')
    </x-slot:sidebar>

    @php
        $guardian = auth()->guard('guardian')->user();

        // Ensure this student belongs to the guardian
        $student = $guardian->students()->with('circle')->findOrFail($studentId);

        $memorizedPages = $student->memorizedPagesCount();
        $percentage = $student->memorizationPercentage();

        // Active plan
        $activePlan = $student->plans()
            ->whereIn('plan_type', ['hifz', 'hifz_review'])
            ->where('status', 'active')
            ->with('teacher')
            ->latest()
            ->first();

        // Today's plan day
        $todayPlanDay = null;
        if ($activePlan) {
            $todayPlanDay = $activePlan->days()
                ->whereDate('date', today())
                ->with(['fromAyah.surah', 'toAyah.surah', 'reviewFromAyah.surah', 'reviewToAyah.surah'])
                ->first();
        }

        // Recent scored days (last 15)
        $recentDays = \App\Models\StudentPlanDay::whereHas(
            'plan',
            fn($q) =>
            $q->where('student_id', $student->id)
        )
            ->whereNotNull('hifz_achievement')
            ->orderByDesc('date')
            ->with(['fromAyah.surah', 'toAyah.surah'])
            ->limit(15)
            ->get();

        // Build quran.com links for today's hifz
        $hifzLinks = [];
        if ($todayPlanDay && $todayPlanDay->fromAyah && $todayPlanDay->toAyah) {
            $hFrom = $todayPlanDay->fromAyah;
            $hTo = $todayPlanDay->toAyah;
            if ($hFrom->surah_id === $hTo->surah_id) {
                $hifzLinks[] = ['name' => $hFrom->surah->name_arabic, 'url' => 'https://quran.com/ar/' . $hFrom->surah->number . '/' . $hFrom->verse_number . '-' . $hTo->verse_number];
            } else {
                $low = min($hFrom->surah_id, $hTo->surah_id);
                $high = max($hFrom->surah_id, $hTo->surah_id);
                $dir = $hFrom->surah_id <= $hTo->surah_id ? 'asc' : 'desc';
                foreach (\App\Models\Surah::whereBetween('id', [$low, $high])->orderBy('id', $dir)->get() as $s) {
                    $from = $s->id === $hFrom->surah_id ? $hFrom->verse_number : 1;
                    $to = $s->id === $hTo->surah_id ? $hTo->verse_number : $s->verses_count;
                    $hifzLinks[] = ['name' => $s->name_arabic, 'url' => 'https://quran.com/ar/' . $s->number . '/' . $from . '-' . $to];
                }
            }
        }

        // Build quran.com links for today's review
        $reviewLinks = [];
        if ($todayPlanDay && $todayPlanDay->reviewFromAyah && $todayPlanDay->reviewToAyah) {
            $rFrom = $todayPlanDay->reviewFromAyah;
            $rTo = $todayPlanDay->reviewToAyah;
            if ($rFrom->surah_id === $rTo->surah_id) {
                $reviewLinks[] = ['name' => $rFrom->surah->name_arabic, 'url' => 'https://quran.com/ar/' . $rFrom->surah->number . '/' . $rFrom->verse_number . '-' . $rTo->verse_number];
            } else {
                $low = min($rFrom->surah_id, $rTo->surah_id);
                $high = max($rFrom->surah_id, $rTo->surah_id);
                $dir = $rFrom->surah_id <= $rTo->surah_id ? 'asc' : 'desc';
                foreach (\App\Models\Surah::whereBetween('id', [$low, $high])->orderBy('id', $dir)->get() as $s) {
                    $from = $s->id === $rFrom->surah_id ? $rFrom->verse_number : 1;
                    $to = $s->id === $rTo->surah_id ? $rTo->verse_number : $s->verses_count;
                    $reviewLinks[] = ['name' => $s->name_arabic, 'url' => 'https://quran.com/ar/' . $s->number . '/' . $from . '-' . $to];
                }
            }
        }
    @endphp

    <div class="flex h-full w-full flex-1 flex-col gap-6">

        {{-- Back + Title --}}
        <div class="flex items-center gap-3">
            <a href="{{ route('guardian.dashboard') }}"
                class="p-2 rounded-lg text-zinc-500 hover:text-zinc-700 hover:bg-zinc-100 dark:hover:bg-zinc-800   s">
                <flux:icon icon="arrow-right" class="size-5" />
            </a>
            <div>
                <h1 class="text-xl font-bold text-zinc-900 dark:text-white">{{ $student->name }}</h1>
                <p class="text-sm text-zinc-500">{{ $student->circle?->name ?? 'لم تُحدَّد حلقة' }}</p>
            </div>
        </div>

        {{-- Top stats --}}
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            <div class="rounded-2xl border border-zinc-100 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-4">
                <p class="text-xs text-zinc-500 mb-1">نسبة المحفوظ من القرآن الكريم</p>
                <p class="text-2xl font-bold text-emerald-600 dark:text-emerald-400">{{ $percentage }}%</p>
                <p class="text-xs text-emerald-600 dark:text-emerald-400 mt-1 font-medium">
                    {{ $student->memorizationText() }}
                </p>
                <p class="text-xs text-zinc-400 mt-1">{{ number_format($memorizedPages) }} صفحة</p>
            </div>
            <div class="rounded-2xl border border-zinc-100 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-4">
                <p class="text-xs text-zinc-500 mb-1">الأجزاء المحفوظة</p>
                <p class="text-2xl font-bold text-blue-600 dark:text-blue-400">{{ floor($memorizedPages / 20) }}</p>
                <p class="text-xs text-zinc-400 mt-1">من 30 جزءاً</p>
            </div>
            <div class="rounded-2xl border border-zinc-100 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-4">
                <p class="text-xs text-zinc-500 mb-1">أيام تم تقييمها</p>
                <p class="text-2xl font-bold text-violet-600 dark:text-violet-400">{{ $recentDays->count() }}</p>
                <p class="text-xs text-zinc-400 mt-1">آخر 15 يوم مقيّم</p>
            </div>
            <div class="rounded-2xl border border-zinc-100 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-4">
                <p class="text-xs text-zinc-500 mb-1">الحالة</p>
                <p
                    class="text-sm font-bold {{ $student->is_approved ? 'text-emerald-600 dark:text-emerald-400' : 'text-amber-600 dark:text-amber-400' }}">
                    {{ $student->is_approved ? 'معتمد' : 'قيد الانتظار' }}
                </p>
                <p class="text-xs text-zinc-400 mt-1">{{ $student->joined_at?->format('Y/m/d') ?? '—' }}</p>
            </div>
        </div>

        {{-- Memorization journey + progress charts --}}
        <x-guardian.memorization-journey :student="$student" />

        {{-- Mutun (hadith) and ode paths progress --}}
        <x-guardian.mutun-odes :student="$student" />

        <div class="grid md:grid-cols-2 gap-6">

            {{-- Today's task --}}
            <div class="rounded-2xl border border-zinc-100 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-5">
                <h2 class="font-semibold text-zinc-900 dark:text-white mb-4 flex items-center gap-2">
                    <flux:icon icon="calendar-days" class="size-5 text-blue-500" />
                    مهمة اليوم
                </h2>

                @if($todayPlanDay)
                    {{-- Hifz section --}}
                    @if($todayPlanDay->fromAyah)
                        <div class="mb-4">
                            <p class="text-xs font-medium text-zinc-500 mb-2">الحفظ</p>
                            <p class="text-sm text-zinc-800 dark:text-zinc-200 font-medium mb-2">
                                {{ $todayPlanDay->formatRange('hifz') }}
                            </p>
                            {{-- quran.com links --}}
                            @if(count($hifzLinks) === 1)
                                <a href="{{ $hifzLinks[0]['url'] }}" target="_blank"
                                    class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-indigo-100 dark:bg-indigo-500/20 text-xs font-medium text-indigo-700 dark:text-indigo-300 hover:bg-indigo-200   s">
                                    <flux:icon icon="book-open" class="size-3.5" />
                                    افتح {{ $hifzLinks[0]['name'] }}
                                </a>
                            @elseif(count($hifzLinks) > 1)
                                <div x-data="{ open: false }">
                                    <button @click="open = !open"
                                        class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-indigo-100 dark:bg-indigo-500/20 text-xs font-medium text-indigo-700 dark:text-indigo-300 hover:bg-indigo-200   s">
                                        <flux:icon icon="book-open" class="size-3.5" />
                                        افتح الآيات ({{ count($hifzLinks) }})
                                        <flux:icon icon="chevron-down" class="size-3.5 transition-transform"
                                            x-bind:class="open ? 'rotate-180' : ''" />
                                    </button>
                                    <div x-show="open" x-collapse class="flex flex-wrap gap-2 mt-2">
                                        @foreach($hifzLinks as $link)
                                            <a href="{{ $link['url'] }}" target="_blank"
                                                class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-indigo-100 dark:bg-indigo-500/20 text-xs font-medium text-indigo-700 dark:text-indigo-300 hover:bg-indigo-200   s">
                                                <flux:icon icon="book-open" class="size-3.5" />
                                                {{ $link['name'] }}
                                            </a>
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                        </div>
                    @endif

                    {{-- Review section --}}
                    @if($todayPlanDay->reviewFromAyah)
                        <div>
                            <p class="text-xs font-medium text-zinc-500 mb-2">المراجعة</p>
                            <p class="text-sm text-zinc-800 dark:text-zinc-200 font-medium mb-2">
                                {{ $todayPlanDay->formatRange('review') }}
                            </p>
                            @if(count($reviewLinks) === 1)
                                <a href="{{ $reviewLinks[0]['url'] }}" target="_blank"
                                    class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-emerald-100 dark:bg-emerald-500/20 text-xs font-medium text-emerald-700 dark:text-emerald-300 hover:bg-emerald-200   s">
                                    <flux:icon icon="book-open" class="size-3.5" />
                                    افتح {{ $reviewLinks[0]['name'] }}
                                </a>
                            @elseif(count($reviewLinks) > 1)
                                <div x-data="{ open: false }">
                                    <button @click="open = !open"
                                        class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-emerald-100 dark:bg-emerald-500/20 text-xs font-medium text-emerald-700 dark:text-emerald-300 hover:bg-emerald-200   s">
                                        <flux:icon icon="book-open" class="size-3.5" />
                                        افتح الآيات ({{ count($reviewLinks) }})
                                        <flux:icon icon="chevron-down" class="size-3.5 transition-transform"
                                            x-bind:class="open ? 'rotate-180' : ''" />
                                    </button>
                                    <div x-show="open" x-collapse class="flex flex-wrap gap-2 mt-2">
                                        @foreach($reviewLinks as $link)
                                            <a href="{{ $link['url'] }}" target="_blank"
                                                class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-emerald-100 dark:bg-emerald-500/20 text-xs font-medium text-emerald-700 dark:text-emerald-300 hover:bg-emerald-200   s">
                                                <flux:icon icon="book-open" class="size-3.5" />
                                                {{ $link['name'] }}
                                            </a>
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                        </div>
                    @endif
                @else
                    <p class="text-sm text-zinc-400 py-4 text-center">لا توجد مهمة مجدولة لهذا اليوم</p>
                @endif
            </div>

            {{-- Active plan info --}}
            <div class="rounded-2xl border border-zinc-100 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-5">
                <h2 class="font-semibold text-zinc-900 dark:text-white mb-4 flex items-center gap-2">
                    <flux:icon icon="clipboard-document-list" class="size-5 text-violet-500" />
                    الخطة الحالية
                </h2>

                @if($activePlan)
                    <div class="space-y-3">
                        <div class="flex justify-between text-sm">
                            <span class="text-zinc-500">المعلم</span>
                            <span
                                class="font-medium text-zinc-800 dark:text-zinc-200">{{ $activePlan->teacher?->name ?? '—' }}</span>
                        </div>
                        <div class="flex justify-between text-sm">
                            <span class="text-zinc-500">تاريخ البدء</span>
                            <span
                                class="font-medium text-zinc-800 dark:text-zinc-200"><x-hijri-date :date="$activePlan->start_date" /></span>
                        </div>
                        <div class="flex justify-between text-sm">
                            <span class="text-zinc-500">عدد الأيام</span>
                            <span class="font-medium text-zinc-800 dark:text-zinc-200">{{ $activePlan->days_count }}
                                يوم</span>
                        </div>
                        <div class="flex justify-between text-sm">
                            <span class="text-zinc-500">الأيام المنجزة</span>
                            @php $completedDays = $activePlan->days()->recited('hifz')->count(); @endphp
                            <span class="font-medium text-zinc-800 dark:text-zinc-200">{{ $completedDays }} /
                                {{ $activePlan->days_count }}</span>
                        </div>

                        {{-- Plan progress bar --}}
                        @php $planPct = $activePlan->days_count > 0 ? round($completedDays / $activePlan->days_count * 100) : 0; @endphp
                        <div>
                            <div class="flex justify-between text-xs text-zinc-400 mb-1">
                                <span>تقدم الخطة</span>
                                <span>{{ $planPct }}%</span>
                            </div>
                            <div class="w-full bg-zinc-200 dark:bg-zinc-700 rounded-full h-2 overflow-hidden">
                                <div class="h-2 rounded-full bg-gradient-to-r from-violet-400 to-violet-600  "
                                    style="width: {{ $planPct }}%"></div>
                            </div>
                        </div>

                        <a href="{{ route('guardian.student.attendance', $student->id) }}"
                            class="mt-2 w-full inline-flex items-center justify-center gap-2 px-3 py-2 rounded-lg border border-zinc-200 dark:border-zinc-700 text-sm text-zinc-600 dark:text-zinc-300 hover:bg-zinc-50 dark:hover:bg-zinc-700   s">
                            <flux:icon icon="calendar" class="size-4" />
                            عرض سجل الحضور
                        </a>
                    </div>
                @else
                    <p class="text-sm text-zinc-400 py-4 text-center">لا توجد خطة نشطة</p>
                @endif
            </div>
        </div>

        {{-- Recent performance table --}}
        <div class="rounded-2xl border border-zinc-100 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-5">
            <h2 class="font-semibold text-zinc-900 dark:text-white mb-4 flex items-center gap-2">
                <flux:icon icon="chart-bar" class="size-5 text-amber-500" />
                سجل الأداء الأخير
            </h2>

            @if($recentDays->isNotEmpty())
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-zinc-100 dark:border-zinc-700 text-xs text-zinc-500">
                                <th class="text-right pb-3 font-medium">التاريخ</th>
                                <th class="text-right pb-3 font-medium">الحفظ</th>
                                <th class="text-center pb-3 font-medium">تقييم الحفظ</th>
                                <th class="text-center pb-3 font-medium">تقييم المراجعة</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            @foreach($recentDays as $day)
                                @php
                                    $hScore = $day->hifz_achievement;
                                    $rScore = $day->review_achievement;

                                    $hBadge = match ($hScore) {
                                        3 => ['label' => 'ممتاز', 'class' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-400'],
                                        2 => ['label' => 'جيد', 'class' => 'bg-amber-100  text-amber-700  dark:bg-amber-500/20  dark:text-amber-400'],
                                        1 => ['label' => 'مقبول', 'class' => 'bg-orange-100 text-orange-700 dark:bg-orange-500/20 dark:text-orange-400'],
                                        0 => ['label' => 'لم يسمع', 'class' => 'bg-red-100    text-red-700    dark:bg-red-500/20    dark:text-red-400'],
                                        default => null,
                                    };
                                    $rBadge = match ($rScore) {
                                        3 => ['label' => 'ممتاز', 'class' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-400'],
                                        2 => ['label' => 'جيد', 'class' => 'bg-amber-100  text-amber-700  dark:bg-amber-500/20  dark:text-amber-400'],
                                        1 => ['label' => 'مقبول', 'class' => 'bg-orange-100 text-orange-700 dark:bg-orange-500/20 dark:text-orange-400'],
                                        0 => ['label' => 'لم يسمع', 'class' => 'bg-red-100    text-red-700    dark:bg-red-500/20    dark:text-red-400'],
                                        default => null,
                                    };
                                @endphp
                                <tr>
                                    <td class="py-3 text-zinc-600 dark:text-zinc-400"><x-hijri-date :date="$day->date" />
                                    </td>
                                    <td class="py-3 text-zinc-800 dark:text-zinc-200">
                                        @if($day->fromAyah)
                                            {{ $day->fromAyah->surah->name_arabic }}
                                            {{ $day->fromAyah->verse_number }}-{{ $day->toAyah->verse_number ?? '—' }}
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="py-3 text-center">
                                        @if($hBadge)
                                            <span
                                                class="px-2 py-0.5 rounded-full text-xs font-medium {{ $hBadge['class'] }}">{{ $hBadge['label'] }}</span>
                                        @else
                                            <span class="text-zinc-400">—</span>
                                        @endif
                                    </td>
                                    <td class="py-3 text-center">
                                        @if($rBadge)
                                            <span
                                                class="px-2 py-0.5 rounded-full text-xs font-medium {{ $rBadge['class'] }}">{{ $rBadge['label'] }}</span>
                                        @else
                                            <span class="text-zinc-400">—</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <p class="text-sm text-zinc-400 py-4 text-center">لا توجد تقييمات مسجلة بعد</p>
            @endif
        </div>
    </div>
</x-layouts.role-shell>