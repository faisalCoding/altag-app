<?php

use App\Models\AcademicCalendarEvent;
use App\Models\Attendance;
use App\Models\Student;
use App\Models\StudentPlanDay;
use App\Services\StudentStatusService;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component {
    public $showModal = false;

    public $studentId = null;

    public $newStatus = 'active';

    public $effectiveDate = '';

    public $returnDate = '';

    public $reason = '';

    public $pickerTarget = 'effective';

    public $monthOffset = 0;

    protected function actingRole(): ?string
    {
        foreach (['manager', 'supervisor', 'teacher'] as $role) {
            if (Auth::guard($role)->check()) {
                return $role;
            }
        }

        return null;
    }

    /**
     * The student scoped to the acting role: the manager sees everyone, the
     * supervisor their stages' circles, the teacher their own circles.
     */
    protected function scopedStudent(?int $studentId): ?Student
    {
        if (! $studentId) {
            return null;
        }

        return match ($this->actingRole()) {
            'manager' => Student::find($studentId),
            'supervisor' => Student::whereHas('circle', function ($q) {
                $q->whereIn('stage_id', Auth::guard('supervisor')->user()->stages()->pluck('stages.id'));
            })->find($studentId),
            'teacher' => Student::whereIn('circle_id', Auth::guard('teacher')->user()->circles()->pluck('circles.id'))
                ->find($studentId),
            default => null,
        };
    }

    #[On('open-status-manager')]
    public function open($studentId)
    {
        $role = $this->actingRole();

        if ($role === 'teacher' && empty(Auth::guard('teacher')->user()->effectivePermissions()['can_change_student_status'])) {
            Flux::toast('ليس لديك صلاحية تغيير حالة الطلاب', variant: 'danger');

            return;
        }

        $student = $this->scopedStudent((int) $studentId);

        if (! $student) {
            Flux::toast('الطالب خارج نطاق صلاحياتك', variant: 'danger');

            return;
        }

        $this->studentId = $student->id;
        $this->newStatus = $student->status ?: 'active';
        $this->effectiveDate = now('Asia/Riyadh')->format('Y-m-d');
        $this->returnDate = '';
        $this->reason = '';
        $this->pickerTarget = 'effective';
        $this->monthOffset = 0;
        $this->resetValidation();
        $this->showModal = true;
    }

    public function shiftMonth(int $direction)
    {
        $this->monthOffset += $direction;
    }

    public function selectDay(string $date)
    {
        $today = now('Asia/Riyadh')->format('Y-m-d');

        if ($this->pickerTarget === 'return') {
            if ($date <= ($this->effectiveDate ?: $today)) {
                Flux::toast('تاريخ العودة يجب أن يكون بعد تاريخ بداية الإيقاف', variant: 'warning');

                return;
            }
            $this->returnDate = $date;

            return;
        }

        if ($date > $today) {
            Flux::toast('تاريخ السريان لا يمكن أن يكون في المستقبل', variant: 'warning');

            return;
        }

        $this->effectiveDate = $date;

        if ($this->returnDate && $this->returnDate <= $date) {
            $this->returnDate = '';
        }
    }

    public function saveStatus()
    {
        $this->validate([
            'newStatus' => 'required|in:active,registering,suspended,left',
            // Pinned to Riyadh, like the default above and the date picker. A bare
            // "today" resolves against app.timezone (UTC), which is a day behind
            // Riyadh from 21:00 UTC onward — so between midnight and 3am local the
            // rule rejected the very date the form had just filled in.
            'effectiveDate' => 'required|date|before_or_equal:'.now('Asia/Riyadh')->format('Y-m-d'),
            'returnDate' => 'nullable|date',
            // Optional: the change is recorded with who made it and when either
            // way, and a mandatory box mostly collects the word "تحديث".
            'reason' => 'nullable|string|max:500',
        ], [
            'effectiveDate.before_or_equal' => 'تاريخ السريان لا يمكن أن يكون في المستقبل',
        ]);

        $student = $this->scopedStudent($this->studentId);
        if (! $student) {
            return;
        }

        try {
            if ($this->newStatus === 'suspended' && $this->returnDate) {
                StudentStatusService::suspendWithReturn($student, $this->effectiveDate, $this->returnDate, $this->reason);
            } else {
                StudentStatusService::changeStatus($student, $this->newStatus, $this->effectiveDate, $this->reason);
            }
        } catch (\InvalidArgumentException $e) {
            Flux::toast($e->getMessage(), variant: 'danger');

            return;
        }

        $this->showModal = false;
        $this->dispatch('student-list-updated');
        $this->dispatch('student-status-updated');
        Flux::toast('تم تحديث حالة الطالب بنجاح', variant: 'success');
    }

    /** @var array{status: string, start_date: string, notes: string}|null */
    public ?array $editingHistory = null;

    public ?int $editingHistoryId = null;

    public function editHistory(int $historyId): void
    {
        $student = $this->scopedStudent($this->studentId);

        $entry = $student?->statusHistories()->whereKey($historyId)->first();

        if (! $entry) {
            return;
        }

        $this->editingHistoryId = $entry->id;
        $this->editingHistory = [
            'status' => $entry->status,
            'start_date' => $entry->start_date->format('Y-m-d'),
            'notes' => $entry->notes ?? '',
        ];

    }

    public function cancelHistoryEdit(): void
    {
        $this->editingHistoryId = null;
        $this->editingHistory = null;
    }

    public function saveHistoryEdit(): void
    {
        $this->validate([
            'editingHistory.status' => 'required|in:active,registering,suspended,left',
            'editingHistory.start_date' => 'required|date|before_or_equal:'.now('Asia/Riyadh')->format('Y-m-d'),
            'editingHistory.notes' => 'nullable|string|max:500',
        ], [
            'editingHistory.start_date.before_or_equal' => 'تاريخ السريان لا يمكن أن يكون في المستقبل',
        ]);

        $student = $this->scopedStudent($this->studentId);

        if (! $student || ! $this->editingHistoryId) {
            return;
        }

        try {
            StudentStatusService::editHistoryEntry(
                $student,
                $this->editingHistoryId,
                $this->editingHistory['status'],
                $this->editingHistory['start_date'],
                $this->editingHistory['notes'] ?: null,
            );
        } catch (\InvalidArgumentException $e) {
            Flux::toast($e->getMessage(), variant: 'danger');

            return;
        }

        $this->cancelHistoryEdit();

        $this->dispatch('student-list-updated');
        $this->dispatch('student-status-updated');
        Flux::toast('تم تعديل سجل الحالة', variant: 'success');
    }

    public function deleteHistoryEntry(int $historyId)
    {
        $student = $this->scopedStudent($this->studentId);
        if (! $student) {
            return;
        }

        StudentStatusService::deleteHistoryEntry($student, $historyId);
        $this->dispatch('student-list-updated');
        $this->dispatch('student-status-updated');
        Flux::toast('تم حذف سجل الحالة', variant: 'success');
    }

    protected function isSchoolDay(string $date, $attendancePeriods): bool
    {
        $dayOfWeek = \Carbon\Carbon::parse($date)->dayOfWeek + 1; // 1=Sun .. 7=Sat

        return $attendancePeriods->some(function ($event) use ($date, $dayOfWeek) {
            $inRange = $event->start_date->format('Y-m-d') <= $date
                && (! $event->end_date || $event->end_date->format('Y-m-d') >= $date);
            $weekdays = $event->weekdays ?? [];

            return $inRange && (empty($weekdays) || in_array($dayOfWeek, $weekdays));
        });
    }

    public function with()
    {
        $student = $this->scopedStudent($this->studentId);

        if (! $student) {
            return ['student' => null, 'historyRows' => collect(), 'timeline' => [], 'monthDays' => [], 'monthName' => '', 'impact' => null];
        }

        $today = now('Asia/Riyadh')->format('Y-m-d');

        $historyRows = $student->statusHistories()
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->get();

        // ── Timeline segments (oldest right in RTL) ──
        $asc = $historyRows->sortBy([['start_date', 'asc'], ['id', 'asc']])->values();
        $timeline = [];
        if ($asc->isNotEmpty()) {
            $spanStart = $asc->first()->start_date->format('Y-m-d');
            $spanEnd = max($today, $asc->last()->start_date->format('Y-m-d'));
            $totalDays = max(1, \Carbon\Carbon::parse($spanStart)->diffInDays(\Carbon\Carbon::parse($spanEnd)) + 1);

            foreach ($asc as $i => $row) {
                $segStart = $row->start_date->format('Y-m-d');
                $segEnd = isset($asc[$i + 1]) ? $asc[$i + 1]->start_date->format('Y-m-d') : $spanEnd;
                $days = max(1, \Carbon\Carbon::parse($segStart)->diffInDays(\Carbon\Carbon::parse($segEnd)) + ($i === $asc->count() - 1 ? 1 : 0));

                $timeline[] = [
                    'status' => $row->status,
                    'start' => $segStart,
                    'days' => $days,
                    'width' => max(7, round($days / $totalDays * 100)),
                    'scheduled' => $segStart > $today,
                ];
            }
        }

        // ── Hijri month grid with school days highlighted ──
        $attendancePeriods = AcademicCalendarEvent::where('is_attendance_period', true)->get();

        $cal = \IntlCalendar::createInstance('Asia/Riyadh', 'ar_SA@calendar=islamic-umalqura');
        $cal->setTime(now('Asia/Riyadh')->getTimestampMs());
        $cal->set(\IntlCalendar::FIELD_DAY_OF_MONTH, 1);
        $cal->add(\IntlCalendar::FIELD_MONTH, $this->monthOffset);

        $monthName = \App\Support\HijriDate::monthYear($cal->getTime() / 1000);

        $monthLength = $cal->getActualMaximum(\IntlCalendar::FIELD_DAY_OF_MONTH);
        $startDayOfWeek = $cal->get(\IntlCalendar::FIELD_DAY_OF_WEEK);

        $monthDays = array_fill(0, $startDayOfWeek - 1, null);
        for ($i = 1; $i <= $monthLength; $i++) {
            $cal->set(\IntlCalendar::FIELD_DAY_OF_MONTH, $i);
            $gregDate = date('Y-m-d', (int) ($cal->getTime() / 1000));

            $monthDays[] = [
                'hijriDay' => $i,
                'date' => $gregDate,
                'isSchoolDay' => $this->isSchoolDay($gregDate, $attendancePeriods),
                'isToday' => $gregDate === $today,
                'isFuture' => $gregDate > $today,
            ];
        }

        // ── Impact preview for backdated changes ──
        $impact = null;
        if ($this->effectiveDate && $this->effectiveDate < $today) {
            $attendanceCount = Attendance::where('student_id', $student->id)
                ->whereDate('date', '>=', $this->effectiveDate)
                ->whereDate('date', '<=', $today)
                ->count();

            $tasmeehCount = StudentPlanDay::whereHas('plan', fn ($q) => $q->where('student_id', $student->id))
                ->whereDate('date', '>=', $this->effectiveDate)
                ->whereDate('date', '<=', $today)
                ->where(function ($q) {
                    $q->whereNotNull('hifz_achievement')->orWhereNotNull('review_achievement');
                })
                ->count();

            if ($attendanceCount > 0 || $tasmeehCount > 0) {
                $impact = [
                    'attendance' => $attendanceCount,
                    'tasmeeh' => $tasmeehCount,
                    'excluding' => $this->newStatus !== 'active',
                ];
            }
        }

        return [
            'student' => $student,
            'historyRows' => $historyRows,
            'timeline' => $timeline,
            'monthDays' => $monthDays,
            'monthName' => $monthName,
            'impact' => $impact,
            'today' => $today,
        ];
    }
};
?>

