<?php

namespace App\Http\Controllers\Api\V1\Teacher;

use App\Http\Controllers\Controller;
use App\Services\ExamChangeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ExamChangeController extends Controller
{
    /**
     * Apply the exams the app scheduled or moved on the phone, answering each
     * one: applied, in conflict with the exam the server holds, or rejected
     * with the reason the teacher will be shown.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'changes' => ['required', 'array', 'min:1', 'max:500'],
            'changes.*.id' => ['required', 'uuid'],
            'changes.*.action' => ['required', Rule::in(['create', 'update'])],
            'changes.*.student_id' => ['required', 'integer'],
            'changes.*.exam' => ['required', 'array'],
            'changes.*.exam.id' => ['nullable', 'integer'],
            'changes.*.exam.uuid' => ['nullable', 'uuid'],
            'changes.*.level_id' => ['required', 'integer'],
            'changes.*.date' => ['required', 'date_format:Y-m-d'],
            'changes.*.base' => ['required_if:changes.*.action,update', 'nullable', 'array'],
            'changes.*.base.level_id' => ['required_with:changes.*.base', 'integer'],
            'changes.*.base.date' => ['required_with:changes.*.base', 'date_format:Y-m-d'],
            'changes.*.edited_on' => ['required', 'date_format:Y-m-d'],
        ]);

        return response()->json([
            'data' => [
                'results' => ExamChangeService::apply($request->user(), $validated['changes']),
                'server_time' => now()->toISOString(),
            ],
        ]);
    }
}
