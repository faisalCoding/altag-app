<?php

namespace App\Ai\Tools;

use App\Models\Attendance;
use Carbon\Carbon;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class getAttendanceData implements Tool
{
    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'Get the attendance data for a specific date.';
    }

    public function handle(Request $request): Stringable|string
    {
        $data = Attendance::with([
            'student:name,id',
            'teacher:name,id',
            'circle:name,id,stage_id',
            'circle.stage:name,id',
        ])
            ->get(['date', 'status', 'student_id', 'teacher_id', 'circle_id'])
            ->groupBy(fn ($a) => Carbon::parse($a->date)->format('Y-m-d'))
            ->map(function ($dateGroup) {
                return $dateGroup->groupBy(fn ($a) => $a->circle?->stage?->name ?? 'غير محدد')
                    ->map(function ($stageGroup) {
                        return $stageGroup->groupBy(fn ($a) => $a->circle?->name ?? 'غير محدد')
                            ->map(function ($circleGroup) {
                                return $circleGroup->groupBy(fn ($a) => $a->teacher?->name ?? 'غير محدد')
                                    ->map(function ($teacherGroup) {
                                        return $teacherGroup->mapWithKeys(function ($a) {
                                            $statusAr = match ($a->status) {
                                                'present' => 'حاضر',
                                                'absent' => 'غائب',
                                                'excused' => 'مستأذن',
                                                'late' => 'متأخر',
                                                default => $a->status,
                                            };

                                            return [$a->student?->name ?? 'غير محدد' => $statusAr];
                                        });
                                    });
                            });
                    });
            });

        return $data->toJson(JSON_UNESCAPED_UNICODE);
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'value' => $schema->string()->required(),
        ];
    }
}
