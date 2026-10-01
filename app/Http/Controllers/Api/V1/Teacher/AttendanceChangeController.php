<?php

namespace App\Http\Controllers\Api\V1\Teacher;

use App\Http\Controllers\Controller;
use App\Services\AttendanceChangeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AttendanceChangeController extends Controller
{
    /**
     * Apply the attendance edits the app queued on the phone, answering each
     * one: applied, in conflict with the server's current value, or rejected
     * with the reason the teacher will be shown.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'changes' => ['required', 'array', 'min:1', 'max:500'],
            'changes.*.id' => ['required', 'uuid'],
            'changes.*.student_id' => ['required', 'integer'],
            'changes.*.date' => ['required', 'date_format:Y-m-d'],
            // `present` rather than `required`: null is a value here — clearing
            // the cell, or a cell the server held nothing for.
            'changes.*.status' => ['present', 'nullable', Rule::in(AttendanceChangeService::STATUSES)],
            'changes.*.base_status' => ['present', 'nullable', Rule::in(AttendanceChangeService::STATUSES)],
            'changes.*.reason' => ['nullable', 'string', 'max:1000'],
            'changes.*.edited_on' => ['required', 'date_format:Y-m-d'],
        ]);

        return response()->json([
            'data' => [
                'results' => AttendanceChangeService::apply($request->user(), $validated['changes']),
                'server_time' => now()->toISOString(),
            ],
        ]);
    }
}
