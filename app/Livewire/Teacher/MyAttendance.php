<?php

namespace App\Livewire\Teacher;

use App\Support\TeacherAttendanceMonth;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * The teacher's own roll-call record, a Hijri month at a time: what the
 * supervisor marked on each working day, with the reason, the arrival time
 * and who covered where they were given.
 */
class MyAttendance extends Component
{
    /** The first Gregorian day of the Hijri month on show. */
    public string $month = '';

    public function mount(): void
    {
        $this->month = $this->read()['month'];
    }

    public function previousMonth(): void
    {
        $this->month = $this->read()['previous'];
    }

    public function nextMonth(): void
    {
        $this->month = $this->read()['next'] ?? $this->month;
    }

    /**
     * @return array<string, mixed>
     */
    private function read(): array
    {
        $day = preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->month) ? $this->month : now('Asia/Riyadh')->format('Y-m-d');

        return TeacherAttendanceMonth::for(Auth::guard('teacher')->user(), $day);
    }

    public function render()
    {
        $month = $this->read();

        return view('livewire.teacher.my-attendance', $month + [
            'hasNext' => $month['next'] !== null,
        ]);
    }
}
