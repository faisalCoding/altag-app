@php
    use App\Support\HijriDate;

    $ar = fn ($n) => HijriDate::arabicDigits((int) $n);

    // The colours the supervisor's roll call marks with, spelled out for Tailwind.
    $statuses = [
        'present' => ['label' => 'حاضر', 'badge' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300'],
        'late' => ['label' => 'متأخر', 'badge' => 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300'],
        'excused' => ['label' => 'مستأذن', 'badge' => 'bg-sky-100 text-sky-700 dark:bg-sky-900/30 dark:text-sky-300'],
        'absent' => ['label' => 'غائب', 'badge' => 'bg-rose-100 text-rose-700 dark:bg-rose-900/30 dark:text-rose-300'],
    ];
@endphp

<div class="space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div class="flex items-center gap-3">
            <div class="p-2 rounded-lg bg-zinc-50 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">
                <flux:icon icon="clipboard-document-check" />
            </div>
            <div>
                <flux:heading size="xl" class="font-bold text-zinc-900 dark:text-white">سجل حضوري</flux:heading>
                <flux:subheading>ما سجّله المشرف لك في كل يوم دوام.</flux:subheading>
            </div>
        </div>

        {{-- Right-to-left: the next month sits to the left. --}}
        <div class="flex items-center gap-2 self-start sm:self-auto">
            <flux:button size="sm" variant="ghost" icon="chevron-right" wire:click="previousMonth" aria-label="الشهر السابق" />
            <span class="min-w-32 text-center font-bold text-zinc-800 dark:text-zinc-100">{{ $title }}</span>
            <flux:button size="sm" variant="ghost" icon="chevron-left" wire:click="nextMonth" :disabled="! $hasNext" aria-label="الشهر التالي" />
        </div>
    </div>

    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
        <div class="col-span-2 sm:col-span-1 rounded-2xl border border-zinc-100 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-4">
            <div class="text-xs text-zinc-500 dark:text-zinc-400">نسبة الحضور</div>
            <div class="text-2xl font-black mt-1 text-emerald-600 dark:text-emerald-400">{{ $rate === null ? '—' : $ar($rate).'٪' }}</div>
        </div>
        @foreach ($statuses as $status => $style)
            <div class="rounded-2xl border border-zinc-100 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-4">
                <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ $style['label'] }}</div>
                <div class="text-2xl font-black mt-1 text-zinc-800 dark:text-zinc-100">{{ $ar($counts[$status] ?? 0) }}</div>
            </div>
        @endforeach
        <div class="rounded-2xl border border-zinc-100 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-4">
            <div class="text-xs text-zinc-500 dark:text-zinc-400">لم يُسجَّل</div>
            <div class="text-2xl font-black mt-1 text-zinc-400">{{ $ar($unrecorded) }}</div>
        </div>
    </div>

    <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 shadow-xs overflow-hidden">
        @if ($days->isEmpty())
            <p class="text-sm text-zinc-400 text-center py-12">لا أيام دوام في هذا الشهر حتى اليوم.</p>
        @else
            <div class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @foreach ($days as $day)
                    @php
                        $record = $records->get($day);
                    @endphp
                    <div wire:key="my-day-{{ $day }}" class="flex flex-wrap items-center gap-x-3 gap-y-1 px-4 py-3 text-sm">
                        <span class="text-zinc-700 dark:text-zinc-200 min-w-44">{{ HijriDate::withWeekday($day) }}</span>
                        @if ($record)
                            <span class="rounded-md px-2 py-0.5 text-xs font-bold {{ $statuses[$record->status]['badge'] ?? '' }}">
                                {{ $statuses[$record->status]['label'] ?? $record->status }}
                            </span>
                            @if ($record->status === 'late' && $record->arrived_at)
                                <span class="text-xs text-amber-700 dark:text-amber-400">
                                    حضرت الساعة <span dir="ltr">{{ $record->arrivalLabel() }}</span>
                                    @if ($minutes = $record->minutesLate())
                                        · بعد البدء بـ{{ $ar($minutes) }} دقيقة
                                    @endif
                                </span>
                            @endif
                            @if ($record->notes)
                                <span class="w-full sm:w-auto text-xs text-zinc-500 dark:text-zinc-400">السبب: {{ $record->notes }}</span>
                            @endif
                            @if ($record->substitute && in_array($record->status, \App\Models\TeacherAttendance::AWAY, true))
                                <span class="text-xs text-zinc-500 dark:text-zinc-400">غطّى حلقتك: {{ $record->substitute->name }}</span>
                            @endif
                        @else
                            <span class="text-xs text-zinc-400">لم يُسجَّل</span>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
