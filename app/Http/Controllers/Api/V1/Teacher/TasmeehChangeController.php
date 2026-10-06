<?php

namespace App\Http\Controllers\Api\V1\Teacher;

use App\Http\Controllers\Controller;
use App\Services\TasmeehChangeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TasmeehChangeController extends Controller
{
    /**
     * Apply the tasmeeh grades the app queued on the phone, answering each
     * one: applied, in conflict with the server's current cell, or rejected
     * with the reason the teacher will be shown.
     */
    public function store(Request $request): JsonResponse
    {
        // `present` rather than `required`: null is a value here — clearing a
        // grade, or a cell the server held nothing for.
        $grade = ['present', 'nullable', 'integer', Rule::in(TasmeehChangeService::GRADES)];

        $validated = $request->validate([
            'changes' => ['required', 'array', 'min:1', 'max:500'],
            'changes.*.id' => ['required', 'uuid'],
            'changes.*.kind' => ['required', Rule::in(['plan', 'free'])],
            'changes.*.part' => ['required', Rule::in(['hifz', 'review'])],
            'changes.*.day_id' => ['required_if:changes.*.kind,plan', 'nullable', 'integer'],
            'changes.*.student_id' => ['required_if:changes.*.kind,free', 'nullable', 'integer'],
            'changes.*.date' => ['required', 'date_format:Y-m-d'],
            'changes.*.grade' => $grade,
            // Record a grade carried from another session for this day. Sent
            // by apps that hold one grade per plan day.
            'changes.*.redate' => ['sometimes', 'boolean'],
            // The change is to the session of its date, not to the plan day's
            // latest grade. Sent by apps that hold every session.
            'changes.*.session' => ['sometimes', 'boolean'],
            ...self::rangeRules('changes.*.recited'),
            'changes.*.base' => ['required', 'array'],
            'changes.*.base.grade' => $grade,
            ...self::rangeRules('changes.*.base.recited'),
        ]);

        return response()->json([
            'data' => [
                'results' => TasmeehChangeService::apply($request->user(), $validated['changes']),
                'server_time' => now()->toISOString(),
            ],
        ]);
    }

    /**
     * A recited range, or null for the day's own portion.
     *
     * @return array<string, array<int, string>>
     */
    private static function rangeRules(string $key): array
    {
        return [
            $key => ['present', 'nullable', 'array'],
            "{$key}.from" => ["required_with:{$key}", 'array'],
            "{$key}.from.surah" => ["required_with:{$key}", 'integer', 'between:1,114'],
            "{$key}.from.verse" => ["required_with:{$key}", 'integer', 'min:1'],
            "{$key}.to" => ["required_with:{$key}", 'array'],
            "{$key}.to.surah" => ["required_with:{$key}", 'integer', 'between:1,114'],
            "{$key}.to.verse" => ["required_with:{$key}", 'integer', 'min:1'],
        ];
    }
}
