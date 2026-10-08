<?php

namespace App\Http\Controllers;

use App\Models\ScheduleWeek;
use App\Services\ProgramScheduleService;
use Illuminate\Contracts\View\View;

/**
 * One week of the programme on its own page, laid out to print or save as a
 * PDF for a guardian: the week must be published and belong to a track
 * their children's stages attend.
 */
class ProgramSchedulePrintController extends Controller
{
    public function __invoke(ScheduleWeek $week, string $role, ProgramScheduleService $schedule): View
    {
        $readable = $schedule->tracksForReader($role)->pluck('id');

        abort_if($week->is_draft || ! $readable->contains($week->schedule_track_id), 404);

        $week->load('cells.activity', 'track');

        return view('schedule.print', ['poster' => $schedule->posterData($week)]);
    }
}
