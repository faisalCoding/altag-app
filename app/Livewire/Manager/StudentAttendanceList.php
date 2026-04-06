<?php

namespace App\Livewire\Manager;

use App\Models\Attendance as AttendanceModel;
use App\Models\Circle;
use App\Models\Student;
use Flux\Flux;
use Livewire\Component;

class StudentAttendanceList extends Component
{
    public $circleId;

    public $date;

    public $circle;

    public $students;

    public array $records = [];

    public bool $isComplete = false;

    public function mount($circleId, $date)
    {
        $this->circleId = $circleId;
        $this->date = $date;
        $this->circle = Circle::with('teachers')->findOrFail($circleId);
        $this->loadStudents();
    }

    public function loadStudents()
    {
        $this->students = Student::where('circle_id', $this->circleId)
            ->where('is_approved', true)
            ->orderBy('name')
            ->get();

        $existing = AttendanceModel::where('circle_id', $this->circleId)
            ->whereDate('date', $this->date)
            ->pluck('status', 'student_id')
            ->toArray();

        $this->records = [];
        foreach ($this->students as $student) {
            $this->records[$student->id] = $existing[$student->id] ?? '';
        }

        $this->checkCompletion();
    }

    public function updateStatus(int $studentId, string $status)
    {
        if (! in_array($status, ['present', 'absent', 'late', 'excused'])) {
            return;
        }

        $this->records[$studentId] = $status;
        $this->saveRecord($studentId, $status);
    }

    private function saveRecord(int $studentId, string $status)
    {
        $teacherId = $this->circle->teachers->first()?->id;

        AttendanceModel::updateOrCreate(
            [
                'student_id' => $studentId,
                'date' => $this->date,
            ],
            [
                'teacher_id' => $teacherId,
                'circle_id' => $this->circleId,
                'status' => $status,
            ]
        );

        Flux::toast('تم تحديث حالة الطالب بنجاح', variant: 'success');
        $this->checkCompletion();
    }

    private function checkCompletion()
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
        return view('livewire.manager.student-attendance-list');
    }
}
