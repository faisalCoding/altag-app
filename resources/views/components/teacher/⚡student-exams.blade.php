<?php

use App\Models\StudentExam;
use App\Models\Student;
use App\Models\ExamLevel;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithPagination;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Locked;

new class extends Component {
    use WithPagination;

    public $search = '';

    public $showModal = false;
    #[Locked]
    public $editingId = null;

    public $studentId = null;
    public $examLevelId = null;
    public $dateTime = null;
    public $location = '';
    public $notes = '';
    public $scorePercentage = null;
    public $status = 'passed';

    public $students = [];
    public $examLevels = [];

    protected $rules = [
        'studentId' => 'required|exists:users,id',
        'examLevelId' => 'required|exists:exam_levels,id',
        'dateTime' => 'required|date',
        'location' => 'nullable|string|max:255',
        'notes' => 'nullable|string',
        'scorePercentage' => 'nullable|numeric|min:0|max:100',
        'status' => 'required|in:pending,passed,failed,absent,cancelled',
    ];

    public function mount()
    {
        $teacher = Auth::guard('teacher')->user();
        $circleIds = $teacher->circles()->pluck('circles.id');
        $this->students = Student::whereIn('circle_id', $circleIds)->orderBy('name')->get();
        $this->examLevels = ExamLevel::orderBy('name')->get();
        $this->dateTime = now()->format('Y-m-d\TH:i');
    }

    public function create()
    {
        $this->resetValidation();
        $this->reset(['editingId', 'studentId', 'examLevelId', 'location', 'notes', 'scorePercentage']);
        $this->status = 'pending';
        $this->dateTime = now()->format('Y-m-d\TH:i');
        $this->showModal = true;
    }

    public function edit($id)
    {
        $this->resetValidation();

        $teacher = Auth::guard('teacher')->user();
        $circleIds = $teacher->circles()->pluck('circles.id');

        $exam = StudentExam::whereHas('student', function ($q) use ($circleIds) {
            $q->whereIn('circle_id', $circleIds);
        })->findOrFail($id);

        $this->editingId = $exam->id;
        $this->studentId = $exam->student_id;
        $this->examLevelId = $exam->exam_level_id;
        $this->dateTime = $exam->date_time->format('Y-m-d\TH:i');
        $this->location = $exam->location;
        $this->notes = $exam->notes;
        $this->scorePercentage = $exam->score_percentage;
        $this->status = $exam->status;

        $this->showModal = true;
    }

    public function save()
    {
        $this->validate();

        // Ensure student belongs to teacher
        $teacher = Auth::guard('teacher')->user();
        $circleIds = $teacher->circles()->pluck('circles.id');
        $student = Student::whereIn('circle_id', $circleIds)->findOrFail($this->studentId);

        $wasCreate = ! $this->editingId;
        $previousStatus = $this->editingId ? StudentExam::find($this->editingId)?->status : null;

        $exam = StudentExam::updateOrCreate(
            ['id' => $this->editingId],
            [
                'student_id' => $student->id,
                'exam_level_id' => $this->examLevelId,
                'date_time' => Carbon::parse($this->dateTime),
                'location' => $this->location,
                'notes' => $this->notes,
                'score_percentage' => $this->scorePercentage !== '' ? $this->scorePercentage : null,
                'status' => $this->status,
            ]
        );

        if ($wasCreate) {
            \App\Services\NotificationService::notify(
                'student',
                $student->id,
                'exam_scheduled',
                'اختبار جديد مجدول',
                'تم جدولة اختبار جديد لك، تفقّد التفاصيل',
                route('student.exams'),
            );
        } elseif ($previousStatus === 'pending' && in_array($this->status, ['passed', 'failed'], true)) {
            \App\Services\NotificationService::notify(
                'student',
                $student->id,
                'exam_result',
                'نتيجة اختبار',
                'تم رصد نتيجة اختبارك، تفقّد التفاصيل',
                route('student.exams'),
            );
        }

        $this->showModal = false;
        $this->dispatch('toast', variant: 'success', heading: 'تم الحفظ بنجاح!');
    }

    public function delete($id)
    {
        $teacher = Auth::guard('teacher')->user();
        $circleIds = $teacher->circles()->pluck('circles.id');

        $exam = StudentExam::whereHas('student', function ($q) use ($circleIds) {
            $q->whereIn('circle_id', $circleIds);
        })->findOrFail($id);

        $exam->delete();
        $this->dispatch('toast', variant: 'success', heading: 'تم الحذف بنجاح!');
    }

    public function with()
    {
        $teacher = Auth::guard('teacher')->user();
        $circleIds = $teacher->circles()->pluck('circles.id');

        $exams = StudentExam::with(['student', 'examLevel'])
            ->whereHas('student', function ($query) use ($circleIds) {
                $query->whereIn('circle_id', $circleIds)
                    ->where('name', 'like', '%' . $this->search . '%');
            })
            ->latest('date_time')
            ->paginate(15);

        return [
            'exams' => $exams,
        ];
    }
};
?>

