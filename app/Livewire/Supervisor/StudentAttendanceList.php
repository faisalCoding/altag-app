<?php

namespace App\Livewire\Supervisor;

use App\Livewire\Manager\StudentAttendanceList as ManagerStudentAttendanceList;
use App\Models\Circle;

/**
 * One circle's roll on one day for the supervisor: the manager's page, for
 * the circles of the stages they oversee alone.
 */
class StudentAttendanceList extends ManagerStudentAttendanceList
{
    protected const ROLE = 'supervisor';

    protected function findCircle($circleId): Circle
    {
        $circle = Circle::with('teachers')
            ->whereIn('stage_id', auth()->guard('supervisor')->user()->stages()->pluck('stages.id'))
            ->find($circleId);

        abort_unless($circle, 404);

        return $circle;
    }
}
