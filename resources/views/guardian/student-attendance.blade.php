<x-layouts.role-shell :title="__('سجل حضور الابن')">
    <x-slot:sidebar>
        @include('guardian.sidebar-nav')
    </x-slot:sidebar>

    @php
        $guardian = auth()->guard('guardian')->user();
        $student = $guardian->students()->findOrFail($studentId);

        // Month navigation via query param ?month=2026-04
        $monthParam = request('month');
        $currentMonth = $monthParam
            ? \Carbon\Carbon::createFromFormat('Y-m', $monthParam)->startOfMonth()
            : now()->startOfMonth();

        $prevMonth = $currentMonth->copy()->subMonth();
        $nextMonth = $currentMonth->copy()->addMonth();
        $canGoNext = $nextMonth->lte(now()->startOfMonth());

        // Fetch attendance for this month
        $attendances = $student->attendances()
            ->whereYear('date', $currentMonth->year)
            ->whereMonth('date', $currentMonth->month)
            ->get()
            ->keyBy(fn($a) => $a->date->format('Y-m-d'));

        // Calendar grid helpers
        $daysInMonth = $currentMonth->daysInMonth;
        $firstDayOfWeek = $currentMonth->copy()->startOfMonth()->dayOfWeek; // 0=Sun
        // We want Saturday as first column (Islamic week), so shift accordingly
        // Sat=6, Sun=0, Mon=1 ... Fri=5
        $startOffset = ($firstDayOfWeek + 1) % 7; // offset from Saturday

        $statusConfig = [
            'present' => ['label' => 'حاضر', 'bg' => 'bg-emerald-100 dark:bg-emerald-500/20', 'text' => 'text-emerald-700 dark:text-emerald-400', 'dot' => 'bg-emerald-500'],
            'late' => ['label' => 'متأخر', 'bg' => 'bg-amber-100  dark:bg-amber-500/20', 'text' => 'text-amber-700  dark:text-amber-400', 'dot' => 'bg-amber-500'],
            'absent' => ['label' => 'غائب', 'bg' => 'bg-red-100    dark:bg-red-500/20', 'text' => 'text-red-700    dark:text-red-400', 'dot' => 'bg-red-500'],
            'excused' => ['label' => 'غياب بعذر', 'bg' => 'bg-blue-100  dark:bg-blue-500/20', 'text' => 'text-blue-700   dark:text-blue-400', 'dot' => 'bg-blue-500'],
        ];

        // Summary counts
        $summary = [
            'present' => 0,
            'late' => 0,
            'absent' => 0,
            'excused' => 0,
        ];
        foreach ($attendances as $att) {
            if (isset($summary[$att->status])) {
                $summary[$att->status]++;
            }
        }
    @endphp

    <div class="flex h-full w-full flex-1 flex-col gap-6">

        {{-- Back + Title --}}
        <div class="flex items-center gap-3">
            <a href="{{ route('guardian.student', $student->id) }}"
                class="p-2 rounded-lg text-zinc-500 hover:text-zinc-700 hover:bg-zinc-100 dark:hover:bg-zinc-800   s">
                <flux:icon icon="arrow-right" class="size-5" />
            </a>
            <div>
                <h1 class="text-xl font-bold text-zinc-900 dark:text-white">سجل الحضور</h1>
                <p class="text-sm text-zinc-500">{{ $student->name }}</p>
            </div>
        </div>

        {{-- Summary cards --}}
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            @foreach($statusConfig as $key => $cfg)
                <div
                    class="rounded-2xl border border-zinc-100 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-4 flex items-center gap-3">
                    <div class="size-3 rounded-full {{ $cfg['dot'] }} shrink-0"></div>
                    <div>
                        <p class="text-xs text-zinc-500">{{ $cfg['label'] }}</p>
                        <p class="text-xl font-bold text-zinc-900 dark:text-white">{{ $summary[$key] }}</p>
                    </div>
                </div>
            @endforeach
        </div>

        {{-- Calendar --}}
        <div class="rounded-2xl border border-zinc-100 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-5">

            {{-- Month navigation --}}
            <div class="flex items-center justify-between mb-6">
                <a href="{{ route('guardian.student.attendance', ['id' => $student->id, 'month' => $prevMonth->format('Y-m')]) }}"
                    class="p-2 rounded-lg text-zinc-500 hover:text-zinc-700 hover:bg-zinc-100 dark:hover:bg-zinc-800   s">
                    <flux:icon icon="chevron-right" class="size-5" />
                </a>

                <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">
                    <x-hijri-date :date="$currentMonth" style="monthYear" />
                </h2>

                @if($canGoNext)
                    <a href="{{ route('guardian.student.attendance', ['id' => $student->id, 'month' => $nextMonth->format('Y-m')]) }}"
                        class="p-2 rounded-lg text-zinc-500 hover:text-zinc-700 hover:bg-zinc-100 dark:hover:bg-zinc-800   s">
                        <flux:icon icon="chevron-left" class="size-5" />
                    </a>
                @else
                    <div class="size-9"></div>
                @endif
            </div>

            {{-- Day headers (Sat → Fri) --}}
            <div class="grid grid-cols-7 mb-2">
                @foreach(['س', 'أ', 'إ', 'ث', 'خ', 'ج', 'ع'] as $dayLabel)
                    <div class="text-center text-xs font-medium text-zinc-400 py-2">{{ $dayLabel }}</div>
                @endforeach
            </div>

            {{-- Calendar cells --}}
            <div class="grid grid-cols-7 gap-1">
                {{-- Empty offset cells --}}
                @for($i = 0; $i < $startOffset; $i++)
                    <div></div>
                @endfor

                {{-- Day cells --}}
                @for($day = 1; $day <= $daysInMonth; $day++)
                    @php
                        $dateStr = $currentMonth->copy()->day($day)->format('Y-m-d');
                        $attendance = $attendances[$dateStr] ?? null;
                        $isToday = $dateStr === today()->format('Y-m-d');
                        $cfg = $attendance ? $statusConfig[$attendance->status] ?? null : null;
                    @endphp
                    <div
                        class="relative flex flex-col items-center justify-center rounded-lg py-2 min-h-[52px]
                                    {{ $cfg ? $cfg['bg'] : '' }}
                                    {{ $isToday && !$cfg ? 'ring-2 ring-blue-400 ring-offset-1 dark:ring-offset-zinc-800' : '' }}">
                        <span
                            class="text-sm font-medium {{ $cfg ? $cfg['text'] : 'text-zinc-600 dark:text-zinc-400' }}">
                            {{ $day }}
                        </span>
                        @if($cfg)
                            <span class="text-[10px] {{ $cfg['text'] }} opacity-75">{{ $cfg['label'] }}</span>
                        @endif
                    </div>
                @endfor
            </div>

            {{-- Legend --}}
            <div class="flex flex-wrap gap-4 mt-6 pt-4 border-t border-zinc-100 dark:border-zinc-800">
                @foreach($statusConfig as $key => $cfg)
                    <div class="flex items-center gap-1.5">
                        <div class="size-2.5 rounded-full {{ $cfg['dot'] }}"></div>
                        <span class="text-xs text-zinc-500">{{ $cfg['label'] }}</span>
                    </div>
                @endforeach
                <div class="flex items-center gap-1.5">
                    <div class="size-2.5 rounded-full ring-2 ring-blue-400 bg-transparent"></div>
                    <span class="text-xs text-zinc-500">اليوم</span>
                </div>
            </div>
        </div>
    </div>
</x-layouts.role-shell>