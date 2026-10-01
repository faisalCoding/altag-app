<?php

namespace App\Http\Controllers\Api\V1\Teacher;

use App\Http\Controllers\Controller;
use App\Services\CompetitionGradingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ScoreChangeController extends Controller
{
    /**
     * Grant or withdraw the criteria the app queued on the phone, answering
     * each change: applied, or rejected with the reason the teacher will see.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'changes' => ['required', 'array', 'min:1', 'max:500'],
            'changes.*.id' => ['required', 'uuid'],
            'changes.*.competition_id' => ['required', 'integer'],
            'changes.*.criterion_id' => ['required', 'integer'],
            'changes.*.student_id' => ['required', 'integer'],
            'changes.*.date' => ['required', 'date_format:Y-m-d'],
            'changes.*.scored' => ['required', 'boolean'],
        ]);

        return response()->json([
            'data' => [
                'results' => CompetitionGradingService::applyScores($request->user(), $validated['changes']),
                'server_time' => now()->toISOString(),
            ],
        ]);
    }
}