@php
    $statusLabels = ['active' => 'مشارك', 'registering' => 'تحت التسجيل', 'suspended' => 'موقوف', 'left' => 'غادر الحلقات'];
    $statusColors = ['active' => 'green', 'registering' => 'blue', 'suspended' => 'amber', 'left' => 'red'];
    $timelineBg = [
        'active' => 'bg-green-400 dark:bg-green-600',
        'registering' => 'bg-blue-400 dark:bg-blue-600',
        'suspended' => 'bg-amber-400 dark:bg-amber-600',
        'left' => 'bg-red-400 dark:bg-red-600',
    ];
    $roleLabels = ['manager' => 'المدير', 'supervisor' => 'المشرف', 'teacher' => 'المعلم'];
@endphp

<flux:modal wire:model="showModal" class="md:w-[640px]" dir="rtl">
    @if($student)
        <div class="space-y-6">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <flux:heading size="lg">{{ __('إدارة حالة الطالب') }}</flux:heading>
                    <flux:subheading>{{ $student->name }}</flux:subheading>
                </div>
                <flux:badge color="{{ $statusColors[$student->status] ?? 'zinc' }}">
                    {{ $statusLabels[$student->status] ?? $student->status }}
                </flux:badge>
            </div>

            {{-- Visual timeline --}}
            @if(count($timeline) > 0)
                <div>
                    <div class="text-xs font-bold text-zinc-500 mb-2">{{ __('الخط الزمني للحالات') }}</div>
                    <div class="flex w-full h-8 rounded-xl overflow-hidden border border-zinc-200 dark:border-zinc-700">
                        @foreach($timeline as $segment)
                            <div wire:key="segment-{{ $loop->index }}"
                                class="{{ $timelineBg[$segment['status']] ?? 'bg-zinc-300' }} {{ $segment['scheduled'] ? 'opacity-50' : '' }} flex items-center justify-center overflow-hidden"
                                style="width: {{ $segment['width'] }}%"
                                title="{{ $statusLabels[$segment['status']] ?? $segment['status'] }} — {{ $segment['days'] }} {{ __('يوم') }}">
                                <span class="text-[0.6rem] font-bold text-white truncate px-1">
                                    {{ $statusLabels[$segment['status']] ?? $segment['status'] }}
                                    @if($segment['scheduled']) ({{ __('مجدول') }}) @endif
                                </span>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- History rows --}}
            <div>
                <div class="text-xs font-bold text-zinc-500 mb-2">{{ __('سجل الحالات') }}</div>
                <div class="space-y-2 max-h-40 overflow-y-auto pr-1">
                    @forelse($historyRows as $history)
                        @if($editingHistoryId === $history->id)
                            {{-- The row itself becomes the form: a second modal over this
                                 one would sit behind it, and the record being corrected
                                 should stay in view while it is corrected. --}}
                            <div wire:key="history-edit-{{ $history->id }}"
                                class="p-3 border border-maroon/40 dark:border-white/20 rounded-xl bg-white dark:bg-zinc-900 space-y-3">
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                    <flux:select wire:model="editingHistory.status" size="sm" label="{{ __('الحالة') }}">
                                        @foreach($statusLabels as $value => $label)
                                            <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                                        @endforeach
                                    </flux:select>

                                    <livewire:shared.hijri-datepicker wire:model="editingHistory.start_date"
                                        :key="'edit-history-date-'.$history->id"
                                        label="{{ __('تاريخ السريان') }}" />
                                </div>

                                <flux:input wire:model="editingHistory.notes" size="sm"
                                    label="{{ __('ملاحظة (اختياري)') }}" />

                                @error('editingHistory.start_date') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                                @error('editingHistory.status') <p class="text-xs text-red-600">{{ $message }}</p> @enderror

                                <div class="flex justify-end gap-2">
                                    <flux:button size="sm" variant="ghost" wire:click="cancelHistoryEdit">{{ __('إلغاء') }}</flux:button>
                                    <flux:button size="sm" variant="primary" wire:click="saveHistoryEdit">{{ __('حفظ') }}</flux:button>
                                </div>
                            </div>
                        @else
                        <div wire:key="history-{{ $history->id }}"
                            class="flex items-center justify-between p-2.5 border border-zinc-200 dark:border-zinc-700/50 rounded-xl bg-zinc-50 dark:bg-zinc-800/50">
                            <div class="flex flex-col gap-0.5">
                                <div class="flex items-center gap-2">
                                    <flux:badge color="{{ $statusColors[$history->status] ?? 'zinc' }}" size="sm">
                                        {{ $statusLabels[$history->status] ?? $history->status }}
                                    </flux:badge>
                                    @if($history->start_date->format('Y-m-d') > $today)
                                        <flux:badge color="zinc" size="sm">{{ __('مجدول') }}</flux:badge>
                                    @endif
                                    <span class="text-xs text-zinc-500">
                                        <x-hijri-date :date="$history->start_date" />
                                        @if($history->end_date) ← <x-hijri-date :date="$history->end_date" /> @endif
                                    </span>
                                </div>
                                @if($history->notes)
                                    <span class="text-xs text-zinc-400">{{ $history->notes }}</span>
                                @endif
                                @if($history->changed_by_name)
                                    <span class="text-[0.65rem] text-zinc-400">
                                        {{ __('بواسطة') }}: {{ $roleLabels[$history->changed_by_role] ?? $history->changed_by_role }} {{ $history->changed_by_name }}
                                    </span>
                                @endif
                            </div>
                            <div class="flex items-center gap-1 shrink-0">
                                <flux:button size="xs" variant="ghost" icon="pencil-square"
                                    class="text-zinc-400 hover:text-zinc-600"
                                    wire:click="editHistory({{ $history->id }})" />
                                <flux:button size="xs" variant="ghost" icon="trash"
                                    class="text-red-400 hover:text-red-600"
                                    wire:click="deleteHistoryEntry({{ $history->id }})"
                                    wire:confirm="{{ __('حذف هذا السجل؟ سيُعاد احتساب حالة الطالب من السجلات المتبقية.') }}" />
                            </div>
                        </div>
                        @endif
                    @empty
                        <div class="text-sm text-zinc-500 text-center py-3">{{ __('لا يوجد سجل حالات.') }}</div>
                    @endforelse
                </div>
            </div>

            <flux:separator />

            {{-- Change form --}}
            <div class="space-y-4">
                <flux:heading size="sm">{{ __('تغيير الحالة') }}</flux:heading>

                <div class="grid grid-cols-2 gap-4">
                    <flux:select wire:model.live="newStatus" label="{{ __('الحالة الجديدة') }}">
                        <flux:select.option value="active">مشارك</flux:select.option>
                        <flux:select.option value="registering">تحت التسجيل</flux:select.option>
                        <flux:select.option value="suspended">موقوف</flux:select.option>
                        <flux:select.option value="left">غادر الحلقات</flux:select.option>
                    </flux:select>

                    <flux:input wire:model="reason" label="{{ __('سبب التغيير (اختياري)') }}"
                        placeholder="{{ __('مثال: انقطاع عن الحضور أسبوعين') }}" />
                </div>

                {{-- Date targets --}}
                <div class="grid {{ $newStatus === 'suspended' ? 'grid-cols-2' : 'grid-cols-1' }} gap-3">
                    <button type="button" wire:click="$set('pickerTarget', 'effective')"
                        class="text-right p-3 rounded-xl border transition-colors {{ $pickerTarget === 'effective' ? 'border-indigo-400 bg-indigo-50 dark:bg-indigo-900/20' : 'border-zinc-200 dark:border-zinc-700' }}">
                        <div class="text-xs text-zinc-500">{{ __('تاريخ سريان الحالة') }}</div>
                        <div class="font-bold text-sm mt-0.5">{{ $effectiveDate ?: __('اختر من التقويم') }}</div>
                    </button>

                    @if($newStatus === 'suspended')
                        <button type="button" wire:click="$set('pickerTarget', 'return')"
                            class="text-right p-3 rounded-xl border transition-colors {{ $pickerTarget === 'return' ? 'border-amber-400 bg-amber-50 dark:bg-amber-900/20' : 'border-zinc-200 dark:border-zinc-700' }}">
                            <div class="text-xs text-zinc-500">{{ __('العودة التلقائية (اختياري)') }}</div>
                            <div class="font-bold text-sm mt-0.5">{{ $returnDate ?: __('بدون عودة تلقائية') }}</div>
                        </button>
                    @endif
                </div>

                {{-- Hijri month grid: school days in green --}}
                <div class="border border-zinc-200 dark:border-zinc-700 rounded-xl p-3">
                    <div class="flex items-center justify-between mb-2">
                        <flux:button variant="ghost" size="xs" icon="chevron-right" wire:click="shiftMonth(-1)" />
                        <div class="text-sm font-bold text-zinc-700 dark:text-zinc-200">{{ $monthName }}</div>
                        <flux:button variant="ghost" size="xs" icon="chevron-left" wire:click="shiftMonth(1)" />
                    </div>

                    <div class="grid grid-cols-7 gap-1 text-center mb-1">
                        @foreach(['أحد', 'إثنين', 'ثلاثاء', 'أربعاء', 'خميس', 'جمعة', 'سبت'] as $day)
                            <div class="text-[0.55rem] font-bold text-zinc-400">{{ $day }}</div>
                        @endforeach
                    </div>

                    <div class="grid grid-cols-7 gap-1">
                        @foreach($monthDays as $day)
                            @if($day === null)
                                <div class="h-9"></div>
                            @else
                                @php
                                    $isSelectedEffective = $day['date'] === $effectiveDate;
                                    $isSelectedReturn = $day['date'] === $returnDate;
                                    $disabled = $pickerTarget === 'effective'
                                        ? $day['isFuture']
                                        : $day['date'] <= ($effectiveDate ?: $today);
                                @endphp
                                <button type="button" wire:key="pick-{{ $day['date'] }}"
                                    wire:click="selectDay('{{ $day['date'] }}')"
                                    @if($disabled) disabled @endif
                                    class="h-9 rounded-lg text-xs font-semibold border transition-colors
                                        {{ $isSelectedEffective ? 'bg-indigo-600 text-white border-indigo-600' :
                                           ($isSelectedReturn ? 'bg-amber-500 text-white border-amber-500' :
                                           ($day['isSchoolDay'] ? 'bg-green-50 dark:bg-green-900/20 border-green-200 dark:border-green-800 text-green-800 dark:text-green-300 hover:bg-green-100' : 'bg-white dark:bg-zinc-800 border-zinc-100 dark:border-zinc-700 text-zinc-600 dark:text-zinc-300 hover:bg-zinc-50')) }}
                                        {{ $day['isToday'] ? 'ring-2 ring-indigo-400 ring-offset-1 dark:ring-offset-zinc-900' : '' }}
                                        {{ $disabled ? 'opacity-30 cursor-not-allowed' : '' }}">
                                    {{ $day['hijriDay'] }}
                                </button>
                            @endif
                        @endforeach
                    </div>

                    <div class="flex items-center gap-3 mt-2 text-[0.6rem] text-zinc-500">
                        <span class="flex items-center gap-1"><span class="size-2.5 rounded bg-green-100 border border-green-300 inline-block"></span> {{ __('يوم دوام') }}</span>
                        <span class="flex items-center gap-1"><span class="size-2.5 rounded bg-indigo-600 inline-block"></span> {{ __('تاريخ السريان') }}</span>
                        @if($newStatus === 'suspended')
                            <span class="flex items-center gap-1"><span class="size-2.5 rounded bg-amber-500 inline-block"></span> {{ __('العودة التلقائية') }}</span>
                        @endif
                    </div>
                </div>

                @error('reason') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                @error('effectiveDate') <p class="text-xs text-red-600">{{ $message }}</p> @enderror

                {{-- Impact preview --}}
                @if($impact)
                    <div class="flex items-start gap-2 rounded-xl p-3 text-sm border
                        {{ $impact['excluding'] ? 'bg-amber-50 dark:bg-amber-900/20 border-amber-200 dark:border-amber-900/50 text-amber-700 dark:text-amber-400' : 'bg-emerald-50 dark:bg-emerald-900/20 border-emerald-200 dark:border-emerald-900/50 text-emerald-700 dark:text-emerald-400' }}">
                        <flux:icon icon="{{ $impact['excluding'] ? 'exclamation-triangle' : 'information-circle' }}" class="size-5 shrink-0" />
                        <span>
                            @if($impact['excluding'])
                                {{ __('هذا التغيير الرجعي سيستبعد من الحسابات') }}
                                @if($impact['attendance'] > 0) {{ $impact['attendance'] }} {{ __('سجل تحضير') }} @endif
                                @if($impact['attendance'] > 0 && $impact['tasmeeh'] > 0) {{ __('و') }} @endif
                                @if($impact['tasmeeh'] > 0) {{ $impact['tasmeeh'] }} {{ __('تسميع مقيّم') }} @endif
                                {{ __('في الفترة المتأثرة (تبقى السجلات محفوظة ولا تُحذف).') }}
                            @else
                                {{ __('هذا التفعيل الرجعي سيعيد احتساب') }}
                                @if($impact['attendance'] > 0) {{ $impact['attendance'] }} {{ __('سجل تحضير') }} @endif
                                @if($impact['attendance'] > 0 && $impact['tasmeeh'] > 0) {{ __('و') }} @endif
                                @if($impact['tasmeeh'] > 0) {{ $impact['tasmeeh'] }} {{ __('تسميع مقيّم') }} @endif
                                {{ __('في الفترة المعنية.') }}
                            @endif
                        </span>
                    </div>
                @endif

                <div class="flex gap-2">
                    <flux:spacer />
                    <flux:button wire:click="$set('showModal', false)">{{ __('إغلاق') }}</flux:button>
                    <flux:button wire:click="saveStatus" variant="primary" icon="check">
                        {{ __('حفظ التغيير') }}
                    </flux:button>
                </div>
            </div>
        </div>
    @endif
</flux:modal>
