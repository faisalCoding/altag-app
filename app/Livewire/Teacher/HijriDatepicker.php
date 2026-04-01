<?php

namespace App\Livewire\Teacher;

use App\Models\Attendance as AttendanceModel;
use App\Models\Student;
use Livewire\Attributes\Modelable;
use Livewire\Component;

class HijriDatepicker extends Component
{
    #[Modelable]
    public $date;

    public $circleId;

    // State for the currently viewed calendar month
    public $currentViewTimestamp;

    // For toggling dropdown state
    public $open = false;

    public function mount($circleId)
    {
        $this->circleId = $circleId;
        $this->date = $this->date ?: now()->format('Y-m-d');

        // Parse date for the view
        $cal = \IntlCalendar::createInstance('Asia/Riyadh', 'ar_SA@calendar=islamic-umalqura');
        $cal->setTime(strtotime($this->date) * 1000);
        $cal->set(\IntlCalendar::FIELD_DAY_OF_MONTH, 1);
        $this->currentViewTimestamp = $cal->getTime() / 1000;
    }

    public function getHijriFormattedDateProperty()
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

    public function previousMonth()
    {
        $cal = \IntlCalendar::createInstance('Asia/Riyadh', 'ar_SA@calendar=islamic-umalqura');
        $cal->setTime($this->currentViewTimestamp * 1000);
        $cal->add(\IntlCalendar::FIELD_MONTH, -1);
        $this->currentViewTimestamp = $cal->getTime() / 1000;
    }

    public function nextMonth()
    {
        $cal = \IntlCalendar::createInstance('Asia/Riyadh', 'ar_SA@calendar=islamic-umalqura');
        $cal->setTime($this->currentViewTimestamp * 1000);
        $cal->add(\IntlCalendar::FIELD_MONTH, 1);
        $this->currentViewTimestamp = $cal->getTime() / 1000;
    }

    public function selectDate($gregorianDate)
    {
        $this->date = $gregorianDate;
        $this->open = false;
        
        // Optionally update view to selected month
        $cal = \IntlCalendar::createInstance('Asia/Riyadh', 'ar_SA@calendar=islamic-umalqura');
        $cal->setTime(strtotime($this->date) * 1000);
        $cal->set(\IntlCalendar::FIELD_DAY_OF_MONTH, 1);
        $this->currentViewTimestamp = $cal->getTime() / 1000;
    }

    public function render()
    {
        $cal = \IntlCalendar::createInstance('Asia/Riyadh', 'ar_SA@calendar=islamic-umalqura');
        $cal->setTime($this->currentViewTimestamp * 1000);
        
        $monthLength = $cal->getActualMaximum(\IntlCalendar::FIELD_DAY_OF_MONTH);
        $startDayOfWeek = $cal->get(\IntlCalendar::FIELD_DAY_OF_WEEK); // 1 = Sunday
        
        $monthNameFormatter = new \IntlDateFormatter('ar_SA@calendar=islamic-umalqura', \IntlDateFormatter::FULL, \IntlDateFormatter::NONE, 'Asia/Riyadh', \IntlDateFormatter::TRADITIONAL, 'MMMM yyyy');
        $monthName = $monthNameFormatter->format($this->currentViewTimestamp);

        // Fetch students and attendance
        $totalStudentsCount = Student::where('circle_id', $this->circleId)->where('is_approved', true)->count();
        
        // Calculate bounds
        $cal->set(\IntlCalendar::FIELD_DAY_OF_MONTH, 1);
        $startTimestamp = $cal->getTime() / 1000;
        
        $cal->set(\IntlCalendar::FIELD_DAY_OF_MONTH, $monthLength);
        $endTimestamp = $cal->getTime() / 1000;

        $startDate = date('Y-m-d', $startTimestamp);
        $endDate = date('Y-m-d', $endTimestamp);

        $attendances = AttendanceModel::where('circle_id', $this->circleId)
            ->whereBetween('date', [$startDate, $endDate])
            ->get();
        
        $attendancesGrouped = $attendances->groupBy(function($item) {
            return \Carbon\Carbon::parse($item->date)->format('Y-m-d');
        });
        
        $days = [];
        $emptySlots = $startDayOfWeek - 1; 

        for ($i = 0; $i < $emptySlots; $i++) {
            $days[] = null;
        }

        for ($i = 1; $i <= $monthLength; $i++) {
            $cal->set(\IntlCalendar::FIELD_DAY_OF_MONTH, $i);
            $dayTimestamp = $cal->getTime() / 1000;
            $gregDate = date('Y-m-d', $dayTimestamp);
            
            $dayRecords = $attendancesGrouped->get($gregDate, collect());
            
            // "نسبة الحضور من الغياب" (Ratio of present to absent)
            $presentCount = $dayRecords->whereIn('status', ['present', 'late'])->count();
            $absentCount = $dayRecords->where('status', 'absent')->count();
            
            $processedCount = $dayRecords->count();
            
            $colorClass = 'bg-white hover:bg-zinc-50 dark:bg-zinc-800 dark:hover:bg-zinc-700'; // Default
            
            if ($processedCount > 0) {
                // If more present than absent -> green
                // If more absent than present -> red
                // If equal? neutral or pale
                $totalTracked = $presentCount + $absentCount;
                if ($totalTracked > 0) {
                    $ratio = $presentCount / $totalTracked;
                    
                    if ($ratio >= 0.8) {
                        $colorClass = 'bg-green-100 hover:bg-green-200 dark:bg-green-900/40 dark:hover:bg-green-900/60 transition-colors border-green-200';
                    } elseif ($ratio > 0.5) {
                        $colorClass = 'bg-green-50 hover:bg-green-100 dark:bg-green-900/20 dark:hover:bg-green-900/40 transition-colors border-green-100';
                    } elseif ($ratio >= 0.2) {
                        $colorClass = 'bg-red-50 hover:bg-red-100 dark:bg-red-900/20 dark:hover:bg-red-900/40 transition-colors border-red-100';
                    } else {
                        $colorClass = 'bg-red-100 hover:bg-red-200 dark:bg-red-900/40 dark:hover:bg-red-900/60 transition-colors border-red-200';
                    }
                }
            }
            
            $completionRate = $totalStudentsCount > 0 ? min(100, round(($processedCount / $totalStudentsCount) * 100)) : 0;

            $days[] = [
                'hijriDay' => $i,
                'gregorianDate' => $gregDate,
                'presentCount' => $presentCount,
                'absentCount' => $absentCount,
                'processedCount' => $processedCount,
                'completionRate' => $completionRate,
                'colorClass' => $colorClass,
                'isToday' => $gregDate === date('Y-m-d'),
                'isSelected' => $gregDate === $this->date,
            ];
        }

        return view('livewire.teacher.hijri-datepicker', [
            'monthName' => $monthName,
            'days' => $days,
            'totalStudentsCount' => $totalStudentsCount,
        ]);
    }
}