<div class="space-y-6">
    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
        <div>
            <flux:heading size="xl">{{ __('اختبارات الطلاب') }}</flux:heading>
            <flux:subheading>{{ __('إدارة ومتابعة اختبارات الجمعية لطلاب حلقتك') }}</flux:subheading>
        </div>
        <flux:button variant="primary" wire:click="create" icon="plus">{{ __('إضافة اختبار جديد') }}</flux:button>
    </div>

    <flux:card>
        <div class="mb-4 w-full sm:w-1/3">
            <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass"
                placeholder="{{ __('بحث باسم الطالب...') }}" />
        </div>

        <div class="overflow-x-auto">
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('الطالب') }}</flux:table.column>
                    <flux:table.column>{{ __('المستوى') }}</flux:table.column>
                    <flux:table.column>{{ __('الحالة') }}</flux:table.column>
                    <flux:table.column>{{ __('التاريخ والوقت') }}</flux:table.column>
                    <flux:table.column>{{ __('الدرجة') }}</flux:table.column>
                    <flux:table.column>{{ __('المكان') }}</flux:table.column>
                    <flux:table.column>{{ __('الإجراءات') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @forelse ($exams as $exam)
                        <flux:table.row>
                            <flux:table.cell class="font-semibold">{{ $exam->student->name ?? '-' }}</flux:table.cell>
                            <flux:table.cell class="first:ps-3" >
                                <flux:badge color="indigo">{{ $exam->examLevel->name ?? '-' }}</flux:badge>
                            </flux:table.cell>
                            <flux:table.cell class="first:ps-3" >
                                @if($exam->status === 'passed')
                                    <flux:badge color="emerald">تم التجاوز</flux:badge>
                                @elseif($exam->status === 'failed')
                                    <flux:badge color="red">لم يتجاوز</flux:badge>
                                @elseif($exam->status === 'absent')
                                    <flux:badge color="amber">تغيب</flux:badge>
                                @elseif($exam->status === 'pending')
                                    <flux:badge color="blue">في الانتظار</flux:badge>
                                @else
                                    <flux:badge color="zinc">ملغى</flux:badge>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell class="first:ps-3" >
                                <div class="text-sm"><x-hijri-date :date="$exam->date_time" /></div>
                                <div class="text-xs text-zinc-500">{{ $exam->date_time->format('h:i A') }}</div>
                            </flux:table.cell>
                            <flux:table.cell class="first:ps-3" >
                                @if($exam->score_percentage !== null)
                                    <span
                                        class="font-bold {{ $exam->score_percentage >= 90 ? 'text-green-600' : ($exam->score_percentage >= 70 ? 'text-amber-500' : 'text-red-500') }}">
                                        {{ $exam->score_percentage }}%
                                    </span>
                                @else
                                    <span class="text-zinc-400 text-sm">لم ترصد</span>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell class="first:ps-3" >{{ $exam->location ?? '-' }}</flux:table.cell>
                            <flux:table.cell class="first:ps-3" >
                                <flux:dropdown>
                                    <flux:button variant="ghost" size="sm" icon="ellipsis-vertical" inset="top bottom" />
                                    <flux:menu>
                                        <flux:menu.item wire:click="edit({{ $exam->id }})" icon="pencil-square">
                                            {{ __('تعديل') }}</flux:menu.item>
                                        <flux:menu.item wire:click="delete({{ $exam->id }})"
                                            wire:confirm="{{ __('هل أنت متأكد من الحذف؟') }}" icon="trash" variant="danger">
                                            {{ __('حذف') }}</flux:menu.item>
                                    </flux:menu>
                                </flux:dropdown>
                            </flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="6" class="text-center text-zinc-500 py-8">
                                {{ __('لا توجد اختبارات مسجلة.') }}
                            </flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </div>

        <div class="mt-4">
            {{ $exams->links() }}
        </div>
    </flux:card>

    <flux:modal wire:model="showModal" class="md:w-3/4 max-w-2xl">
        <form wire:submit.prevent="save" class="space-y-6">
            <flux:heading size="lg">{{ $editingId ? __('تعديل الاختبار') : __('إضافة اختبار جديد') }}</flux:heading>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <flux:select wire:model="studentId" label="{{ __('الطالب') }}" placeholder="{{ __('اختر الطالب') }}"
                    searchable>
                    @foreach($students as $student)
                        <flux:select.option value="{{ $student->id }}">{{ $student->name }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:select wire:model="examLevelId" label="{{ __('المستوى') }}" placeholder="{{ __('اختر المستوى') }}"
                    searchable>
                    @foreach($examLevels as $level)
                        <flux:select.option value="{{ $level->id }}">{{ $level->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <flux:input type="datetime-local" wire:model="dateTime" label="{{ __('التاريخ والوقت') }}" />
                <flux:input wire:model="scorePercentage" type="number" step="0.01" min="0" max="100"
                    label="{{ __('الدرجة (%)') }}" placeholder="{{ __('مثال: 95.5') }}" />
            </div>

            <flux:radio.group wire:model="status" label="{{ __('حالة الاختبار') }}" variant="cards"
                class="grid grid-cols-2 sm:grid-cols-5 gap-2">
                <flux:radio value="pending" label="{{ __('في الانتظار') }}" icon="clock" />
                <flux:radio value="passed" label="{{ __('تم التجاوز') }}" icon="check-circle" />
                <flux:radio value="failed" label="{{ __('لم يتجاوز') }}" icon="x-circle" />
                <flux:radio value="absent" label="{{ __('تغيب') }}" icon="calendar" />
                <flux:radio value="cancelled" label="{{ __('ملغى') }}" icon="no-symbol" />
            </flux:radio.group>

            <flux:input wire:model="location" label="{{ __('المكان') }}"
                placeholder="{{ __('مثال: قاعة الجمعية، مسجد عثمان...') }}" />

            <flux:textarea wire:model="notes" label="{{ __('ملاحظات') }}" rows="3" />

            <div class="flex justify-end gap-2 mt-6">
                <flux:button variant="ghost" wire:click="$set('showModal', false)">{{ __('إلغاء') }}</flux:button>
                <flux:button type="submit" variant="primary">{{ __('حفظ') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>