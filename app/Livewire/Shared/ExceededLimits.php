<?php

namespace App\Livewire\Shared;

use App\Models\Setting;
use App\Models\Student;
use App\Support\DisciplineWindow;
use Livewire\Component;

class ExceededLimits extends Component
{
    public function render()
    {
        $absenceLimit = (int) Setting::getVal('absence_limit', 3);
        $latenessLimit = (int) Setting::getVal('lateness_limit', 5);
        $days = DisciplineWindow::days();
        $window = DisciplineWindow::for();

        $query = Student::query()
            ->with(['circle.stage', 'guardian'])
            ->whereRoleState(fn ($q) => $q->where('is_approved', true))
            ->withCount([
                'attendances as recent_absences_count' => function ($query) use ($window) {
                    $query->where('status', 'absent')->whereDate('date', '>=', $window['from'])->whereDate('date', '<=', $window['to']);
                },
                'attendances as recent_lateness_count' => function ($query) use ($window) {
                    $query->where('status', 'late')->whereDate('date', '>=', $window['from'])->whereDate('date', '<=', $window['to']);
                },
            ]);

        // Filter depending on role
        if (auth()->guard('teacher')->check()) {
            $circleIds = auth()->guard('teacher')->user()->circles()->pluck('circle_id');
            $query->whereIn('circle_id', $circleIds);
        } elseif (auth()->guard('supervisor')->check()) {
            $stageIds = auth()->guard('supervisor')->user()->stages()->pluck('stage_id');
            $query->whereHas('circle', function ($q) use ($stageIds) {
                $q->whereIn('stage_id', $stageIds);
            });
        }

        $students = collect();
        if ($query->count() > 0) {
            $students = $query->get()->filter(function ($student) use ($absenceLimit, $latenessLimit) {
                return $student->recent_absences_count >= $absenceLimit ||
                       $student->recent_lateness_count >= $latenessLimit;
            })->values(); // Reset numbering
        }

        return view('livewire.shared.exceeded-limits', [
            'students' => $students,
            'absenceLimit' => $absenceLimit,
            'latenessLimit' => $latenessLimit,
            'periodDays' => $days,
        ]);
    }
}
