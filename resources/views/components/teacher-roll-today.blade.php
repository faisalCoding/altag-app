@props([
    /** @var array<int, array{stage: string, supervisors: string, expected: int, marked: int, absent: int, late: int, excused: int}> */
    'rows' => [],
    'rollRoute',
    'reportRoute',
    'showSupervisors' => false,
])

@php
    $ar = fn ($n) => App\Support\HijriDate::arabicDigits((int) $n);
@endphp

{{-- Today's roll call for the teachers, stage by stage: who is still to be
     marked, and who is away — the first thing to check on a working morning. --}}
<div class="rounded-2xl border border-zinc-100 dark:border-zinc-800 bg-white dark:bg-zinc-900 overflow-hidden">
    <div class="flex flex-wrap items-center justify-between gap-3 p-5 border-b border-zinc-100 dark:border-zinc-800">
        <div class="flex items-center gap-2.5">
            <flux:icon icon="clipboard-document-check" class="size-5 text-cyan-600" />
            <flux:heading size="sm">تحضير المعلمين اليوم</flux:heading>
        </div>
        <div class="flex items-center gap-3">
            @if (App\Support\RolePages::isEnabled(Str::before($reportRoute, '.'), $reportRoute))
                <flux:link :accent="false" :href="route($reportRoute)" wire:navigate class="text-sm">التقرير</flux:link>
            @endif
            <flux:button size="sm" variant="primary" :href="route($rollRoute)" wire:navigate>فتح التحضير</flux:button>
        </div>
    </div>

    @if ($rows === [])
        <p class="text-sm text-zinc-400 text-center py-8">لا دوام اليوم في التقويم الأكاديمي.</p>
    @else
        <div class="divide-y divide-zinc-100 dark:divide-zinc-800">
            @foreach ($rows as $row)
                @php
                    $done = $row['marked'] >= $row['expected'];
                    $percent = $row['expected'] > 0 ? min(100, (int) round($row['marked'] / $row['expected'] * 100)) : 0;
                @endphp
                <div wire:key="roll-today-{{ $loop->index }}" class="p-4 space-y-2">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div class="min-w-0">
                            <div class="font-bold text-zinc-800 dark:text-zinc-100">{{ $row['stage'] }}</div>
                            @if ($showSupervisors)
                                <div class="text-xs text-zinc-400">{{ $row['supervisors'] !== '' ? $row['supervisors'] : 'بلا مشرف' }}</div>
                            @endif
                        </div>
                        @if ($done)
                            <flux:badge size="sm" color="green">مكتمل</flux:badge>
                        @elseif ($row['marked'] > 0)
                            <flux:badge size="sm" color="amber">حُضِّر {{ $ar($row['marked']) }} من {{ $ar($row['expected']) }}</flux:badge>
                        @else
                            <flux:badge size="sm" color="red">لم يُحضَّر بعد · {{ $ar($row['expected']) }} معلمين</flux:badge>
                        @endif
                    </div>

                    <div class="w-full bg-zinc-100 dark:bg-zinc-800 rounded-full h-1.5 overflow-hidden">
                        <div class="h-1.5 rounded-full {{ $done ? 'bg-emerald-500' : 'bg-amber-500' }}" style="width: {{ $percent }}%"></div>
                    </div>

                    @if ($row['absent'] + $row['late'] + $row['excused'] > 0)
                        <div class="flex flex-wrap gap-3 text-xs">
                            @if ($row['absent'] > 0)
                                <span class="text-rose-600 dark:text-rose-400">غائب {{ $ar($row['absent']) }}</span>
                            @endif
                            @if ($row['late'] > 0)
                                <span class="text-amber-600 dark:text-amber-400">متأخر {{ $ar($row['late']) }}</span>
                            @endif
                            @if ($row['excused'] > 0)
                                <span class="text-sky-600 dark:text-sky-400">مستأذن {{ $ar($row['excused']) }}</span>
                            @endif
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</div>
