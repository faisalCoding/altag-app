<?php

namespace App\Http\Controllers\Api\V1\Teacher;

use App\Http\Controllers\Controller;
use App\Services\PlanChangeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PlanChangeController extends Controller
{
    /**
     * Save the Quran plans the app wrote on the phone, answering each one:
     * applied, with the plans made for the student meanwhile, or rejected with
     * the reason the teacher will be shown.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'changes' => ['required', 'array', 'min:1', 'max:50'],
            'changes.*.id' => ['required', 'uuid'],
            'changes.*.student_id' => ['required', 'integer'],
            'changes.*.plan_type' => ['required', Rule::in(['hifz', 'review', 'hifz_review'])],
            'changes.*.direction' => ['required', Rule::in(['forward', 'reverse'])],
            'changes.*.review_direction' => ['required', Rule::in(['forward', 'reverse'])],
            'changes.*.start_date' => ['required', 'date_format:Y-m-d'],
            'changes.*.active_days' => ['required', 'array', 'min:1', 'max:7'],
            'changes.*.active_days.*' => ['required', Rule::in(array_keys(PlanChangeService::DAY_NAMES))],
            'changes.*.description' => ['nullable', 'string', 'max:2000'],
            'changes.*.deactivate_previous' => ['required', 'boolean'],
            // When the phone last synced: plans made after it are news to its teacher.
            'changes.*.synced_at' => ['required', 'date'],
            'changes.*.days' => ['required', 'array', 'min:1', 'max:100'],
            'changes.*.days.*.date' => ['required', 'date_format:Y-m-d'],
            'changes.*.days.*.hifz' => ['nullable', 'array'],
            'changes.*.days.*.review' => ['nullable', 'array'],
        ]);

        return response()->json([
            'data' => [
                'results' => PlanChangeService::apply($request->user(), $validated['changes']),
                'server_time' => now()->toISOString(),
            ],
        ]);
    }
}
