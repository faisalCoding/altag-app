<?php

use App\Models\ExamLevel;
use App\Models\Student;
use App\Models\StudentExam;
use App\Services\ExamReadiness;
use App\Services\ExamSnapshot;
use App\Services\TeacherSyncSnapshot;
use App\Support\HijriDate;
use App\Support\NextExamBadge;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The tasmeeh page's one exam editor, opened by the box at the end of a
 * student's name, in the list or in their card.
 *
 * A teacher does not set the exam's day: they put the student forward for a
 * level, ready now or by a day they expect, and the manager seats the ready
 * ones in a dated batch. Without an open exam the editor puts the student
 * forward, the level the student would sit next already chosen. Put forward,
 * the nomination can change, be confirmed ready once the expected day comes,
 * or be taken back. Seated, the exam is shown as the manager set it.
 *
 * It is mounted once for the whole page and stays empty until opened, so the
 * list costs nothing more to draw however many students it holds.
 */
new class extends Component {
    #[Locked]
    public ?int $studentId = null;

    #[Locked]
    public string $studentName = '';

    /** The open exam on show; null while the student is being put forward. */
    #[Locked]
    public ?int $examId = null;

    /** The level the student would sit next, marked among the choices. */
    #[Locked]
    public ?int $suggestedLevelId = null;

    public bool $showEditor = false;

    /** Whether the nomination on show is open for changing. A new one always is. */
    public bool $changing = false;

    /** @var int|string|null */
    public $levelId = null;

    /** 'now' or 'expected'. */
    public string $readiness = 'now';

    public ?string $expectedOn = null;

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

    /** Open the nomination on show for changing, filled as it stands now. */
    public function startChanging(): void
    {
        if ($this->studentId === null) {
            return;
        }

        $this->reload();
        $this->changing = $this->openExam()?->status === StudentExam::NOMINATED;
    }

    /** Leave the form: a change goes back to the nomination, a new one closes the editor. */
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

        $this->validate([
            'levelId' => ['required', 'integer', 'exists:exam_levels,id'],
            'readiness' => ['required', 'in:now,expected'],
            // A day to come on the academy's clock, never the bare `tomorrow` rule, which reads UTC.
            'expectedOn' => ['required_if:readiness,expected', 'nullable', 'date_format:Y-m-d', 'after:'.TeacherSyncSnapshot::today()],
        ], [
            'levelId.required' => 'اختر مستوى الاختبار.',
            'levelId.integer' => 'اختر مستوى الاختبار.',
            'levelId.exists' => 'لم يعد هذا المستوى موجوداً، فاختر غيره.',
            'expectedOn.required_if' => 'اختر اليوم الذي تتوقع أن يكون فيه جاهزاً.',
            'expectedOn.date_format' => 'اختر اليوم من التقويم.',
            'expectedOn.after' => 'اختر يوماً قادماً، أو اختر «جاهز الآن».',
        ]);

        $expected = $this->readiness === 'expected' ? $this->expectedOn : null;
        // What the form was opened on decides: a new nomination never writes
        // over one made meanwhile from the phone or by another teacher.
        $exam = $this->examId === null ? null : StudentExam::where('student_id', $student->id)->find($this->examId);

        $result = match (true) {
            $this->examId === null => ExamReadiness::nominate($student, (int) $this->levelId, $expected, Auth::guard('teacher')->user()),
            $exam === null => 'حُذف هذا الترشيح من جهاز آخر في هذه الأثناء.',
            default => ExamReadiness::revise($exam, (int) $this->levelId, $expected),
        };

        if (is_string($result)) {
            $this->reload();
            Flux::toast($result, variant: 'warning');

            return;
        }

        $this->done($this->examId === null ? 'رُشّح الطالب للاختبار، وتحدد الإدارة موعده.' : 'تم حفظ الترشيح');
    }

    /** The day expected has come and the teacher says the student is ready. */
    public function confirmReady(): void
    {
        $exam = $this->openExam();

        if ($exam?->status === StudentExam::NOMINATED) {
            ExamReadiness::confirmReady($exam);
            $this->done('أُكّدت جاهزية الطالب، وتحدد الإدارة موعد اختباره.');
        }
    }

    public function withdraw(): void
    {
        $exam = $this->openExam();

        if ($exam && ExamReadiness::withdraw($exam)) {
            $this->done('أُلغي ترشيح الطالب');

            return;
        }

        $this->reload();
        Flux::toast('جُدول اختبار الطالب في دفعة، فلم يعد الترشيح قابلاً للإلغاء.', variant: 'warning');
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
            return ['exam' => null, 'badge' => null, 'when' => '', 'today' => ''];
        }

        $today = TeacherSyncSnapshot::today();
        $exam = $this->examId === null ? null : StudentExam::with('examLevel.endAyah:id,juz_number')
            ->where('student_id', $this->studentId)
            ->find($this->examId);
        $scheduledOn = $exam?->status === StudentExam::SCHEDULED ? $exam->date_time->toDateString() : null;

        return [
            'exam' => $exam,
            'badge' => $exam ? NextExamBadge::of($exam, CarbonImmutable::parse($today)) : null,
            'when' => $scheduledOn ? $this->relativeDays((int) CarbonImmutable::parse($today)->diffInDays($scheduledOn, false)) : '',
            'today' => $today,
        ];
    }

    /**
     * The student, if the teacher may put students forward and the student
     * is still an active one of theirs; otherwise null, the teacher told why.
     */
    private function allowedStudent(int $studentId): ?Student
    {
        if (! NextExamBadge::canSchedule()) {
            Flux::toast('صفحة الاختبارات مغلقة للمعلمين الآن، فلا يُرشَّح طالب من هنا.', variant: 'danger');

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

    /** The student's open exam as it stands now. */
    private function openExam(): ?StudentExam
    {
        return $this->studentId === null ? null : NextExamBadge::nextExams([$this->studentId])->get($this->studentId);
    }

    /** Fill the editor from the student's open exam, or for a new nomination. */
    private function prepare(?StudentExam $exam): void
    {
        $this->resetValidation();
        $this->examId = $exam?->id;
        $this->changing = false;
        $this->suggestedLevelId = ExamSnapshot::suggestedLevelId($this->studentId, $this->levels);
        $this->levelId = $exam?->exam_level_id ?? $this->suggestedLevelId;
        $expected = $exam?->status === StudentExam::NOMINATED && $exam->ready_at === null ? $exam->expected_ready_on?->toDateString() : null;
        $this->readiness = $expected ? 'expected' : 'now';
        $this->expectedOn = $expected;
    }

    private function reload(): void
    {
        $this->prepare($this->openExam());
    }

    /** Close the editor on a saved change, the boxes drawn again. */
    private function done(string $message): void
    {
        $this->showEditor = false;
        Flux::toast($message, variant: 'success');

        // The list redraws every box; only this student's card redraws its own.
        $this->dispatch('exam-saved', studentId: $this->studentId);
        $this->dispatch("exam-saved.{$this->studentId}");
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
            @php
                $state = $badge['state'] ?? null;
                $scheduled = $state === 'scheduled';
            @endphp
            <div class="space-y-5">
                <div>
                    <flux:heading size="lg">
                        {{ $examId === null ? __('ترشيح للاختبار') : ($scheduled ? __('الاختبار القادم') : __('ترشيح للاختبار')) }} — {{ $studentName }}
                    </flux:heading>
                    @if ($examId === null)
                        <flux:subheading>{{ __('اختر المستوى ومتى يكون الطالب جاهزاً، وتحدد الإدارة موعد الاختبار.') }}</flux:subheading>
                    @endif
                </div>

                @if ($exam && $badge)
                    <div @class([
                        'flex items-center gap-4 rounded-xl p-4',
                        'bg-red-50 dark:bg-red-500/10' => $badge['overdue'],
                        'bg-amber-50 dark:bg-amber-500/10' => $state === 'due',
                        'bg-indigo-50 dark:bg-indigo-500/10' => ! $badge['overdue'] && $state !== 'due',
                    ])>
                        <div @class([
                            'size-14 shrink-0 rounded-xl flex flex-col items-center justify-center leading-none bg-white dark:bg-zinc-900',
                            'border-2 border-dashed' => ! $scheduled,
                            'border-indigo-300 dark:border-indigo-500/50' => in_array($state, ['ready', 'expected'], true),
                            'border-amber-400' => $state === 'due',
                            'text-red-600 dark:text-red-400' => $badge['overdue'],
                            'text-amber-700 dark:text-amber-300' => $state === 'due',
                            'text-indigo-700 dark:text-indigo-300' => ! $badge['overdue'] && $state !== 'due',
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
                            @if ($scheduled)
                                <div class="text-sm text-zinc-700 dark:text-zinc-200 mt-1" data-exam-day>{{ HijriDate::withWeekday($exam->date_time->toDateString()) }}</div>
                                <div @class([
                                    'text-xs mt-0.5',
                                    'text-red-600 dark:text-red-400 font-medium' => $badge['overdue'],
                                    'text-zinc-500 dark:text-zinc-400' => ! $badge['overdue'],
                                ])>
                                    {{ $when }}{{ $badge['overdue'] ? '، '.__('ولم تُرصد نتيجته بعد') : '' }}
                                </div>
                            @elseif ($state === 'ready')
                                <div class="text-sm text-zinc-700 dark:text-zinc-200 mt-1">{{ __('جاهز، بانتظار موعد من الإدارة') }}</div>
                            @elseif ($state === 'expected')
                                <div class="text-sm text-zinc-700 dark:text-zinc-200 mt-1">{{ __('جاهزيته متوقعة') }} {{ $badge['date_hijri'] }}</div>
                            @else
                                <div class="text-sm font-medium text-amber-800 dark:text-amber-200 mt-1">{{ __('حان اليوم المتوقع لجاهزيته') }}</div>
                                <div class="text-xs text-amber-700/80 dark:text-amber-300/80 mt-0.5">{{ __('أكّد جاهزيته ليُدرج في الدفعات، أو حدّد يوماً آخر.') }}</div>
                            @endif
                        </div>
                    </div>

                    @if ($scheduled)
                        <div class="flex items-center gap-2 text-sm text-zinc-600 dark:text-zinc-300">
                            <flux:icon icon="map-pin" variant="mini" class="size-4 shrink-0 text-zinc-400" />
                            <span>{{ $exam->location ?: __('لم يُحدَّد المكان') }}</span>
                        </div>
                    @endif
                @endif

                @if ($examId === null || $changing)
                    <form wire:submit="save" class="space-y-4">
                        @if ($examId !== null)
                            <flux:separator text="{{ __('تعديل الترشيح') }}" />
                        @endif

                        <flux:select wire:model="levelId" label="{{ __('المستوى') }}" placeholder="{{ __('اختر المستوى') }}">
                            @foreach ($this->levels as $level)
                                <flux:select.option value="{{ $level->id }}">
                                    {{ $level->name }}{{ $level->id === $suggestedLevelId ? ' — '.__('المقترح') : '' }}
                                </flux:select.option>
                            @endforeach
                        </flux:select>

                        <flux:radio.group wire:model.live="readiness" label="{{ __('الجاهزية') }}" variant="segmented">
                            <flux:radio value="now" label="{{ __('جاهز الآن') }}" />
                            <flux:radio value="expected" label="{{ __('في يوم متوقع') }}" />
                        </flux:radio.group>

                        @if ($readiness === 'expected')
                            <flux:field>
                                <flux:label>{{ __('اليوم المتوقع لجاهزيته') }}</flux:label>
                                <livewire:shared.hijri-datepicker
                                    wire:model="expectedOn"
                                    label=""
                                    :min-date="$today"
                                    :placeholder="__('اختر التاريخ')"
                                    :wire:key="'expected-'.$studentId"
                                />
                                <flux:description>{{ __('تؤكد جاهزيته حين يحين ذلك اليوم، ويصلك تذكير فيه.') }}</flux:description>
                                <flux:error name="expectedOn" />
                            </flux:field>
                        @endif

                        <div class="flex justify-end gap-2">
                            <flux:button type="button" variant="ghost" wire:click="cancel">{{ __('إلغاء') }}</flux:button>
                            <flux:button type="submit" variant="primary">{{ $examId === null ? __('ترشيح') : __('حفظ التعديل') }}</flux:button>
                        </div>
                    </form>
                @elseif ($scheduled)
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <span class="text-xs text-zinc-500">{{ __('حدّدت الإدارة موعد هذا الاختبار.') }}</span>
                        <flux:modal.close>
                            <flux:button variant="primary">{{ __('تم') }}</flux:button>
                        </flux:modal.close>
                    </div>
                @else
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <flux:button variant="ghost" size="sm" icon="trash" class="text-rose-600" wire:click="withdraw"
                            wire:confirm="{{ __('يُلغى ترشيح الطالب للاختبار. متابعة؟') }}">{{ __('إلغاء الترشيح') }}</flux:button>
                        <div class="flex gap-2">
                            <flux:button variant="filled" icon="pencil-square" wire:click="startChanging">{{ __('تعديل') }}</flux:button>
                            @if ($state !== 'ready')
                                <flux:button variant="primary" icon="check" wire:click="confirmReady">{{ __('تأكيد الجاهزية') }}</flux:button>
                            @else
                                <flux:modal.close>
                                    <flux:button variant="primary">{{ __('تم') }}</flux:button>
                                </flux:modal.close>
                            @endif
                        </div>
                    </div>
                @endif
            </div>
        @endif
    </flux:modal>
</div>
