<?php

use App\Models\ExamLevel;
use App\Models\Student;
use App\Models\StudentExam;
use App\Services\ExamReadiness;
use App\Services\ExamSnapshot;
use App\Services\TeacherSyncSnapshot;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The teacher's exams page: putting students forward for the association's
 * exam, and the results of those the manager seated.
 *
 * The teacher never sets the day. They mark a student ready now, or name the
 * day they expect them to be and confirm it when it comes; the manager seats
 * the ready ones in dated batches. The results of a seated exam are recorded
 * here as on the manager's batch page.
 */
new class extends Component {
    use WithPagination;

    public $search = '';

    /** The nomination form: a new one, or the one being changed. */
    public bool $showNomination = false;

    #[Locked]
    public ?int $nominationId = null;

    public $studentId = null;

    public $examLevelId = null;

    /** 'now' or 'expected'. */
    public string $readiness = 'now';

    public ?string $expectedOn = null;

    /** The result form of a seated or finished exam. */
    public bool $showResult = false;

    #[Locked]
    public ?int $resultId = null;

    public string $status = 'passed';

    public $scorePercentage = null;

    public $notes = '';

    public function nominate(): void
    {
        $this->resetValidation();
        $this->reset(['nominationId', 'studentId', 'examLevelId', 'expectedOn']);
        $this->readiness = 'now';
        $this->showNomination = true;
    }

    public function updatedStudentId(): void
    {
        // The level the student would sit next, as the tasmeeh box chooses it.
        if ($this->nominationId === null && $this->studentId) {
            $this->examLevelId = ExamSnapshot::suggestedLevelId((int) $this->studentId, ExamSnapshot::levels());
        }
    }

    public function editNomination(int $id): void
    {
        $exam = $this->ownExams()->nominated()->findOrFail($id);

        $this->resetValidation();
        $this->nominationId = $exam->id;
        $this->studentId = $exam->student_id;
        $this->examLevelId = $exam->exam_level_id;
        $expected = $exam->ready_at === null ? $exam->expected_ready_on?->toDateString() : null;
        $this->readiness = $expected ? 'expected' : 'now';
        $this->expectedOn = $expected;
        $this->showNomination = true;
    }

    public function saveNomination(): void
    {
        $this->validate([
            'studentId' => ['required', 'integer'],
            'examLevelId' => ['required', 'integer', 'exists:exam_levels,id'],
            'readiness' => ['required', 'in:now,expected'],
            // A day to come on the academy's clock, never the bare `tomorrow` rule, which reads UTC.
            'expectedOn' => ['required_if:readiness,expected', 'nullable', 'date_format:Y-m-d', 'after:'.TeacherSyncSnapshot::today()],
        ], [
            'studentId.required' => 'اختر الطالب.',
            'examLevelId.required' => 'اختر مستوى الاختبار.',
            'examLevelId.exists' => 'لم يعد هذا المستوى موجوداً، فاختر غيره.',
            'expectedOn.required_if' => 'اختر اليوم الذي تتوقع أن يكون فيه جاهزاً.',
            'expectedOn.after' => 'اختر يوماً قادماً، أو اختر «جاهز الآن».',
        ]);

        $expected = $this->readiness === 'expected' ? $this->expectedOn : null;

        if ($this->nominationId === null) {
            $student = Student::whereIn('circle_id', $this->ownCircleIds())->findOrFail($this->studentId);
            $result = ExamReadiness::nominate($student, (int) $this->examLevelId, $expected, Auth::guard('teacher')->user());
        } else {
            $result = ExamReadiness::revise($this->ownExams()->findOrFail($this->nominationId), (int) $this->examLevelId, $expected);
        }

        if (is_string($result)) {
            $this->addError('studentId', $result);

            return;
        }

        $this->showNomination = false;
        Flux::toast($this->nominationId === null ? 'رُشّح الطالب للاختبار، وتحدد الإدارة موعده.' : 'تم حفظ الترشيح', variant: 'success');
    }

    public function confirmReady(int $id): void
    {
        ExamReadiness::confirmReady($this->ownExams()->nominated()->findOrFail($id));
        Flux::toast('أُكّدت جاهزية الطالب، وتحدد الإدارة موعد اختباره.', variant: 'success');
    }

    public function withdraw(int $id): void
    {
        if (ExamReadiness::withdraw($this->ownExams()->findOrFail($id))) {
            Flux::toast('أُلغي ترشيح الطالب', variant: 'success');
        }
    }

    public function editResult(int $id): void
    {
        $exam = $this->visibleExams()->whereNotIn('status', [StudentExam::NOMINATED])->findOrFail($id);

        $this->resetValidation();
        $this->resultId = $exam->id;
        $this->status = in_array($exam->status, StudentExam::RESULTS, true) ? $exam->status : 'passed';
        $this->scorePercentage = $exam->score_percentage;
        $this->notes = (string) $exam->notes;
        $this->showResult = true;
    }

    public function saveResult(): void
    {
        $this->validate([
            'status' => ['required', 'in:'.implode(',', StudentExam::RESULTS)],
            'scorePercentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $exam = $this->visibleExams()->whereNotIn('status', [StudentExam::NOMINATED])->findOrFail($this->resultId);

        ExamReadiness::recordResult($exam, $this->status, $this->scorePercentage);
        $exam->update(['notes' => filled($this->notes) ? $this->notes : null]);

        $this->showResult = false;
        Flux::toast('تم رصد النتيجة', variant: 'success');
    }

    /** @return \Illuminate\Support\Collection<int, ExamLevel> */
    #[Computed]
    public function levels()
    {
        return ExamSnapshot::levels();
    }

    /** The students the teacher may put forward: those of their own circles. */
    #[Computed]
    public function students()
    {
        return Student::whereIn('circle_id', $this->ownCircleIds())->orderBy('name')->get(['id', 'name']);
    }

    /** @return array<int, int> */
    private function ownCircleIds(): array
    {
        return Auth::guard('teacher')->user()->circles()->pluck('circles.id')->all();
    }

    /** The exams of the teacher's own circles. */
    private function ownExams()
    {
        return StudentExam::whereHas('student', fn ($q) => $q->whereIn('circle_id', $this->ownCircleIds()));
    }

    /**
     * The exams of the teacher's own circles, and today's seated exams of a
     * circle they stand in for, whose results they may record.
     */
    private function visibleExams()
    {
        $teacher = Auth::guard('teacher')->user();
        $own = $this->ownCircleIds();
        $standingIn = $teacher->substituteCircleIds();

        return StudentExam::query()->where(fn ($q) => $q
            ->whereHas('student', fn ($q) => $q->whereIn('circle_id', $own))
            ->orWhere(fn ($q) => $q
                ->whereHas('student', fn ($q) => $q->whereIn('circle_id', $standingIn))
                ->whereDate('date_time', TeacherSyncSnapshot::today())));
    }

    public function with()
    {
        $today = TeacherSyncSnapshot::today();

        return [
            // Expected ready by now, and waiting on the teacher to say so.
            'due' => $this->ownExams()->nominated()->whereNull('ready_at')
                ->whereDate('expected_ready_on', '<=', $today)
                ->with('student:id,name', 'examLevel:id,name')->orderBy('expected_ready_on')->get(),
            // Open exams first — put forward, then seated — then the finished, newest first.
            'exams' => $this->visibleExams()
                ->with(['student:id,name', 'examLevel:id,name'])
                ->whereHas('student', fn ($q) => $q->where('name', 'like', '%'.$this->search.'%'))
                ->orderByRaw("case status when 'nominated' then 0 when 'pending' then 1 else 2 end")
                ->orderByDesc('date_time')
                ->orderByDesc('id')
                ->paginate(15),
            'today' => $today,
        ];
    }
};
?>

@php
    use App\Support\HijriDate;
@endphp

<div class="space-y-6">
    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
        <div>
            <flux:heading size="xl">{{ __('اختبارات الطلاب') }}</flux:heading>
            <flux:subheading>{{ __('رشّح طلابك للاختبار، وتحدد الإدارة موعده في دفعة.') }}</flux:subheading>
        </div>
        <flux:button variant="primary" wire:click="nominate" icon="plus">{{ __('ترشيح للاختبار') }}</flux:button>
    </div>

    @if($due->isNotEmpty())
        <div class="rounded-2xl border border-amber-200 dark:border-amber-900/50 bg-amber-50 dark:bg-amber-900/20 p-4 space-y-3" data-readiness-due>
            <div class="flex items-center gap-2 text-amber-900 dark:text-amber-200">
                <flux:icon icon="bell-alert" class="size-5" />
                <span class="font-bold">{{ __('حان اليوم المتوقع لجاهزية هؤلاء: أكّد جاهزيتهم أو حدّد يوماً آخر') }}</span>
            </div>
            <div class="space-y-2">
                @foreach($due as $exam)
                    <div wire:key="due-{{ $exam->id }}" class="flex flex-wrap items-center gap-3 rounded-xl bg-white dark:bg-zinc-900 border border-amber-100 dark:border-amber-900/40 p-3">
                        <div class="flex-1 min-w-[10rem]">
                            <div class="font-bold text-zinc-900 dark:text-white">{{ $exam->student?->name }}</div>
                            <div class="text-xs text-zinc-500">{{ $exam->examLevel?->name }} · {{ __('متوقع') }} {{ HijriDate::full($exam->expected_ready_on) }}</div>
                        </div>
                        <flux:button size="sm" variant="ghost" wire:click="editNomination({{ $exam->id }})">{{ __('يوم آخر') }}</flux:button>
                        <flux:button size="sm" variant="primary" icon="check" wire:click="confirmReady({{ $exam->id }})">{{ __('جاهز') }}</flux:button>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    <flux:card>
        <div class="mb-4 w-full sm:w-1/3">
            <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" placeholder="{{ __('بحث باسم الطالب...') }}" />
        </div>

        <div class="overflow-x-auto">
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('الطالب') }}</flux:table.column>
                    <flux:table.column>{{ __('المستوى') }}</flux:table.column>
                    <flux:table.column>{{ __('الحالة') }}</flux:table.column>
                    <flux:table.column>{{ __('الموعد') }}</flux:table.column>
                    <flux:table.column>{{ __('الدرجة') }}</flux:table.column>
                    <flux:table.column>{{ __('المكان') }}</flux:table.column>
                    <flux:table.column>{{ __('الإجراءات') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @forelse ($exams as $exam)
                        <flux:table.row wire:key="exam-{{ $exam->id }}">
                            <flux:table.cell class="font-semibold">{{ $exam->student->name ?? '-' }}</flux:table.cell>
                            <flux:table.cell>
                                <flux:badge color="indigo">{{ $exam->examLevel->name ?? '-' }}</flux:badge>
                            </flux:table.cell>
                            <flux:table.cell>
                                @if($exam->status === StudentExam::NOMINATED)
                                    @if($exam->ready_at)
                                        <flux:badge color="sky">{{ __('مرشح، جاهز') }}</flux:badge>
                                    @elseif($exam->expected_ready_on && $exam->expected_ready_on->toDateString() <= $today)
                                        <flux:badge color="amber">{{ __('حان موعد جاهزيته') }}</flux:badge>
                                    @else
                                        <flux:badge color="zinc">{{ __('مرشح، جاهزيته متوقعة') }}</flux:badge>
                                    @endif
                                @elseif($exam->status === 'passed')
                                    <flux:badge color="emerald">{{ StudentExam::STATUS_LABELS['passed'] }}</flux:badge>
                                @elseif($exam->status === 'failed')
                                    <flux:badge color="red">{{ StudentExam::STATUS_LABELS['failed'] }}</flux:badge>
                                @elseif($exam->status === 'absent')
                                    <flux:badge color="amber">{{ StudentExam::STATUS_LABELS['absent'] }}</flux:badge>
                                @elseif($exam->status === 'pending')
                                    <flux:badge color="blue">{{ StudentExam::STATUS_LABELS['pending'] }}</flux:badge>
                                @else
                                    <flux:badge color="zinc">{{ StudentExam::STATUS_LABELS['cancelled'] }}</flux:badge>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>
                                @if($exam->date_time)
                                    <div class="text-sm"><x-hijri-date :date="$exam->date_time->toDateString()" /></div>
                                    <div class="text-xs text-zinc-500">{{ $exam->date_time->format('h:i A') }}</div>
                                @elseif($exam->status === StudentExam::NOMINATED && ! $exam->ready_at && $exam->expected_ready_on)
                                    <div class="text-xs text-zinc-500">{{ __('متوقع') }} {{ HijriDate::full($exam->expected_ready_on) }}</div>
                                @else
                                    <span class="text-xs text-zinc-400">{{ __('تحدده الإدارة') }}</span>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>
                                @if($exam->score_percentage !== null)
                                    <span class="font-bold {{ $exam->score_percentage >= 90 ? 'text-green-600' : ($exam->score_percentage >= 70 ? 'text-amber-500' : 'text-red-500') }}">
                                        {{ $exam->score_percentage }}%
                                    </span>
                                @else
                                    <span class="text-zinc-400 text-sm">{{ __('لم ترصد') }}</span>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>{{ $exam->location ?? '-' }}</flux:table.cell>
                            <flux:table.cell>
                                <flux:dropdown>
                                    <flux:button variant="ghost" size="sm" icon="ellipsis-vertical" inset="top bottom" />
                                    <flux:menu>
                                        @if($exam->status === StudentExam::NOMINATED)
                                            @if(! $exam->ready_at)
                                                <flux:menu.item wire:click="confirmReady({{ $exam->id }})" icon="check">{{ __('تأكيد الجاهزية') }}</flux:menu.item>
                                            @endif
                                            <flux:menu.item wire:click="editNomination({{ $exam->id }})" icon="pencil-square">{{ __('تعديل الترشيح') }}</flux:menu.item>
                                            <flux:menu.item wire:click="withdraw({{ $exam->id }})" wire:confirm="{{ __('يُلغى ترشيح الطالب للاختبار. متابعة؟') }}" icon="trash" variant="danger">{{ __('إلغاء الترشيح') }}</flux:menu.item>
                                        @else
                                            <flux:menu.item wire:click="editResult({{ $exam->id }})" icon="clipboard-document-check">
                                                {{ $exam->status === 'pending' ? __('رصد النتيجة') : __('تعديل النتيجة') }}
                                            </flux:menu.item>
                                        @endif
                                    </flux:menu>
                                </flux:dropdown>
                            </flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="7" class="text-center text-zinc-500 py-8">{{ __('لا توجد اختبارات مسجلة.') }}</flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </div>

        <div class="mt-4">{{ $exams->links() }}</div>
    </flux:card>

    <flux:modal wire:model="showNomination" class="md:w-[30rem]">
        <form wire:submit.prevent="saveNomination" class="space-y-5">
            <div>
                <flux:heading size="lg">{{ $nominationId ? __('تعديل الترشيح') : __('ترشيح للاختبار') }}</flux:heading>
                <flux:subheading>{{ __('اختر المستوى ومتى يكون الطالب جاهزاً، وتحدد الإدارة موعد الاختبار.') }}</flux:subheading>
            </div>

            @if(! $nominationId)
                <flux:select wire:model.live="studentId" label="{{ __('الطالب') }}" placeholder="{{ __('اختر الطالب') }}" searchable>
                    @foreach($this->students as $student)
                        <flux:select.option value="{{ $student->id }}">{{ $student->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            @endif
            <flux:error name="studentId" />

            <flux:select wire:model="examLevelId" label="{{ __('المستوى') }}" placeholder="{{ __('اختر المستوى') }}">
                @foreach($this->levels as $level)
                    <flux:select.option value="{{ $level->id }}">{{ $level->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:radio.group wire:model.live="readiness" label="{{ __('الجاهزية') }}" variant="segmented">
                <flux:radio value="now" label="{{ __('جاهز الآن') }}" />
                <flux:radio value="expected" label="{{ __('في يوم متوقع') }}" />
            </flux:radio.group>

            @if($readiness === 'expected')
                <flux:field>
                    <flux:label>{{ __('اليوم المتوقع لجاهزيته') }}</flux:label>
                    <livewire:shared.hijri-datepicker wire:model="expectedOn" label="" :min-date="$today" :placeholder="__('اختر التاريخ')" :wire:key="'expected-'.($nominationId ?? 'new')" />
                    <flux:description>{{ __('لا يُدرج في الدفعات حتى تؤكد جاهزيته، ويصلك تذكير في ذلك اليوم.') }}</flux:description>
                    <flux:error name="expectedOn" />
                </flux:field>
            @endif

            <div class="flex justify-end gap-2">
                <flux:button variant="ghost" wire:click="$set('showNomination', false)">{{ __('إلغاء') }}</flux:button>
                <flux:button type="submit" variant="primary">{{ $nominationId ? __('حفظ') : __('ترشيح') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal wire:model="showResult" class="md:w-[32rem]">
        <form wire:submit.prevent="saveResult" class="space-y-5">
            <flux:heading size="lg">{{ __('نتيجة الاختبار') }}</flux:heading>

            <flux:radio.group wire:model="status" label="{{ __('النتيجة') }}" variant="cards" class="grid grid-cols-2 gap-2">
                <flux:radio value="passed" label="{{ StudentExam::STATUS_LABELS['passed'] }}" icon="check-circle" />
                <flux:radio value="failed" label="{{ StudentExam::STATUS_LABELS['failed'] }}" icon="x-circle" />
                <flux:radio value="absent" label="{{ StudentExam::STATUS_LABELS['absent'] }}" icon="calendar" />
                <flux:radio value="cancelled" label="{{ StudentExam::STATUS_LABELS['cancelled'] }}" icon="no-symbol" />
            </flux:radio.group>

            <flux:input wire:model="scorePercentage" type="number" step="0.01" min="0" max="100" label="{{ __('الدرجة (%)') }}" placeholder="{{ __('مثال: 95.5') }}" />
            <flux:textarea wire:model="notes" label="{{ __('ملاحظات') }}" rows="2" />

            <div class="flex justify-end gap-2">
                <flux:button variant="ghost" wire:click="$set('showResult', false)">{{ __('إلغاء') }}</flux:button>
                <flux:button type="submit" variant="primary">{{ __('حفظ') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
