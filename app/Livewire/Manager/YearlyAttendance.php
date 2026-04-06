<?php

namespace App\Livewire\Manager;

use App\Models\Attendance as AttendanceModel;
use App\Models\Circle;
use Carbon\Carbon;
use Flux\Flux;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class YearlyAttendance extends Component
{
    public $year;

    public $selectedDate = null;

    public $selectedDateHijri = '';

    public $circlesAttendance = [];

    public function mount()
    {
        // Get the current Hijri year
        $cal = \IntlCalendar::createInstance('Asia/Riyadh', 'ar_SA@calendar=islamic-umalqura');
        $this->year = $cal->get(\IntlCalendar::FIELD_YEAR);
    }

    public function selectDate($date, $hijriDay, $monthName)
    {
        $this->selectedDate = $date;
        $this->selectedDateHijri = "$hijriDay $monthName $this->year";

        $this->circlesAttendance = Circle::with([
            'teachers',
            'attendances' => function ($query) {
                $query->whereDate('date', $this->selectedDate);
            },
        ])->get()->map(function ($circle) {
            $hasAttendance = $circle->attendances->isNotEmpty();
            $teacher = $circle->teachers->first();

            return [
                'id' => $circle->id,
                'name' => $circle->name,
                'is_completed' => $hasAttendance,
                'teacher_name' => $teacher?->name ?? 'غير محدد',
                'teacher_phone' => $teacher?->phone,
            ];
        })->toArray();

        Flux::modal('day-details')->show();
    }

    public function render()
    {
        $cal = \IntlCalendar::createInstance('Asia/Riyadh', 'ar_SA@calendar=islamic-umalqura');
        $cal->set(\IntlCalendar::FIELD_YEAR, $this->year);
        $cal->set(\IntlCalendar::FIELD_MONTH, 0);
        $cal->set(\IntlCalendar::FIELD_DAY_OF_MONTH, 1);
        $startDate = date('Y-m-d', $cal->getTime() / 1000);

        $cal->set(\IntlCalendar::FIELD_YEAR, $this->year);
        $cal->set(\IntlCalendar::FIELD_MONTH, 11);
        $monthLength = $cal->getActualMaximum(\IntlCalendar::FIELD_DAY_OF_MONTH);
        $cal->set(\IntlCalendar::FIELD_DAY_OF_MONTH, $monthLength);
        $endDate = date('Y-m-d', $cal->getTime() / 1000);

        $totalCirclesCount = Circle::count();
        $allAttendances = AttendanceModel::whereBetween('date', [$startDate, $endDate])
            ->select('date', DB::raw('count(distinct circle_id) as circles_completed'))
            ->groupBy('date')
            ->get()
            ->mapWithKeys(function ($item) {
                return [Carbon::parse($item->date)->format('Y-m-d') => $item->circles_completed];
            });

        $months = [];
        for ($m = 0; $m < 12; $m++) {
            $months[] = $this->getMonthData($this->year, $m, $totalCirclesCount, $allAttendances);
        }

        return view('livewire.manager.yearly-attendance', [
            'months' => $months,
            'totalCirclesCount' => $totalCirclesCount,
            'currentYear' => $this->year,
        ])->layout('layouts.role-shell');
    }

    private function getMonthData($year, $monthIndex, $totalCirclesCount, $allAttendances)
    {
        $cal = \IntlCalendar::createInstance('Asia/Riyadh', 'ar_SA@calendar=islamic-umalqura');
        $cal->set(\IntlCalendar::FIELD_YEAR, $year);
        $cal->set(\IntlCalendar::FIELD_MONTH, $monthIndex);
        $cal->set(\IntlCalendar::FIELD_DAY_OF_MONTH, 1);

        $monthLength = $cal->getActualMaximum(\IntlCalendar::FIELD_DAY_OF_MONTH);
        $startDayOfWeek = $cal->get(\IntlCalendar::FIELD_DAY_OF_WEEK); // 1 = Sunday

        $monthNameFormatter = new \IntlDateFormatter('ar_SA@calendar=islamic-umalqura', \IntlDateFormatter::FULL, \IntlDateFormatter::NONE, 'Asia/Riyadh', \IntlDateFormatter::TRADITIONAL, 'MMMM');
        $monthName = $monthNameFormatter->format($cal->getTime() / 1000);

        $days = [];
        $emptySlots = $startDayOfWeek - 1;

        for ($i = 0; $i < $emptySlots; $i++) {
            $days[] = null;
        }

        for ($i = 1; $i <= $monthLength; $i++) {
            $cal->set(\IntlCalendar::FIELD_DAY_OF_MONTH, $i);
            $dayTimestamp = $cal->getTime() / 1000;
            $gregDate = date('Y-m-d', $dayTimestamp);

            $completedCirclesCount = $allAttendances->get($gregDate, 0);
            $completionRate = $totalCirclesCount > 0 ? min(100, round(($completedCirclesCount / $totalCirclesCount) * 100)) : 0;

            $colorClass = 'bg-white hover:bg-zinc-50 dark:bg-zinc-800 dark:hover:bg-zinc-700';
            if ($completedCirclesCount > 0 && $totalCirclesCount > 0) {
                $ratio = $completedCirclesCount / $totalCirclesCount;
                if ($ratio >= 1.0) {
                    $colorClass = 'bg-green-100 dark:bg-green-900/40 border-green-200';
                } elseif ($ratio >= 0.5) {
                    $colorClass = 'bg-blue-50 dark:bg-blue-900/20 border-blue-100';
                } else {
                    $colorClass = 'bg-amber-50 dark:bg-amber-900/20 border-amber-100';
                }
            }

            $days[] = [
                'hijriDay' => $i,
                'gregorianDate' => $gregDate,
                'completedCount' => $completedCirclesCount,
                'completionRate' => $completionRate,
                'colorClass' => $colorClass,
                'isToday' => $gregDate === date('Y-m-d'),
            ];
        }

        return [
            'monthName' => $monthName,
            'days' => $days,
        ];
    }
}
