@props(['report', 'showCircleColumn' => false])

@php
    $totals = $report['totals'];
    $perStudent = $report['perStudent'];
@endphp

<div class="space-y-6">

    {{-- Totals cards --}}
    <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-5 gap-4">
        <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 p-4">
            <div class="flex items-center gap-2 mb-1">
                <flux:icon icon="book-open" class="size-4 text-emerald-500" />
                <p class="text-xs text-zinc-500">حفظ القرآن</p>
            </div>
            <p class="text-2xl font-bold text-emerald-600 dark:text-emerald-400">{{ number_format($totals['hifz']['pages']) }} <span class="text-sm font-medium">صفحة</span></p>
            <p class="text-xs text-zinc-400 mt-1">
                {{ $totals['hifz']['days'] }} يوم تسميع
                @if($totals['hifz']['average'] !== null)
                    · متوسط التقييم {{ $totals['hifz']['average'] }}/3
                @endif
            </p>
        </div>

        <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 p-4">
            <div class="flex items-center gap-2 mb-1">
                <flux:icon icon="arrow-path" class="size-4 text-blue-500" />
                <p class="text-xs text-zinc-500">مراجعة القرآن</p>
            </div>
            <p class="text-2xl font-bold text-blue-600 dark:text-blue-400">{{ number_format($totals['review']['pages']) }} <span class="text-sm font-medium">صفحة</span></p>
            <p class="text-xs text-zinc-400 mt-1">
                {{ number_format($totals['review']['pages_distinct']) }} صفحة مميزة
                · {{ $totals['review']['days'] }} يوم مراجعة
                @if($totals['review']['average'] !== null)
                    · متوسط التقييم {{ $totals['review']['average'] }}/3
                @endif
            </p>
        </div>

        <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 p-4">
            <div class="flex items-center gap-2 mb-1">
                <flux:icon icon="calendar-days" class="size-4 text-violet-500" />
                <p class="text-xs text-zinc-500">الحضور</p>
            </div>
            <p class="text-2xl font-bold text-violet-600 dark:text-violet-400">
                {{ $totals['attendance']['rate'] !== null ? $totals['attendance']['rate'].'%' : '—' }}
            </p>
            <p class="text-xs text-zinc-400 mt-1">
                حاضر {{ $totals['attendance']['present'] }} · متأخر {{ $totals['attendance']['late'] }} · غائب {{ $totals['attendance']['absent'] }} · مستأذن {{ $totals['attendance']['excused'] }}
            </p>
            <p class="text-xs text-zinc-400 mt-0.5">
                من {{ $totals['attendance']['expected_days'] }} يوم دوام مطلوب · الاستئذان خارج الحساب
            </p>
        </div>

        <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 p-4">
            <div class="flex items-center gap-2 mb-1">
                <flux:icon icon="document-text" class="size-4 text-rose-500" />
                <p class="text-xs text-zinc-500">الأحاديث المحفوظة</p>
            </div>
            <p class="text-2xl font-bold text-rose-600 dark:text-rose-400">{{ number_format($totals['hadiths']) }} <span class="text-sm font-medium">حديثاً</span></p>
            <p class="text-xs text-zinc-400 mt-1">ضمن مسارات المتون</p>
        </div>

        <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 p-4">
            <div class="flex items-center gap-2 mb-1">
                <flux:icon icon="musical-note" class="size-4 text-amber-500" />
                <p class="text-xs text-zinc-500">أبيات المنظومات</p>
            </div>
            <p class="text-2xl font-bold text-amber-600 dark:text-amber-400">{{ number_format($totals['verses']) }} <span class="text-sm font-medium">بيتاً</span></p>
            <p class="text-xs text-zinc-400 mt-1">ضمن مسارات المنظومات</p>
        </div>
    </div>

    {{-- Per-student breakdown --}}
    <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 shadow-xs overflow-hidden">
        <flux:table>
            <flux:table.columns>
                <flux:table.column>الطالب</flux:table.column>
                @if($showCircleColumn)
                    <flux:table.column class="hidden md:table-cell">الحلقة</flux:table.column>
                @endif
                <flux:table.column class="text-center">صفحات الحفظ</flux:table.column>
                <flux:table.column class="text-center">صفحات المراجعة <span class="font-normal text-zinc-400">(المميزة)</span></flux:table.column>
                <flux:table.column class="text-center">
                    الحضور
                    <span class="block text-[10px] font-normal text-zinc-400">مستأذن / حاضر / أيام الدوام</span>
                </flux:table.column>
                <flux:table.column class="text-center">الأحاديث</flux:table.column>
                <flux:table.column class="text-center">الأبيات</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($perStudent as $row)
                    <flux:table.row :key="$row['student']->id">
                        <flux:table.cell class="font-bold text-zinc-900 dark:text-white">{{ $row['student']->name }}</flux:table.cell>
                        @if($showCircleColumn)
                            <flux:table.cell class="hidden md:table-cell">
                                <flux:badge size="sm" variant="neutral">{{ $row['student']->circle?->name ?? 'بدون حلقة' }}</flux:badge>
                            </flux:table.cell>
                        @endif
                        <flux:table.cell class="text-center">
                            <span class="font-semibold text-emerald-600 dark:text-emerald-400">{{ $row['hifz_pages'] }}</span>
                        </flux:table.cell>
                        <flux:table.cell class="text-center">
                            <span class="font-semibold text-blue-600 dark:text-blue-400">{{ $row['review_pages'] }}</span>
                            {{-- The distinct figure alongside: how much of the mushaf the
                                 repetitions covered, as against how much work they were. --}}
                            <span class="text-xs text-zinc-400 dark:text-zinc-500" title="صفحات مميزة دون تكرار">({{ $row['review_pages_distinct'] }})</span>
                        </flux:table.cell>
                        <flux:table.cell class="text-center">
                            @php
                                // Excused days sit outside the ratio on both sides, so the
                                // colour reads only the days the student was expected.
                                $attended = $row['present'] + $row['late'];
                                $expected = $row['expected_days'];
                            @endphp
                            @if($row['working_days'] > 0)
                                <flux:badge size="sm" :color="$expected === 0 ? 'zinc' : ($attended >= $expected * 0.8 ? 'green' : ($attended >= $expected * 0.5 ? 'amber' : 'red'))">
                                    {{ $row['excused'] }} / {{ $attended }} / {{ $row['working_days'] }}
                                </flux:badge>
                            @else
                                <span class="text-zinc-400">—</span>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell class="text-center">
                            <span class="font-semibold text-rose-600 dark:text-rose-400">{{ $row['hadiths'] }}</span>
                        </flux:table.cell>
                        <flux:table.cell class="text-center">
                            <span class="font-semibold text-amber-600 dark:text-amber-400">{{ $row['verses'] }}</span>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="{{ $showCircleColumn ? 7 : 6 }}" class="text-center py-16">
                            <flux:text class="text-zinc-400">لا يوجد طلاب ضمن النطاق المحدد</flux:text>
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>
</div>
