<?php

namespace App\Http\Controllers\Api\V1\Teacher;

use App\Http\Controllers\Controller;
use App\Services\CompetitionGradingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ExtraPointChangeController extends Controller
{
    /**
     * Add or remove the extra points the app queued on the phone. An addition
     * is named by the phone's own id, which becomes the row's uuid.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'changes' => ['required', 'array', 'min:1', 'max:500'],
            'changes.*.id' => ['required', 'uuid'],
            'changes.*.action' => ['required', Rule::in(['add', 'remove'])],
            'changes.*.competition_id' => ['required_if:changes.*.action,add', 'integer'],
            'changes.*.student_id' => ['required_if:changes.*.action,add', 'integer'],
            'changes.*.date' => ['required_if:changes.*.action,add', 'date_format:Y-m-d'],
            'changes.*.points' => ['required_if:changes.*.action,add', 'integer', 'min:1', 'max:1000'],
            'changes.*.notes' => ['required_if:changes.*.action,add', 'string', 'max:255'],
            'changes.*.extra_point_id' => ['nullable', 'integer'],
            'changes.*.extra_point_uuid' => ['nullable', 'uuid'],
        ]);

        return response()->json([
            'data' => [
                'results' => CompetitionGradingService::applyExtraPoints($request->user(), $validated['changes']),
                'server_time' => now()->toISOString(),
            ],
        ]);
    }
}
