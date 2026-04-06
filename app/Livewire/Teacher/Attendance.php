<?php

namespace App\Livewire\Teacher;

use App\Models\Attendance as AttendanceModel;
use App\Models\Student;
use Flux\Flux;
use Livewire\Component;

class Attendance extends Component
{
    /** @var 'wizard'|'list' */
    public string $mode = 'wizard';

    public $circles = [];

    public ?int $selectedCircle = null;

    public string $date = '';

    public $students;

    /** @var array<int, string> student_id => status */
    public array $records = [];

    public int $currentIndex = 0;

    public bool $isComplete = false;

    public function mount(): void
    {

        $this->students = collect();
        $this->date = now()->format('Y-m-d');

        $teacher = auth()->guard('teacher')->user();
        $this->circles = $teacher->circles()->get();

        if ($this->circles->count() === 1) {
            $this->selectedCircle = $this->circles->first()->id;
            $this->loadStudents();
        }

    }

    public function updatedSelectedCircle(): void
    {
        $this->loadStudents();
    }

    public function updatedDate(): void
    {
        $this->loadStudents();
    }

    public function loadStudents(): void
    {
        if (! $this->selectedCircle) {
            $this->students = collect();
            $this->records = [];

            return;
        }

        $this->students = Student::where('circle_id', $this->selectedCircle)
            ->where('is_approved', true)
            ->orderBy('name')
            ->get();

        // Load existing attendance records for this date
        $existing = AttendanceModel::where('circle_id', $this->selectedCircle)
            ->whereDate('date', $this->date)
            ->pluck('status', 'student_id')
            ->toArray();

        $this->records = [];
        foreach ($this->students as $student) {
            $this->records[$student->id] = $existing[$student->id] ?? '';
        }

        // Find the first unmarked student for wizard mode
        $this->currentIndex = 0;
        foreach ($this->students as $index => $student) {
            if (empty($this->records[$student->id])) {
                $this->currentIndex = $index;
                break;
            }
        }

        $this->checkCompletion();
    }

    public function markStatus(int $studentId, string $status): void
    {
        if (! in_array($status, ['present', 'absent', 'late', 'excused'])) {
            return;
        }

        $this->records[$studentId] = $status;
        $this->saveRecord($studentId, $status);

        // Move to next unmarked student
        $this->moveToNextUnmarked();
    }

    public function updateStatus(int $studentId, string $status): void
    {
        if (! in_array($status, ['present', 'absent', 'late', 'excused'])) {
            return;
        }

        $this->records[$studentId] = $status;
        $this->saveRecord($studentId, $status);
    }

    public function goToPrevious(): void
    {
        if ($this->currentIndex > 0) {
            $this->currentIndex--;
        }
    }

    public function goToNext(): void
    {
        if ($this->currentIndex < count($this->students) - 1) {
            $this->currentIndex++;
        }
    }

    public function switchMode(string $mode): void
    {
        if (in_array($mode, ['wizard', 'list'])) {
            $this->mode = $mode;
        }
    }

    public function markAllPresent(): void
    {
        $teacher = auth()->guard('teacher')->user();

        foreach ($this->students as $student) {
            if (AttendanceModel::whereDate('date', $this->date)->where('student_id', $student->id)->exists()) {
                continue;
            }
            $this->records[$student->id] = 'present';

            AttendanceModel::updateOrCreate(
                [
                    'student_id' => $student->id,
                    'date' => $this->date,
                ],
                [
                    'teacher_id' => $teacher->id,
                    'circle_id' => $this->selectedCircle,
                    'status' => 'present',
                ]
            );
        }

        $this->isComplete = true;
        Flux::toast('تم تسجيل حضور جميع الطلاب بنجاح', variant: 'success');
    }

    private function saveRecord(int $studentId, string $status): void
    {
        $teacher = auth()->guard('teacher')->user();
        if (AttendanceModel::whereDate('date', $this->date)->where('student_id', $studentId)->exists()) {
            AttendanceModel::whereDate('date', $this->date)->where('student_id', $studentId)->update([
                'teacher_id' => $teacher->id,
                'circle_id' => $this->selectedCircle,
                'status' => $status,
            ]);
            Flux::toast('تم تحديث حالة الطالب بنجاح', variant: 'success');

            return;
        }
        AttendanceModel::updateOrCreate(
            [
                'student_id' => $studentId,
                'date' => $this->date,
            ],
            [
                'teacher_id' => $teacher->id,
                'circle_id' => $this->selectedCircle,
                'status' => $status,
            ]
        );

        $this->checkCompletion();
    }

    private function moveToNextUnmarked(): void
    {
        $total = count($this->students);

        for ($i = $this->currentIndex + 1; $i < $total; $i++) {
            $student = $this->students[$i];
            if (empty($this->records[$student->id])) {
                $this->currentIndex = $i;

                return;
            }
        }

        // If all marked from current position, check from beginning
        for ($i = 0; $i <= $this->currentIndex; $i++) {
            $student = $this->students[$i];
            if (empty($this->records[$student->id])) {
                $this->currentIndex = $i;

                return;
            }
        }

        // All students marked
        $this->isComplete = true;
        Flux::toast('تم تحضير جميع الطلاب بنجاح! 🎉', variant: 'success');
    }

    private function checkCompletion(): void
    {
        $this->isComplete = count($this->students) > 0
            && collect($this->records)->filter(fn ($s) => ! empty($s))->count() === count($this->students);
    }

    public function getMarkedCountProperty(): int
    {
        return collect($this->records)->filter(fn ($s) => ! empty($s))->count();
    }

    public function getWhatsAppMessage(Student $student): string
    {
        $hijriDate = $this->getHijriDate();
        $absencesCount = $student->getAbsencesInLast30DaysCount($this->date);

        $ordinals = [
            1 => 'أول',
            2 => 'ثاني',
            3 => 'ثالث',
            4 => 'رابع',
            5 => 'خامس',
            6 => 'سادس',
            7 => 'سابع',
            8 => 'ثامن',
            9 => 'تاسع',
            10 => 'عاشر',
        ];

        $ordinal = $ordinals[$absencesCount] ?? $absencesCount;

        return "السلام عليكم ورحمة الله وبركاته\n نود ان نشعركم بأن الطالب {$student->name} غائب اليوم {$hijriDate}\n و انه هذه {$ordinal} حالة غياب تم تسجيلها عليه \n`وفي حال تم تسجيل ثلاث حالات غياب على الطالب بلا عذر فسيتم ايقافه`";
    }

    private function getHijriDate(): string
    {
        $formatter = new \IntlDateFormatter(
            'ar_SA@calendar=islamic-umalqura',
            \IntlDateFormatter::FULL,
            \IntlDateFormatter::NONE,
            'Asia/Riyadh',
            \IntlDateFormatter::TRADITIONAL,
            'd MMMM yyyy'
        );

        return $formatter->format(strtotime($this->date));
    }

    public function render()
    {
        return view('livewire.teacher.attendance');
    }
}
