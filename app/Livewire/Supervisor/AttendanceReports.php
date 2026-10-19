<?php

namespace App\Livewire\Supervisor;

use App\Livewire\Manager\AttendanceReports as ManagerAttendanceReports;
use App\Models\Stage;
use Illuminate\Database\Eloquent\Collection;

/**
 * The supervisor's attendance reports: the manager's page over the stages
 * they oversee alone — their circles' rows, their stages to pick from, and
 * their own day pages behind the cells.
 */
class AttendanceReports extends ManagerAttendanceReports
{
    protected const ROLE = 'supervisor';

    /**
     * The supervisor's stages; one with none reads nothing rather than all.
     *
     * @return array<int, int>
     */
    protected function readableStageIds(): array
    {
        return auth()->guard('supervisor')->user()->stages()->pluck('stages.id')->map(fn ($id) => (int) $id)->all() ?: [0];
    }

    /** @return Collection<int, Stage> */
    protected function reportableStages(): Collection
    {
        return Stage::whereIn('id', $this->readableStageIds())->get(['id', 'name']);
    }
}
