<?php

use App\Models\Attendance;
use App\Models\Student;
use App\Services\StudentStatusService;
use App\Support\ArabicCount;
use App\Support\HijriDate;
use App\Support\StudentStatus;
use Flux\Flux;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/*
 * A student's standing, as a teacher, supervisor or manager reads and changes
 * it: where the student stands now, the few things that can be done about it
 * — each named for what it does, not picked from a list of statuses — and
 * what has gone before. A change says in one sentence what it will do before
 * it is made, the reports it reaches included. Correcting the past is kept
 * apart, behind its own link.
 */
new class extends Component
{
    public bool $showModal = false;

    #[Locked]
    public ?int $studentId = null;

    /** A teacher without the status permission reads the record and changes nothing. */
    #[Locked]
    public bool $readOnly = false;

    /** The status chosen to move to; empty while choosing. */
    public string $newStatus = '';

    public string $effectiveDate = '';

    /** Whether the start is being picked from the calendar rather than a quick choice. */
    public bool $pickingDate = false;

    /** Empty: no automatic return. */
    public string $returnDate = '';

    public bool $pickingReturn = false;

    public string $reason = '';

    /** Correcting the recorded history, row by row. */
    public bool $correcting = false;

    /** @var array{status: string, start_date: string, notes: string}|null */
    public ?array $editingHistory = null;

    #[Locked]
    public ?int $editingHistoryId = null;

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

    private function today(): string
    {
        return now('Asia/Riyadh')->format('Y-m-d');
    }

    #[On('open-status-manager')]
    public function open($studentId): void
    {
        $student = $this->scopedStudent((int) $studentId);

        if (! $student) {
            Flux::toast('الطالب خارج نطاق صلاحياتك', variant: 'danger');

            return;
        }

        $this->studentId = $student->id;
        $this->readOnly = $this->actingRole() === 'teacher'
            && empty(Auth::guard('teacher')->user()->effectivePermissions()['can_change_student_status']);
        $this->correcting = false;
        $this->cancelHistoryEdit();
        $this->back();
        $this->showModal = true;
    }

    /** Begin a change to the given status. */
    public function choose(string $status): void
    {
        if ($this->readOnly || ! array_key_exists($status, StudentStatus::LABELS)) {
            return;
        }

        $this->newStatus = $status;
        $this->effectiveDate = $this->today();
        $this->pickingDate = false;
        $this->returnDate = '';
        $this->pickingReturn = false;
        $this->reason = '';
        $this->resetValidation();
    }

    /** Back to choosing, nothing changed. */
    public function back(): void
    {
        $this->newStatus = '';
        $this->effectiveDate = $this->today();
        $this->pickingDate = false;
        $this->returnDate = '';
        $this->pickingReturn = false;
        $this->reason = '';
        $this->resetValidation();
    }

    public function updatedEffectiveDate(): void
    {
        // A return can only follow the start.
        if ($this->returnDate !== '' && $this->returnDate <= $this->effectiveDate) {
            $this->returnDate = '';
        }
    }

    public function saveStatus(): void
    {
        $student = $this->scopedStudent($this->studentId);

        if ($this->readOnly || ! $student) {
            return;
        }

        $this->validate([
            'newStatus' => 'required|in:active,registering,suspended,left',
            // Pinned to Riyadh, like the dates the form offers. A bare "today"
            // resolves against app.timezone (UTC), a day behind Riyadh from
            // 21:00 UTC onward.
            'effectiveDate' => 'required|date|before_or_equal:'.$this->today(),
            'returnDate' => 'nullable|date|after:effectiveDate',
            'reason' => 'nullable|string|max:500',
        ], [
            'newStatus.required' => 'اختر ما تريد فعله.',
            'effectiveDate.before_or_equal' => 'تاريخ البداية لا يكون في المستقبل.',
            'returnDate.after' => 'تاريخ العودة يأتي بعد تاريخ البداية.',
        ]);

        if (StudentStatusService::statusOn($student, $this->today()) === $this->newStatus) {
            $this->addError('newStatus', 'الطالب على هذه الحالة أصلاً.');

            return;
        }

        try {
            if ($this->newStatus === 'suspended' && $this->returnDate !== '') {
                StudentStatusService::suspendWithReturn($student, $this->effectiveDate, $this->returnDate, $this->reason ?: null);
            } else {
                StudentStatusService::changeStatus($student, $this->newStatus, $this->effectiveDate, $this->reason ?: null);
            }
        } catch (\InvalidArgumentException $e) {
            $this->addError('effectiveDate', $e->getMessage());

            return;
        }

        $this->showModal = false;
        $this->dispatch('student-list-updated');
        $this->dispatch('student-status-updated');
        Flux::toast('تم تحديث حالة الطالب.', variant: 'success');
    }

    public function editHistory(int $historyId): void
    {
        if ($this->readOnly) {
            return;
        }

        $entry = $this->scopedStudent($this->studentId)?->statusHistories()->whereKey($historyId)->first();

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
        if ($this->readOnly) {
            return;
        }

        $this->validate([
            'editingHistory.status' => 'required|in:active,registering,suspended,left',
            'editingHistory.start_date' => 'required|date|before_or_equal:'.$this->today(),
            'editingHistory.notes' => 'nullable|string|max:500',
        ], [
            'editingHistory.start_date.before_or_equal' => 'تاريخ البداية لا يكون في المستقبل.',
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
        Flux::toast('صُحّح سجل الحالة.', variant: 'success');
    }

    public function deleteHistoryEntry(int $historyId): void
    {
        $student = $this->scopedStudent($this->studentId);

        if ($this->readOnly || ! $student) {
            return;
        }

        StudentStatusService::deleteHistoryEntry($student, $historyId);
        $this->dispatch('student-list-updated');
        $this->dispatch('student-status-updated');
        Flux::toast('حُذف السجل.', variant: 'success');
    }

    /**
     * Attendance records between the chosen start and today that the change
     * moves into the reports or out of them: the reports count a record only
     * on a day the student was active (Attendance::activeStatusOnDateSql).
     *
     * @param  \Illuminate\Support\Collection<int, \App\Models\StudentStatusHistory>  $rowsAscending
     */
    private function reportShift(Student $student, $rowsAscending): int
    {
        if ($this->newStatus === '' || $this->effectiveDate === '' || $this->effectiveDate > $this->today()) {
            return 0;
        }

        $statusOn = function (string $day) use ($rowsAscending): string {
            $row = $rowsAscending->filter(fn ($row) => $row->start_date->format('Y-m-d') <= $day)->last();

            return $row?->status ?? 'active';
        };
        $countedAfter = $this->newStatus === 'active';

        return Attendance::where('student_id', $student->id)
            ->whereDate('date', '>=', $this->effectiveDate)
            ->whereDate('date', '<=', $this->today())
            ->pluck('date')
            ->filter(fn ($date) => ($statusOn($date->format('Y-m-d')) === 'active') !== $countedAfter)
            ->count();
    }

    public function with(): array
    {
        $student = $this->scopedStudent($this->studentId);

        if (! $student) {
            return ['student' => null];
        }

        $today = $this->today();
        $rows = $student->statusHistories()->reorder('start_date')->orderBy('id')->get();

        $periods = $rows->values()->map(function ($row, int $index) use ($rows, $today) {
            $from = $row->start_date->format('Y-m-d');
            $next = $rows->values()[$index + 1] ?? null;
            $to = $next?->start_date->format('Y-m-d');

            return [
                'row' => $row,
                'from' => $from,
                'to' => $to,
                'scheduled' => $from > $today,
                'current' => $from <= $today && ($to === null || $to > $today),
                'days' => $from > $today ? 0 : (int) Carbon::parse($from)->diffInDays(Carbon::parse(min($to ?? $today, $today))) + ($to === null || $to > $today ? 1 : 0),
            ];
        })->reverse()->values();

        $current = $periods->firstWhere('current', true);
        $currentStatus = $current['row']->status ?? $student->status ?: 'active';

        return [
            'student' => $student,
            'today' => $today,
            'yesterday' => Carbon::parse($today)->subDay()->format('Y-m-d'),
            'currentStatus' => $currentStatus,
            'currentSince' => $current['from'] ?? null,
            'scheduled' => $periods->firstWhere('scheduled', true),
            'periods' => $periods,
            // A new period cannot begin before the current one did.
            'earliest' => $current['from'] ?? null,
            'shift' => $this->reportShift($student, $rows),
        ];
    }
};
?>

@php
    $labels = StudentStatus::LABELS;
    // Spelled out for Tailwind.
    $dots = ['active' => 'bg-green-500', 'registering' => 'bg-blue-500', 'suspended' => 'bg-amber-500', 'left' => 'bg-red-500'];
    $panels = [
        'active' => 'bg-green-50 text-green-800 dark:bg-green-900/20 dark:text-green-300',
        'registering' => 'bg-blue-50 text-blue-800 dark:bg-blue-900/20 dark:text-blue-300',
        'suspended' => 'bg-amber-50 text-amber-800 dark:bg-amber-900/20 dark:text-amber-300',
        'left' => 'bg-red-50 text-red-800 dark:bg-red-900/20 dark:text-red-300',
    ];
    $roleLabels = ['manager' => 'المدير', 'supervisor' => 'المشرف', 'teacher' => 'المعلم'];
    $ar = fn ($n) => HijriDate::arabicDigits($n);
@endphp

<flux:modal wire:model="showModal" class="w-full md:w-[520px]" dir="rtl">
    @if ($student)
        @php
            $actions = [
                'active' => [
                    'title' => $currentStatus === 'registering' ? 'قبول وبدء المشاركة' : 'إعادة للمشاركة',
                    'hint' => 'يظهر في التحضير والتسميع ويُحتسب في التقارير',
                    'icon' => 'play-circle', 'tone' => 'text-green-600', 'confirm' => 'تأكيد المشاركة',
                ],
                'suspended' => [
                    'title' => 'إيقاف مؤقت',
                    'hint' => 'يغيب عن التحضير والتسميع حتى يعود',
                    'icon' => 'pause-circle', 'tone' => 'text-amber-600', 'confirm' => 'تأكيد الإيقاف',
                ],
                'left' => [
                    'title' => 'غادر الحلقات',
                    'hint' => 'يتوقف احتسابه، ويبقى في حلقته حتى يُزال منها',
                    'icon' => 'arrow-right-start-on-rectangle', 'tone' => 'text-red-600', 'confirm' => 'تأكيد المغادرة',
                ],
                'registering' => [
                    'title' => 'إرجاع إلى «تحت التسجيل»',
                    'hint' => 'لم يبدأ بعد؛ لا يظهر في التحضير',
                    'icon' => 'clipboard-document-list', 'tone' => 'text-blue-600', 'confirm' => 'تأكيد',
                ],
            ];
            $dayWord = fn (string $date) => match ($date) {
                $today => 'اليوم',
                $yesterday => 'أمس',
                default => HijriDate::full($date),
            };
            $returnIn = fn (int $days) => Carbon::parse($effectiveDate ?: $today)->addDays($days)->format('Y-m-d');
        @endphp

        <div class="space-y-5">
            {{-- ─────────── الآن ─────────── --}}
            <div>
                <flux:heading size="lg">{{ $student->name }}</flux:heading>
                <div class="mt-3 flex items-center gap-2 rounded-xl px-3 py-2.5 text-sm font-bold {{ $panels[$currentStatus] ?? '' }}" data-current-status="{{ $currentStatus }}">
                    <span class="size-2.5 shrink-0 rounded-full {{ $dots[$currentStatus] ?? 'bg-zinc-400' }}"></span>
                    {{ $labels[$currentStatus] ?? $currentStatus }}
                    @if ($currentSince)
                        <span class="font-normal">منذ {{ HijriDate::full($currentSince) }}</span>
                    @endif
                </div>
                @if ($scheduled)
                    <p class="mt-1.5 text-xs text-zinc-500 dark:text-zinc-400">
                        يعود {{ $labels[$scheduled['row']->status] ?? '' }} تلقائياً في {{ HijriDate::full($scheduled['from']) }}.
                    </p>
                @endif
            </div>

            @unless ($readOnly)
                @if ($newStatus === '')
                    {{-- ─────────── ماذا تريد؟ ─────────── --}}
                    <div>
                        <div class="text-sm font-bold text-zinc-600 dark:text-zinc-300 mb-2">ماذا تريد أن تفعل؟</div>
                        <div class="space-y-2">
                            @foreach ($actions as $status => $action)
                                @continue($status === $currentStatus)
                                <button type="button" wire:key="action-{{ $status }}" wire:click="choose('{{ $status }}')" data-action="{{ $status }}"
                                    class="w-full flex items-center gap-3 rounded-xl border border-zinc-200 dark:border-zinc-700 px-3 py-2.5 text-right hover:border-zinc-300 hover:bg-zinc-50 dark:hover:bg-zinc-800 transition-colors">
                                    <flux:icon :icon="$action['icon']" class="size-6 shrink-0 {{ $action['tone'] }}" />
                                    <span class="min-w-0">
                                        <span class="block text-sm font-bold text-zinc-800 dark:text-zinc-100">{{ $action['title'] }}</span>
                                        <span class="block text-xs text-zinc-500 dark:text-zinc-400">{{ $action['hint'] }}</span>
                                    </span>
                                    <flux:icon icon="chevron-left" class="size-4 shrink-0 text-zinc-300 ms-auto" />
                                </button>
                            @endforeach
                        </div>
                    </div>
                @else
                    {{-- ─────────── التفاصيل ─────────── --}}
                    @php
                        $action = $actions[$newStatus];
                        $when = $dayWord($effectiveDate ?: $today);
                        $summary = match ($newStatus) {
                            'active' => "يصبح مشاركاً من {$when}، ويظهر في التحضير والتسميع.",
                            'suspended' => "يُوقف من {$when}، ".($returnDate !== '' ? 'ويعود مشاركاً تلقائياً في '.HijriDate::full($returnDate).'.' : 'ويبقى موقوفاً حتى يُعاد.'),
                            'left' => "يُسجَّل مغادراً للحلقات من {$when}، ويغيب عن التحضير والتسميع.",
                            default => "يعود «تحت التسجيل» من {$when}، ويغيب عن التحضير والتسميع.",
                        };
                    @endphp
                    <div class="space-y-4 rounded-2xl border border-zinc-200 dark:border-zinc-700 p-4">
                        <div class="flex items-center gap-2">
                            <flux:button size="xs" variant="ghost" icon="arrow-right" wire:click="back" aria-label="رجوع" />
                            <flux:icon :icon="$action['icon']" class="size-5 {{ $action['tone'] }}" />
                            <span class="font-bold text-zinc-800 dark:text-zinc-100">{{ $action['title'] }}</span>
                        </div>

                        <div>
                            <div class="text-sm text-zinc-600 dark:text-zinc-300 mb-1.5">من متى؟</div>
                            <div class="flex flex-wrap gap-2">
                                @foreach (['today' => [$today, 'اليوم'], 'yesterday' => [$yesterday, 'أمس']] as $key => [$date, $word])
                                    @if (! $earliest || $date >= $earliest)
                                        <button type="button" wire:key="when-{{ $key }}" wire:click="$set('effectiveDate', '{{ $date }}'); $set('pickingDate', false)"
                                            class="rounded-full px-3 py-1 text-sm border {{ ! $pickingDate && $effectiveDate === $date ? 'border-zinc-800 bg-zinc-800 text-white dark:border-white dark:bg-white dark:text-zinc-900' : 'border-zinc-300 dark:border-zinc-600 text-zinc-700 dark:text-zinc-200' }}">
                                            {{ $word }}
                                        </button>
                                    @endif
                                @endforeach
                                <button type="button" wire:click="$set('pickingDate', true)"
                                    class="rounded-full px-3 py-1 text-sm border {{ $pickingDate || ! in_array($effectiveDate, [$today, $yesterday], true) ? 'border-zinc-800 bg-zinc-800 text-white dark:border-white dark:bg-white dark:text-zinc-900' : 'border-zinc-300 dark:border-zinc-600 text-zinc-700 dark:text-zinc-200' }}">
                                    تاريخ آخر
                                </button>
                            </div>
                            @if ($pickingDate)
                                <div class="mt-2">
                                    <livewire:shared.hijri-datepicker wire:model.live="effectiveDate" label="" :max-date="$today" :min-date="$earliest"
                                        :show-attendance-days="true" :key="'status-start-'.$student->id" />
                                </div>
                            @endif
                            @error('effectiveDate') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>

                        @if ($newStatus === 'suspended')
                            <div>
                                <div class="text-sm text-zinc-600 dark:text-zinc-300 mb-1.5">يعود متى؟</div>
                                <div class="flex flex-wrap gap-2">
                                    @foreach ([7 => 'بعد أسبوع', 14 => 'بعد أسبوعين', 30 => 'بعد شهر'] as $days => $word)
                                        <button type="button" wire:key="return-{{ $days }}" wire:click="$set('returnDate', '{{ $returnIn($days) }}'); $set('pickingReturn', false)"
                                            class="rounded-full px-3 py-1 text-sm border {{ ! $pickingReturn && $returnDate === $returnIn($days) ? 'border-zinc-800 bg-zinc-800 text-white dark:border-white dark:bg-white dark:text-zinc-900' : 'border-zinc-300 dark:border-zinc-600 text-zinc-700 dark:text-zinc-200' }}">
                                            {{ $word }}
                                        </button>
                                    @endforeach
                                    <button type="button" wire:click="$set('pickingReturn', true)"
                                        class="rounded-full px-3 py-1 text-sm border {{ $pickingReturn ? 'border-zinc-800 bg-zinc-800 text-white dark:border-white dark:bg-white dark:text-zinc-900' : 'border-zinc-300 dark:border-zinc-600 text-zinc-700 dark:text-zinc-200' }}">
                                        تاريخ آخر
                                    </button>
                                    <button type="button" wire:click="$set('returnDate', ''); $set('pickingReturn', false)"
                                        class="rounded-full px-3 py-1 text-sm border {{ ! $pickingReturn && $returnDate === '' ? 'border-zinc-800 bg-zinc-800 text-white dark:border-white dark:bg-white dark:text-zinc-900' : 'border-zinc-300 dark:border-zinc-600 text-zinc-700 dark:text-zinc-200' }}">
                                        لا أعرف
                                    </button>
                                </div>
                                @if ($pickingReturn)
                                    <div class="mt-2">
                                        <livewire:shared.hijri-datepicker wire:model.live="returnDate" label="" :min-date="Carbon::parse($effectiveDate ?: $today)->addDay()->format('Y-m-d')"
                                            :show-attendance-days="true" :key="'status-return-'.$student->id" />
                                    </div>
                                @endif
                                @error('returnDate') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                            </div>
                        @endif

                        <flux:input wire:model="reason" size="sm" label="السبب (اختياري)" placeholder="مثال: انقطع عن الحضور أسبوعين" />

                        {{-- What the change will do, said before it is done. --}}
                        <div class="rounded-xl px-3 py-2.5 text-sm leading-relaxed {{ $panels[$newStatus] ?? '' }}" data-status-summary>
                            {{ $summary }}
                            @if ($shift > 0)
                                <span class="block mt-1 font-bold" data-report-shift="{{ $shift }}">
                                    {{ $newStatus === 'active' ? 'ويدخل تقارير الحضور' : 'ويخرج من تقارير الحضور' }}
                                    ما سُجّل له في {{ $ar(ArabicCount::of($shift, ArabicCount::DAYS_GEN)) }} منذ {{ $when }}.
                                </span>
                            @endif
                        </div>
                        @error('newStatus') <p class="text-xs text-red-600">{{ $message }}</p> @enderror

                        <div class="flex justify-end gap-2">
                            <flux:button variant="ghost" wire:click="back">إلغاء</flux:button>
                            <flux:button variant="primary" wire:click="saveStatus">{{ $action['confirm'] }}</flux:button>
                        </div>
                    </div>
                @endif
            @else
                <p class="text-xs text-zinc-500">لا تملك صلاحية تغيير حالة الطلاب؛ هذا سجلّه للاطلاع.</p>
            @endunless

            {{-- ─────────── ما سبق ─────────── --}}
            <div>
                <div class="flex items-center justify-between gap-2 mb-2">
                    <div class="text-sm font-bold text-zinc-600 dark:text-zinc-300">ما سبق</div>
                    @if (! $readOnly && $periods->isNotEmpty())
                        <button type="button" wire:click="$toggle('correcting')" class="text-xs font-bold text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200 underline-offset-4 hover:underline">
                            {{ $correcting ? 'إنهاء التصحيح' : 'تصحيح السجل' }}
                        </button>
                    @endif
                </div>

                @if ($correcting)
                    <p class="mb-2 rounded-lg bg-amber-50 dark:bg-amber-900/20 px-3 py-2 text-xs text-amber-800 dark:text-amber-300">
                        التصحيح لما سُجّل خطأً، وهو يغيّر ماضي الطالب وتقارير حضوره. لتغيير حاله من الآن استعمل الأزرار أعلاه.
                    </p>
                @endif

                <div class="divide-y divide-zinc-100 dark:divide-zinc-800" data-status-history>
                    @forelse ($periods as $period)
                        @php
                            $row = $period['row'];
                        @endphp
                        @if ($editingHistoryId === $row->id)
                            {{-- The row itself becomes the form, so the record being corrected stays in view. --}}
                            <div wire:key="history-edit-{{ $row->id }}" class="py-3 space-y-3">
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                    <flux:select wire:model="editingHistory.status" size="sm" label="الحالة">
                                        @foreach ($labels as $value => $label)
                                            <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                                        @endforeach
                                    </flux:select>
                                    <livewire:shared.hijri-datepicker wire:model="editingHistory.start_date" :max-date="$today"
                                        :key="'edit-history-date-'.$row->id" label="بدأت في" />
                                </div>
                                <flux:input wire:model="editingHistory.notes" size="sm" label="ملاحظة (اختياري)" />
                                @error('editingHistory.start_date') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                                <div class="flex justify-end gap-2">
                                    <flux:button size="sm" variant="ghost" wire:click="cancelHistoryEdit">إلغاء</flux:button>
                                    <flux:button size="sm" variant="primary" wire:click="saveHistoryEdit">حفظ التصحيح</flux:button>
                                </div>
                            </div>
                        @else
                            <div wire:key="history-{{ $row->id }}" class="flex items-start gap-3 py-2.5 {{ $period['scheduled'] ? 'opacity-60' : '' }}">
                                <span class="mt-1.5 size-2.5 shrink-0 rounded-full {{ $dots[$row->status] ?? 'bg-zinc-400' }}"></span>
                                <div class="min-w-0 flex-1">
                                    <div class="text-sm">
                                        <span class="font-bold text-zinc-800 dark:text-zinc-100">{{ $labels[$row->status] ?? $row->status }}</span>
                                        <span class="text-zinc-500 dark:text-zinc-400">
                                            @if ($period['scheduled'])
                                                · مجدول في {{ HijriDate::full($period['from']) }}
                                            @elseif ($period['current'])
                                                · منذ {{ HijriDate::full($period['from']) }}
                                            @else
                                                · {{ HijriDate::dayMonth($period['from']) }} – {{ HijriDate::full($period['to']) }}
                                            @endif
                                        </span>
                                        @if ($period['days'] > 0)
                                            <span class="text-xs text-zinc-400">({{ $ar(ArabicCount::of($period['days'], ArabicCount::DAYS)) }})</span>
                                        @endif
                                    </div>
                                    @if ($row->notes)
                                        <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ $row->notes }}</div>
                                    @endif
                                    @if ($row->changed_by_name)
                                        <div class="text-[11px] text-zinc-400">{{ $roleLabels[$row->changed_by_role] ?? '' }} {{ $row->changed_by_name }}</div>
                                    @endif
                                </div>
                                @if ($correcting)
                                    <div class="flex shrink-0 items-center gap-1">
                                        @unless ($period['scheduled'])
                                            <flux:button size="xs" variant="ghost" icon="pencil-square" wire:click="editHistory({{ $row->id }})" aria-label="تصحيح" />
                                        @endunless
                                        <flux:button size="xs" variant="ghost" icon="trash" class="text-red-500" wire:click="deleteHistoryEntry({{ $row->id }})"
                                            wire:confirm="{{ $period['scheduled'] ? 'إلغاء هذا الموعد؟' : 'حذف هذا السجل؟ تمتد الحالة التي قبله مكانه.' }}"
                                            aria-label="{{ $period['scheduled'] ? 'إلغاء الموعد' : 'حذف' }}" />
                                    </div>
                                @endif
                            </div>
                        @endif
                    @empty
                        <p class="py-3 text-sm text-zinc-400">لا يوجد سجل حالات بعد.</p>
                    @endforelse
                </div>
            </div>
        </div>
    @endif
</flux:modal>
