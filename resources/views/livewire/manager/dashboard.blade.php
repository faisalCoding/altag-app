<div class="space-y-8 p-6" dir="rtl">

    @if (\App\Support\RolePages::isEnabled('manager', 'manager.teacher-attendance'))
        <x-teacher-roll-today :rows="$teacherRoll" roll-route="manager.teacher-attendance"
            report-route="manager.teacher-attendance-report" show-supervisors />
    @endif

    {{-- ══════════════════════════════════════════ --}}
    {{--  SECTION 1 – ATTENDANCE                   --}}
    {{-- ══════════════════════════════════════════ --}}
    <div class="space-y-4">

        {{-- Header + period selector --}}
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
            <div class="flex items-center gap-3">
                <div class="p-2.5 rounded-xl bg-emerald-50 dark:bg-emerald-900/30 text-emerald-600 dark:text-emerald-400 shadow-sm">
                    <flux:icon icon="user-group" class="size-6" />
                </div>
                <div>
                    <flux:heading size="xl" class="font-bold">إحصائيات الحضور</flux:heading>
                    <flux:subheading class="text-zinc-500">
                        {{ $attDates['label'] }}
                        @if($attDates['from'] !== $attDates['to'])
                            &nbsp;·&nbsp; <x-hijri-date :date="\Carbon\Carbon::parse($attDates['from'])" /> → <x-hijri-date :date="\Carbon\Carbon::parse($attDates['to'])" />
                        @else
                            &nbsp;·&nbsp; <x-hijri-date :date="\Carbon\Carbon::parse($attDates['from'])" />
                        @endif
                    </flux:subheading>
                </div>
            </div>

            {{-- Period Chips --}}
            <div class="flex flex-wrap items-center gap-2">
                @foreach([
                    'today'     => 'اليوم',
                    'yesterday' => 'أمس',
                    'last_data' => 'آخر يوم بيانات',
                    'last_week' => 'الأسبوع الماضي',
                    'custom'    => 'تاريخ مخصص',
                ] as $key => $label)
                    <button wire:click="setAttendancePeriod('{{ $key }}')"
                        class="px-3 py-1.5 text-xs font-semibold rounded-lg border transition-all
                            {{ $attendancePeriod === $key
                                ? 'bg-emerald-500 border-emerald-500 text-white shadow-md shadow-emerald-500/20'
                                : 'bg-white dark:bg-zinc-800 border-zinc-200 dark:border-zinc-700 text-zinc-600 dark:text-zinc-400 hover:border-emerald-400' }}">
                        {{ $label }}
                    </button>
                @endforeach
            </div>
        </div>

        {{-- Custom date range --}}
        @if($attendancePeriod === 'custom')
            <div class="flex flex-wrap items-center gap-3 p-4 bg-zinc-50 dark:bg-zinc-800/50 rounded-xl border border-zinc-200 dark:border-zinc-700">
                <div class="flex flex-col gap-1 min-w-[160px]">
                    <label class="text-xs font-medium text-zinc-500">من تاريخ</label>
                    <livewire:manager.hijri-datepicker wire:model.live="attFrom" />
                </div>
                <div class="flex flex-col gap-1 min-w-[160px]">
                    <label class="text-xs font-medium text-zinc-500">إلى تاريخ</label>
                    <livewire:manager.hijri-datepicker wire:model.live="attTo" />
                </div>
            </div>
        @endif

        {{-- Content --}}
        @php $att = $attendanceData; @endphp

        @if($att['isSingleDay'] && $att['isSchoolDay'] === false)
            {{-- Not a school day --}}
            <div class="flex items-center gap-4 p-5 rounded-2xl border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-900/20">
                <div class="p-3 rounded-xl bg-amber-100 dark:bg-amber-800/50 text-amber-600 dark:text-amber-300">
                    <flux:icon icon="no-symbol" class="size-7" />
                </div>
                <div>
                    <flux:heading size="lg" class="text-amber-800 dark:text-amber-300">لا يوجد دوام اليوم</flux:heading>
                    <p class="text-amber-700/80 dark:text-amber-400 text-sm mt-0.5">هذا اليوم ليس ضمن أيام الدوام في التقويم الأكاديمي.</p>
                </div>
            </div>

        @elseif($att['total'] === 0)
            {{-- School day but no data --}}
            <div class="flex items-center gap-4 p-5 rounded-2xl border border-blue-200 dark:border-blue-800 bg-blue-50 dark:bg-blue-900/20">
                <div class="p-3 rounded-xl bg-blue-100 dark:bg-blue-800/50 text-blue-500 dark:text-blue-300">
                    <flux:icon icon="clock" class="size-7" />
                </div>
                <div>
                    <flux:heading size="lg" class="text-blue-800 dark:text-blue-300">لم يتم تسجيل بيانات حتى الآن</flux:heading>
                    <p class="text-blue-700/80 dark:text-blue-400 text-sm mt-0.5">لا توجد سجلات حضور لهذا الاختيار. ربما لم يتم تسجيل الحضور بعد.</p>
                </div>
            </div>

        @else
            {{-- Stats Cards --}}
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                {{-- Present --}}
                <div class="relative overflow-hidden rounded-2xl border border-emerald-100 dark:border-emerald-900/50 bg-white dark:bg-zinc-900 p-5 shadow-sm">
                    <div class="absolute top-0 right-0 w-1.5 h-full bg-emerald-500 rounded-r-2xl"></div>
                    <p class="text-xs font-bold text-zinc-500 dark:text-zinc-400 mb-1 uppercase tracking-wide">الحضور</p>
                    <p class="text-4xl font-black text-emerald-600 dark:text-emerald-400">{{ number_format($att['present']) }}</p>
                    @if($att['total'] > 0)
                        <div class="mt-2 w-full bg-emerald-100 dark:bg-emerald-900/30 rounded-full h-1.5 overflow-hidden">
                            <div class="h-1.5 rounded-full bg-emerald-500 transition-all duration-700"
                                style="width: {{ round($att['present'] / $att['total'] * 100) }}%"></div>
                        </div>
                        <p class="text-xs text-zinc-400 mt-1">{{ round($att['present'] / $att['total'] * 100) }}% من المسجلين</p>
                    @endif
                </div>

                {{-- Absent --}}
                <div class="relative overflow-hidden rounded-2xl border border-rose-100 dark:border-rose-900/50 bg-white dark:bg-zinc-900 p-5 shadow-sm">
                    <div class="absolute top-0 right-0 w-1.5 h-full bg-rose-500 rounded-r-2xl"></div>
                    <p class="text-xs font-bold text-zinc-500 dark:text-zinc-400 mb-1 uppercase tracking-wide">الغياب</p>
                    <p class="text-4xl font-black text-rose-600 dark:text-rose-400">{{ number_format($att['absent']) }}</p>
                    @if($att['total'] > 0)
                        <div class="mt-2 w-full bg-rose-100 dark:bg-rose-900/30 rounded-full h-1.5 overflow-hidden">
                            <div class="h-1.5 rounded-full bg-rose-500 transition-all duration-700"
                                style="width: {{ round($att['absent'] / $att['total'] * 100) }}%"></div>
                        </div>
                        <p class="text-xs text-zinc-400 mt-1">{{ round($att['absent'] / $att['total'] * 100) }}% من المسجلين</p>
                    @endif
                </div>

                {{-- Total Recorded --}}
                <div class="relative overflow-hidden rounded-2xl border border-zinc-100 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-5 shadow-sm">
                    <div class="absolute top-0 right-0 w-1.5 h-full bg-zinc-400 rounded-r-2xl"></div>
                    <p class="text-xs font-bold text-zinc-500 dark:text-zinc-400 mb-1 uppercase tracking-wide">إجمالي المسجلين</p>
                    <p class="text-4xl font-black text-zinc-700 dark:text-zinc-200">{{ number_format($att['total']) }}</p>
                    <p class="text-xs text-zinc-400 mt-3">تسجيلات في الفترة المحددة</p>
                </div>

                {{-- Attendance Rate --}}
                <div class="relative overflow-hidden rounded-2xl border border-indigo-100 dark:border-indigo-900/50 bg-white dark:bg-zinc-900 p-5 shadow-sm">
                    <div class="absolute top-0 right-0 w-1.5 h-full bg-indigo-500 rounded-r-2xl"></div>
                    <p class="text-xs font-bold text-zinc-500 dark:text-zinc-400 mb-1 uppercase tracking-wide">نسبة الحضور</p>
                    @php $rate = $att['total'] > 0 ? round($att['present'] / $att['total'] * 100) : 0; @endphp
                    <p class="text-4xl font-black {{ $rate >= 80 ? 'text-emerald-600 dark:text-emerald-400' : ($rate >= 60 ? 'text-amber-600 dark:text-amber-400' : 'text-rose-600 dark:text-rose-400') }}">
                        {{ $rate }}%
                    </p>
                    <p class="text-xs text-zinc-400 mt-3">{{ $rate >= 80 ? 'ممتاز' : ($rate >= 60 ? 'مقبول' : 'ضعيف') }}</p>
                </div>
            </div>

            {{-- Stage breakdown --}}
            @if(count($att['stageRows']) > 0)
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3 mt-2">
                    @foreach($att['stageRows'] as $row)
                        @php $stageRate = $row['total'] > 0 ? round($row['present'] / $row['total'] * 100) : 0; @endphp
                        <div class="flex items-center justify-between p-4 rounded-xl border border-zinc-100 dark:border-zinc-800 bg-zinc-50 dark:bg-zinc-800/30">
                            <div>
                                <p class="font-semibold text-zinc-800 dark:text-zinc-200 text-sm">{{ $row['name'] }}</p>
                                <p class="text-xs text-zinc-500 mt-0.5">
                                    <span class="text-emerald-600 dark:text-emerald-400 font-bold">{{ $row['present'] }}</span>
                                    / {{ $row['total'] }} مسجل
                                </p>
                            </div>
                            <div class="text-lg font-black {{ $stageRate >= 80 ? 'text-emerald-600 dark:text-emerald-400' : ($stageRate >= 60 ? 'text-amber-600' : 'text-rose-600') }}">
                                {{ $stageRate }}%
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        @endif
    </div>

    <div class="h-px bg-zinc-100 dark:bg-zinc-800 rounded-full"></div>

    {{-- ══════════════════════════════════════════ --}}
    {{--  SECTION 2 – QURAN ACTIVITY               --}}
    {{-- ══════════════════════════════════════════ --}}
    <div class="space-y-4">

        {{-- Header + period selector --}}
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
            <div class="flex items-center gap-3">
                <div class="p-2.5 rounded-xl bg-indigo-50 dark:bg-indigo-900/30 text-indigo-600 dark:text-indigo-400 shadow-sm">
                    <flux:icon icon="book-open" class="size-6" />
                </div>
                <div>
                    <flux:heading size="xl" class="font-bold">إحصائيات الحفظ والمراجعة</flux:heading>
                    <flux:subheading class="text-zinc-500">
                        {{ $quranDates['label'] }}
                        @if($quranDates['from'] !== $quranDates['to'])
                            &nbsp;·&nbsp; <x-hijri-date :date="\Carbon\Carbon::parse($quranDates['from'])" /> → <x-hijri-date :date="\Carbon\Carbon::parse($quranDates['to'])" />
                        @else
                            &nbsp;·&nbsp; <x-hijri-date :date="\Carbon\Carbon::parse($quranDates['from'])" />
                        @endif
                    </flux:subheading>
                </div>
            </div>

            {{-- Period Chips --}}
            <div class="flex flex-wrap items-center gap-2">
                @foreach([
                    'today'     => 'اليوم',
                    'yesterday' => 'أمس',
                    'last_data' => 'آخر يوم بيانات',
                    'last_week' => 'الأسبوع الماضي',
                    'custom'    => 'تاريخ مخصص',
                ] as $key => $label)
                    <button wire:click="setQuranPeriod('{{ $key }}')"
                        class="px-3 py-1.5 text-xs font-semibold rounded-lg border transition-all
                            {{ $quranPeriod === $key
                                ? 'bg-indigo-500 border-indigo-500 text-white shadow-md shadow-indigo-500/20'
                                : 'bg-white dark:bg-zinc-800 border-zinc-200 dark:border-zinc-700 text-zinc-600 dark:text-zinc-400 hover:border-indigo-400' }}">
                        {{ $label }}
                    </button>
                @endforeach
            </div>
        </div>

        {{-- Custom date range --}}
        @if($quranPeriod === 'custom')
            <div class="flex flex-wrap items-center gap-3 p-4 bg-zinc-50 dark:bg-zinc-800/50 rounded-xl border border-zinc-200 dark:border-zinc-700">
                <div class="flex flex-col gap-1 min-w-[160px]">
                    <label class="text-xs font-medium text-zinc-500">من تاريخ</label>
                    <livewire:manager.hijri-datepicker wire:model.live="quranFrom" />
                </div>
                <div class="flex flex-col gap-1 min-w-[160px]">
                    <label class="text-xs font-medium text-zinc-500">إلى تاريخ</label>
                    <livewire:manager.hijri-datepicker wire:model.live="quranTo" />
                </div>
            </div>
        @endif

        {{-- Content --}}
        @php $q = $quranData; @endphp

        @if($q['isSingleDay'] && $q['isSchoolDay'] === false)
            <div class="flex items-center gap-4 p-5 rounded-2xl border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-900/20">
                <div class="p-3 rounded-xl bg-amber-100 dark:bg-amber-800/50 text-amber-600 dark:text-amber-300">
                    <flux:icon icon="no-symbol" class="size-7" />
                </div>
                <div>
                    <flux:heading size="lg" class="text-amber-800 dark:text-amber-300">لا يوجد دوام اليوم</flux:heading>
                    <p class="text-amber-700/80 dark:text-amber-400 text-sm mt-0.5">هذا اليوم ليس ضمن أيام الدوام في التقويم الأكاديمي.</p>
                </div>
            </div>

        @elseif(! $q['hasData'])
            <div class="flex items-center gap-4 p-5 rounded-2xl border border-blue-200 dark:border-blue-800 bg-blue-50 dark:bg-blue-900/20">
                <div class="p-3 rounded-xl bg-blue-100 dark:bg-blue-800/50 text-blue-500 dark:text-blue-300">
                    <flux:icon icon="clock" class="size-7" />
                </div>
                <div>
                    <flux:heading size="lg" class="text-blue-800 dark:text-blue-300">لم يتم تسجيل بيانات حتى الآن</flux:heading>
                    <p class="text-blue-700/80 dark:text-blue-400 text-sm mt-0.5">لا توجد جلسات تسميع مسجلة لهذا الاختيار.</p>
                </div>
            </div>

        @else
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                {{-- Hifz sessions --}}
                <div class="relative overflow-hidden rounded-2xl border border-indigo-100 dark:border-indigo-900/50 bg-white dark:bg-zinc-900 p-5 shadow-sm">
                    <div class="absolute top-0 right-0 w-1.5 h-full bg-indigo-500 rounded-r-2xl"></div>
                    <div class="flex items-center gap-2 mb-2">
                        <flux:icon icon="book-open" class="size-4 text-indigo-500" />
                        <p class="text-xs font-bold text-zinc-500 dark:text-zinc-400 uppercase tracking-wide">جلسات الحفظ</p>
                    </div>
                    <p class="text-4xl font-black text-indigo-600 dark:text-indigo-400">{{ number_format($q['hifzSessions']) }}</p>
                    <p class="text-xs text-zinc-400 mt-2">جلسة تسميع حفظ مسجلة</p>
                </div>

                {{-- Review sessions --}}
                <div class="relative overflow-hidden rounded-2xl border border-purple-100 dark:border-purple-900/50 bg-white dark:bg-zinc-900 p-5 shadow-sm">
                    <div class="absolute top-0 right-0 w-1.5 h-full bg-purple-500 rounded-r-2xl"></div>
                    <div class="flex items-center gap-2 mb-2">
                        <flux:icon icon="arrow-path" class="size-4 text-purple-500" />
                        <p class="text-xs font-bold text-zinc-500 dark:text-zinc-400 uppercase tracking-wide">جلسات المراجعة</p>
                    </div>
                    <p class="text-4xl font-black text-purple-600 dark:text-purple-400">{{ number_format($q['reviewSessions']) }}</p>
                    <p class="text-xs text-zinc-400 mt-2">جلسة مراجعة مسجلة</p>
                </div>

                {{-- Excellent grades --}}
                <div class="relative overflow-hidden rounded-2xl border border-amber-100 dark:border-amber-900/50 bg-white dark:bg-zinc-900 p-5 shadow-sm">
                    <div class="absolute top-0 right-0 w-1.5 h-full bg-amber-500 rounded-r-2xl"></div>
                    <div class="flex items-center gap-2 mb-2">
                        <flux:icon icon="star" class="size-4 text-amber-500" variant="solid" />
                        <p class="text-xs font-bold text-zinc-500 dark:text-zinc-400 uppercase tracking-wide">تقييمات ممتاز</p>
                    </div>
                    <p class="text-4xl font-black text-amber-600 dark:text-amber-400">{{ number_format($q['excellentCount']) }}</p>
                    <p class="text-xs text-zinc-400 mt-2">جلسة بتقييم ممتاز</p>
                </div>
            </div>

            {{-- Stage breakdown --}}
            @if(count($q['stageRows']) > 0)
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3 mt-2">
                    @foreach($q['stageRows'] as $row)
                        <div class="flex items-center justify-between p-4 rounded-xl border border-zinc-100 dark:border-zinc-800 bg-zinc-50 dark:bg-zinc-800/30">
                            <div>
                                <p class="font-semibold text-zinc-800 dark:text-zinc-200 text-sm">{{ $row['name'] }}</p>
                                <p class="text-xs text-zinc-500 mt-0.5 flex gap-3">
                                    <span><span class="text-indigo-600 dark:text-indigo-400 font-bold">{{ $row['hifz'] }}</span> حفظ</span>
                                    <span><span class="text-purple-600 dark:text-purple-400 font-bold">{{ $row['review'] }}</span> مراجعة</span>
                                </p>
                            </div>
                            <flux:icon icon="book-open" class="size-5 text-zinc-300 dark:text-zinc-600" />
                        </div>
                    @endforeach
                </div>
            @endif
        @endif
    </div>

</div>
