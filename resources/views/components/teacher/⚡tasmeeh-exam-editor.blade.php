<?php

use App\Models\ExamLevel;
use App\Models\Student;
use App\Models\StudentExam;
use App\Services\ExamChangeService;
use App\Services\ExamSnapshot;
use App\Services\NotificationService;
use App\Services\TeacherSyncSnapshot;
use App\Support\HijriDate;
use App\Support\NextExamBadge;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The tasmeeh page's one exam editor, opened by the box at the end of a
 * student's name, in the list or in their card.
 *
 * Without a pending exam it sets one: the level the student would sit next
 * already chosen, as the teacher app chooses it, and a day a fortnight on.
 * With one it shows that exam, and lets its level, day and place change.
 * Results stay with the exams page, as they do for the phone.
 *
 * It is mounted once for the whole page and stays empty until opened, so the
 * list costs nothing more to draw however many students it holds. It writes
 * under the lock the teacher app's exam changes take, and by the same rules:
 * one pending exam per student, never on a day already gone.
 */
new class extends Component {
    #[Locked]
    public ?int $studentId = null;

    #[Locked]
    public string $studentName = '';

    /** The pending exam on show; null while a new one is being set. */
    #[Locked]
    public ?int $examId = null;

    /** The level the student would sit next, marked among the choices. */
    #[Locked]
    public ?int $suggestedLevelId = null;

    /**
     * The exam on show as the form was filled from it: level, day and place.
     * A change is written only while the exam still stands so, as the teacher
     * app's base value guards its own.
     *
     * @var array{0: int, 1: string, 2: string}|null
     */
    #[Locked]
    public ?array $base = null;

    public bool $showEditor = false;

    /** Whether the exam on show is open for changing. A new one always is. */
    public bool $changing = false;

    /** @var int|string|null */
    public $levelId = null;

    public ?string $date = null;

    public string $location = '';

    #[On('open-exam-editor')]
    public function open(int $studentId): void
    {
        $student = $this->allowedStudent($studentId);

        // Whatever the answer, the box that was tapped stops waiting.
        $this->dispatch('exam-editor-ready');

        if (! $student) {
            return;
        }

        $this->studentId = $student->id;
        $this->studentName = $student->name;
        $this->prepare(NextExamBadge::nextExams([$student->id])->get($student->id));
        $this->showEditor = true;
    }

    /**
     * Open the exam on show for changing, the form filled from it as it
     * stands now rather than as it stood when the editor opened.
     */
    public function startChanging(): void
    {
        if ($this->studentId === null) {
            return;
        }

        $this->reload();
        $this->changing = $this->examId !== null;
    }

    /**
     * Leave the form: a change goes back to the exam as it stands, a new
     * exam closes the editor.
     */
    public function cancel(): void
    {
        if ($this->examId !== null && $this->changing && $this->studentId !== null) {
            $this->reload();

            return;
        }

        $this->showEditor = false;
    }

    public function save(): void
    {
        $student = $this->studentId === null ? null : $this->allowedStudent($this->studentId);

        if (! $student) {
            $this->showEditor = false;

            return;
        }

        $exam = $this->examId === null ? null : StudentExam::where('student_id', $student->id)->find($this->examId);

        $this->validate($this->examRules($exam), [
            'levelId.required' => 'اختر مستوى الاختبار.',
            'levelId.integer' => 'اختر مستوى الاختبار.',
            'levelId.exists' => 'لم يعد هذا المستوى موجوداً، فاختر غيره.',
            'date.required' => 'اختر موعد الاختبار.',
            'date.date_format' => 'اختر موعد الاختبار من التقويم.',
            'date.after_or_equal' => 'مضى هذا اليوم، فاختر يوماً قادماً للاختبار.',
            'location.max' => 'المكان أطول من أن يُحفظ، فاختصره.',
        ]);

        try {
            $saved = Cache::lock(ExamChangeService::studentLockKey($student->id), 10)->block(5, fn () => DB::transaction(
                fn () => $this->examId === null ? $this->schedule($student) : $this->move($student),
            ));
        } catch (LockTimeoutException) {
            // The phone is writing this student's exam right now; nothing was saved.
            Flux::toast('لم يُحفظ الاختبار: يجري تعديل اختبار هذا الطالب من جهاز آخر، أعد المحاولة.', variant: 'danger');

            return;
        }

        if (! $saved) {
            return;
        }

        $this->showEditor = false;
        Flux::toast('تم حفظ الاختبار', variant: 'success');

        // The list redraws every box; only this student's card redraws its own.
        $this->dispatch('exam-saved', studentId: $student->id);
        $this->dispatch("exam-saved.{$student->id}");
    }

    /**
     * The levels in the order teachers climb them, read once a request.
     *
     * @return Collection<int, ExamLevel>
     */
    #[Computed]
    public function levels(): Collection
    {
        return ExamSnapshot::levels();
    }

    public function with(): array
    {
        // Closed and never opened: nothing to read, so the tasmeeh list draws
        // with no query of the editor's.
        if ($this->studentId === null) {
            return ['exam' => null, 'badge' => null, 'when' => '', 'quickDates' => [], 'today' => ''];
        }

        $today = TeacherSyncSnapshot::today();
        $exam = $this->examId === null ? null : StudentExam::with('examLevel.endAyah:id,juz_number')
            ->where('student_id', $this->studentId)
            ->find($this->examId);
        $date = $exam?->date_time->toDateString();

        return [
            'exam' => $exam,
            'badge' => $exam ? NextExamBadge::of($exam, CarbonImmutable::parse($today)) : null,
            'when' => $exam ? $this->relativeDays((int) CarbonImmutable::parse($today)->diffInDays($date, false)) : '',
            'quickDates' => $this->quickDates($today),
            'today' => $today,
        ];
    }

    /**
     * The student, if the teacher may set exams and the student is still an
     * active one of theirs; otherwise null, the teacher told why.
     */
    private function allowedStudent(int $studentId): ?Student
    {
        if (! NextExamBadge::canSchedule()) {
            Flux::toast('صفحة الاختبارات مغلقة للمعلمين الآن، فلا يُجدول اختبار من هنا.', variant: 'danger');

            return null;
        }

        $teacher = Auth::guard('teacher')->user();

        // The students the tasmeeh list itself shows.
        $student = Student::whereKey($studentId)
            ->whereIn('circle_id', $teacher->circles()->pluck('circles.id'))
            ->where('status', 'active')
            ->first();

        if (! $student) {
            Flux::toast('لم يعد هذا الطالب ضمن حلقاتك.', variant: 'danger');
        }

        return $student;
    }

    /**
     * Fill the editor from the student's pending exam, or for a new one when
     * there is none.
     */
    private function prepare(?StudentExam $exam): void
    {
        $this->resetValidation();
        $this->examId = $exam?->id;
        $this->changing = false;
        $this->suggestedLevelId = ExamSnapshot::suggestedLevelId($this->studentId, $this->levels);

        if ($exam) {
            $this->levelId = $exam->exam_level_id;
            $this->date = $exam->date_time->toDateString();
            $this->location = (string) $exam->location;
            $this->base = self::standing($exam);

            return;
        }

        $this->base = null;
        $this->levelId = $this->suggestedLevelId;
        $this->date = $this->quickDates(TeacherSyncSnapshot::today())['two_weeks']['date'];
        $this->location = '';
    }

    private function reload(): void
    {
        $this->prepare(NextExamBadge::nextExams([$this->studentId])->get($this->studentId));
    }

    /**
     * The day is checked against today on the academy's clock, never the bare
     * `today` rule, which reads UTC. A day kept as it was is not checked: an
     * exam that slipped past its day may still change level or place without
     * being moved, as the teacher app allows.
     *
     * @return array<string, array<int, string>>
     */
    private function examRules(?StudentExam $exam): array
    {
        $keepsDay = $exam !== null && $this->date === $exam->date_time->toDateString();

        return [
            'levelId' => ['required', 'integer', 'exists:exam_levels,id'],
            'date' => ['required', 'date_format:Y-m-d', ...($keepsDay ? [] : ['after_or_equal:'.TeacherSyncSnapshot::today()])],
            'location' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Write the new exam, unless one turned up since the editor opened.
     */
    private function schedule(Student $student): bool
    {
        // Another teacher of the circle, or this one on the phone, may have
        // set one meanwhile. A student waits on one exam at a time, so that
        // one is shown instead of a second written beside it.
        $pending = NextExamBadge::nextExams([$student->id])->get($student->id);

        if ($pending) {
            $this->prepare($pending);
            Flux::toast('سُجّل للطالب اختبار قادم في هذه الأثناء، فلم يُضف آخر. هذا هو، ويمكنك تغييره.', variant: 'warning');

            return false;
        }

        StudentExam::create([
            'student_id' => $student->id,
            'exam_level_id' => (int) $this->levelId,
            // The column defaults to passed, which an exam yet to be sat is not.
            'status' => 'pending',
            // A day without an hour, as the teacher app writes it.
            'date_time' => "{$this->date} 00:00:00",
            'location' => $this->locationOrNull(),
        ]);

        // Told once the exam is safely written, as the exams page tells them.
        DB::afterCommit(fn () => NotificationService::notify(
            'student',
            $student->id,
            'exam_scheduled',
            'اختبار جديد مجدول',
            'تم جدولة اختبار جديد لك، تفقّد التفاصيل',
            route('student.exams'),
        ));

        return true;
    }

    /**
     * Move the exam on show, if it is still waiting to be sat.
     */
    private function move(Student $student): bool
    {
        $exam = StudentExam::where('student_id', $student->id)->find($this->examId);

        if (! $exam || $exam->status !== 'pending') {
            $this->reload();
            Flux::toast('لم يعد هذا الاختبار في الانتظار: حُذف أو رُصدت نتيجته من صفحة الاختبارات.', variant: 'warning');

            return false;
        }

        // Changed from the phone or the exams page since this form was filled:
        // writing it now would undo that change unseen.
        if (self::standing($exam) !== $this->base) {
            $this->reload();
            $this->changing = true;
            Flux::toast('غُيّر هذا الاختبار من جهاز آخر في هذه الأثناء، فلم يُحفظ تغييرك. هذا هو الآن، فراجعه ثم احفظ.', variant: 'warning');

            return false;
        }

        $exam->update([
            'exam_level_id' => (int) $this->levelId,
            // The day moves; an hour the exams page may have set stays.
            'date_time' => $exam->date_time->copy()->setDateFrom($this->date),
            'location' => $this->locationOrNull(),
        ]);

        return true;
    }

    /**
     * An exam's level, day and place, as the form shows them.
     *
     * @return array{0: int, 1: string, 2: string}
     */
    private static function standing(StudentExam $exam): array
    {
        return [(int) $exam->exam_level_id, $exam->date_time->toDateString(), (string) $exam->location];
    }

    private function locationOrNull(): ?string
    {
        $location = trim($this->location);

        return $location === '' ? null : $location;
    }

    /**
     * The shortcuts the teacher app offers beside its calendar, on the same
     * days: the coming Sunday, a fortnight on, and a month on.
     *
     * @return array<string, array{label: string, date: string}>
     */
    private function quickDates(string $today): array
    {
        $today = CarbonImmutable::parse($today);

        return [
            'sunday' => ['label' => 'الأحد القادم', 'date' => $today->addDays(7 - $today->dayOfWeek)->toDateString()],
            'two_weeks' => ['label' => 'بعد أسبوعين', 'date' => $today->addDays(14)->toDateString()],
            'month' => ['label' => 'بعد شهر', 'date' => $today->addDays(30)->toDateString()],
        ];
    }

    /**
     * "اليوم"، "غداً"، "بعد ٥ أيام"، "مضى يومان" — worded as the teacher app words it.
     */
    private function relativeDays(int $days): string
    {
        $count = abs($days);

        return match (true) {
            $days === 0 => 'اليوم',
            $days === 1 => 'غداً',
            $days === 2 => 'بعد يومين',
            $days === -1 => 'مضى يوم',
            $days === -2 => 'مضى يومان',
            default => ($days > 0 ? 'بعد ' : 'مضى ').HijriDate::arabicDigits($count).' '.($count <= 10 ? 'أيام' : 'يوماً'),
        };
    }
};
?>

<div>
    {{-- Scrolling the page rather than the card, so the calendar may hang below the card. --}}
    <flux:modal wire:model.self="showEditor" scroll="body" class="w-full md:w-[30rem]">
        @if ($studentId !== null)
            <div class="space-y-5">
                <div>
                    <flux:heading size="lg">
                        {{ $examId === null ? __('تحديد اختبار') : __('الاختبار القادم') }} — {{ $studentName }}
                    </flux:heading>
                    @if ($examId === null)
                        <flux:subheading>{{ __('اختر المستوى والموعد، ويصل الطالبَ إشعارٌ بالاختبار.') }}</flux:subheading>
                    @endif
                </div>

                @if ($exam && $badge)
                    <div @class([
                        'flex items-center gap-4 rounded-xl p-4',
                        'bg-red-50 dark:bg-red-500/10' => $badge['overdue'],
                        'bg-indigo-50 dark:bg-indigo-500/10' => ! $badge['overdue'],
                    ])>
                        <div @class([
                            'size-14 shrink-0 rounded-xl flex flex-col items-center justify-center leading-none bg-white dark:bg-zinc-900',
                            'text-red-600 dark:text-red-400' => $badge['overdue'],
                            'text-indigo-700 dark:text-indigo-300' => ! $badge['overdue'],
                        ])>
                            @if ($badge['juz'] !== null)
                                <span class="text-2xl font-bold">{{ HijriDate::arabicDigits($badge['juz']) }}</span>
                                <span class="text-[11px] mt-1 opacity-80">{{ $badge['word'] }}</span>
                            @else
                                <flux:icon icon="academic-cap" class="size-6" />
                            @endif
                        </div>
                        <div class="min-w-0">
                            <flux:heading size="lg" class="truncate">{{ $badge['level'] ?: __('مستوى محذوف') }}</flux:heading>
                            <div class="text-sm text-zinc-700 dark:text-zinc-200 mt-1">{{ HijriDate::withWeekday($exam->date_time) }}</div>
                            <div @class([
                                'text-xs mt-0.5',
                                'text-red-600 dark:text-red-400 font-medium' => $badge['overdue'],
                                'text-zinc-500 dark:text-zinc-400' => ! $badge['overdue'],
                            ])>
                                {{ $when }}{{ $badge['overdue'] ? '، '.__('ولم تُرصد نتيجته بعد') : '' }}
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center gap-2 text-sm text-zinc-600 dark:text-zinc-300">
                        <flux:icon icon="map-pin" variant="mini" class="size-4 shrink-0 text-zinc-400" />
                        <span>{{ $exam->location ?: __('لم يُحدَّد المكان') }}</span>
                    </div>
                @endif

                @if ($examId === null || $changing)
                    <form wire:submit="save" class="space-y-4">
                        @if ($examId !== null)
                            <flux:separator text="{{ __('تغيير الاختبار') }}" />
                        @endif

                        <flux:select wire:model="levelId" label="{{ __('المستوى') }}" placeholder="{{ __('اختر المستوى') }}">
                            @foreach ($this->levels as $level)
                                <flux:select.option value="{{ $level->id }}">
                                    {{ $level->name }}{{ $level->id === $suggestedLevelId ? ' — '.__('المقترح') : '' }}
                                </flux:select.option>
                            @endforeach
                        </flux:select>

                        <flux:field>
                            <flux:label>{{ __('الموعد') }}</flux:label>

                            <div class="flex flex-wrap gap-2">
                                @foreach ($quickDates as $key => $quick)
                                    <button type="button" wire:click="$set('date', '{{ $quick['date'] }}')"
                                        data-quick-date="{{ $key }}"
                                        aria-pressed="{{ $date === $quick['date'] ? 'true' : 'false' }}"
                                        @class([
                                            'px-3 py-1.5 rounded-lg border text-xs font-bold transition-colors',
                                            'bg-indigo-50 border-indigo-400 text-indigo-700 dark:bg-indigo-500/15 dark:border-indigo-500 dark:text-indigo-300' => $date === $quick['date'],
                                            'bg-white border-zinc-200 text-zinc-700 hover:border-zinc-300 dark:bg-zinc-900 dark:border-zinc-700 dark:text-zinc-200 dark:hover:border-zinc-600' => $date !== $quick['date'],
                                        ])>
                                        {{ $quick['label'] }}
                                    </button>
                                @endforeach
                            </div>

                            {{-- Keyed by the day, so a shortcut above draws the picker again on it. --}}
                            <livewire:shared.hijri-datepicker
                                wire:model.live="date"
                                label=""
                                :min-date="$today"
                                :placeholder="__('اختر التاريخ')"
                                :wire:key="'exam-date-'.$studentId.'-'.$date"
                            />

                            <flux:error name="date" />
                        </flux:field>

                        <flux:input wire:model="location" label="{{ __('المكان') }}" placeholder="{{ __('اختياري، مثل: قاعة الجمعية') }}" />

                        <div class="flex justify-end gap-2">
                            <flux:button type="button" variant="ghost" wire:click="cancel">{{ __('إلغاء') }}</flux:button>
                            <flux:button type="submit" variant="primary">{{ $examId === null ? __('إضافة الاختبار') : __('حفظ التغيير') }}</flux:button>
                        </div>
                    </form>
                @else
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        {{-- Not in the accent colour, which this app sets to white. --}}
                        <flux:link :accent="false" href="{{ route('teacher.student-exams') }}" wire:navigate class="text-sm">
                            {{ __('صفحة الاختبارات') }}
                        </flux:link>

                        <div class="flex gap-2">
                            <flux:button variant="filled" icon="pencil-square" wire:click="startChanging">{{ __('تغيير الاختبار') }}</flux:button>
                            <flux:modal.close>
                                <flux:button variant="primary">{{ __('تم') }}</flux:button>
                            </flux:modal.close>
                        </div>
                    </div>
                @endif
            </div>
        @endif
    </flux:modal>
</div>
