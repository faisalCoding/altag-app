<?php

namespace App\Http\Controllers\Api\V1\Teacher;

use App\Http\Controllers\Controller;
use App\Models\TeacherAttendance;
use App\Support\HijriDate;
use App\Support\TeacherAttendanceMonth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The teacher's own roll-call record, a Hijri month at a time — what their
 * supervisor marked for them. Read online, as the site's page reads it: it is
 * the supervisor's record, not something the phone keeps.
 */
class MyAttendanceController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $validated = $request->validate(['month' => ['nullable', 'date_format:Y-m-d']]);

        $month = TeacherAttendanceMonth::for($request->user(), $validated['month'] ?? now('Asia/Riyadh')->format('Y-m-d'));

        return response()->json(['data' => [
            'month' => $month['month'],
            'title' => $month['title'],
            'previous' => $month['previous'],
            'next' => $month['next'],
            'counts' => $month['counts'],
            'rate' => $month['rate'],
            'unrecorded' => $month['unrecorded'],
            'days' => $month['days']->map(function (string $day) use ($month) {
                /** @var TeacherAttendance|null $record */
                $record = $month['records']->get($day);

                return [
                    'date' => $day,
                    'label' => HijriDate::withWeekday($day),
                    'status' => $record?->status,
                    'arrived_at' => $record?->status === 'late' ? $record->arrivalLabel() : null,
                    'minutes_late' => $record?->minutesLate(),
                    'notes' => $record?->notes,
                    'substitute' => $record && in_array($record->status, TeacherAttendance::AWAY, true) ? $record->substitute?->name : null,
                ];
            })->values(),
        ]]);
    }
}
