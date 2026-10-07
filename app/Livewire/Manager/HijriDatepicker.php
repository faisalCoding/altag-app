<?php

namespace App\Livewire\Manager;

use App\Models\Attendance as AttendanceModel;
use App\Models\Circle;
use App\Support\HijriDate;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Modelable;
use Livewire\Component;

class HijriDatepicker extends Component
{
    #[Modelable]
    public $date;

    public $label;

    /** The last day that may be picked (Y-m-d); later ones show but cannot be chosen. */
    public ?string $maxDate = null;

    // State for the currently viewed calendar month
    public $currentViewTimestamp;

    // For toggling dropdown state
    public $open = false;

    public function mount($label = 'التاريخ (هجري)')
    {
        $this->label = $label;
        $this->date = $this->date ?: now()->format('Y-m-d');

        // Parse date for the view
        $cal = \IntlCalendar::createInstance('Asia/Riyadh', 'ar_SA@calendar=islamic-umalqura');
        $cal->setTime(strtotime($this->date) * 1000);
        $cal->set(\IntlCalendar::FIELD_DAY_OF_MONTH, 1);
        $this->currentViewTimestamp = $cal->getTime() / 1000;
    }

    public function getHijriFormattedDateProperty()
    {
        if (! $this->date) {
            return '';
        }

        return HijriDate::full($this->date);
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
        if ($this->maxDate && $gregorianDate > $this->maxDate) {
            return;
        }

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

        $monthName = HijriDate::monthYear($this->currentViewTimestamp);

        // Fetch overall circle count
        $totalCirclesCount = Circle::count();

        // Calculate bounds
        $cal->set(\IntlCalendar::FIELD_DAY_OF_MONTH, 1);
        $startTimestamp = $cal->getTime() / 1000;

        $cal->set(\IntlCalendar::FIELD_DAY_OF_MONTH, $monthLength);
        $endTimestamp = $cal->getTime() / 1000;

        $startDate = date('Y-m-d', $startTimestamp);
        $endDate = date('Y-m-d', $endTimestamp);

        // Fetch circle attendance activity per day
        $attendancesCounts = AttendanceModel::whereBetween('date', [$startDate, $endDate])
            ->select('date', DB::raw('count(distinct circle_id) as circles_completed'))
            ->groupBy('date')
            ->get()
            ->mapWithKeys(function ($item) {
                return [Carbon::parse($item->date)->format('Y-m-d') => $item->circles_completed];
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

            $completedCirclesCount = $attendancesCounts->get($gregDate, 0);

            $colorClass = 'bg-white hover:bg-zinc-50 dark:bg-zinc-800 dark:hover:bg-zinc-700'; // Default

            if ($completedCirclesCount > 0 && $totalCirclesCount > 0) {
                $ratio = $completedCirclesCount / $totalCirclesCount;

                if ($ratio >= 1.0) {
                    $colorClass = 'bg-green-100 hover:bg-green-200 dark:bg-green-900/40 dark:hover:bg-green-900/60   s border-green-200';
                } elseif ($ratio >= 0.5) {
                    $colorClass = 'bg-blue-50 hover:bg-blue-100 dark:bg-blue-900/20 dark:hover:bg-blue-900/40   s border-blue-100';
                } else {
                    $colorClass = 'bg-amber-50 hover:bg-amber-100 dark:bg-amber-900/20 dark:hover:bg-amber-900/40   s border-amber-100';
                }
            }

            $completionRate = $totalCirclesCount > 0 ? min(100, round(($completedCirclesCount / $totalCirclesCount) * 100)) : 0;

            $days[] = [
                'hijriDay' => $i,
                'gregorianDate' => $gregDate,
                'completedCount' => $completedCirclesCount,
                'completionRate' => $completionRate,
                'colorClass' => $colorClass,
                'isToday' => $gregDate === date('Y-m-d'),
                'isSelected' => $gregDate === $this->date,
                'isAfterMax' => $this->maxDate && $gregDate > $this->maxDate,
            ];
        }

        return view('livewire.manager.hijri-datepicker', [
            'monthName' => $monthName,
            'days' => $days,
            'totalCirclesCount' => $totalCirclesCount,
        ]);
    }
}
