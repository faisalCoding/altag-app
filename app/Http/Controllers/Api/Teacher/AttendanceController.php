<?php

namespace App\Http\Controllers\Api\Teacher;

use App\Http\Controllers\Controller;
use App\Http\Resources\AttendanceResource;
use App\Http\Resources\CircleResource;
use App\Http\Resources\StudentAttendanceResource;
use App\Models\Attendance;
use App\Models\Student;
use App\Services\GamificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AttendanceController extends Controller
{
    public function index(Request $request)
    {
        $teacher = $request->user();
        $circles = $teacher->circles()->get();

        if ($request->filled('last_synced_at')) {
            $request->validate([
                'last_synced_at' => 'required|date',
            ]);

            $lastSyncedAt = Carbon::parse($request->input('last_synced_at'));
            $circleIds = $teacher->circles()->pluck('circles.id');

            $syncedCircles = $teacher->circles()
                ->where('circles.updated_at', '>', $lastSyncedAt)
                ->get();

            $syncedStudents = Student::whereIn('circle_id', $circleIds)
                ->whereRoleState(fn ($q) => $q->where('is_approved', true))
                ->where('updated_at', '>', $lastSyncedAt)
                ->orderBy('name')
                ->get();

            $syncedAttendances = Attendance::whereIn('circle_id', $circleIds)
                ->where('updated_at', '>', $lastSyncedAt)
                ->get();

            return response()->json([
                'circles' => CircleResource::collection($syncedCircles),
                'students' => StudentAttendanceResource::collection($syncedStudents),
                'attendances' => AttendanceResource::collection($syncedAttendances),
                'server_time' => now()->toISOString(),
            ]);
        }

        if ($request->filled('circle_id')) {
            $circle = $teacher->circles()->where('circles.id', $request->circle_id)->first();

            if (! $circle) {
                return response()->json([
                    'message' => 'غير مصرح لك بالوصول لهذه الحلقة.',
                ], 403);
            }

            $date = $request->input('date', now()->format('Y-m-d'));

            $studentsQuery = Student::where('circle_id', $circle->id)
                ->whereRoleState(fn ($q) => $q->where('is_approved', true))
                ->where(function ($query) use ($date) {
                    $query->whereNull('joined_at')
                        ->orWhereDate('joined_at', '<=', $date);
                })
                ->with([
                    'circle',
                    'statusHistories' => function ($query) use ($date) {
                        $query->where('start_date', '<=', $date)->orderBy('start_date', 'desc')->orderByDesc('id');
                    },
                ])
                ->orderBy('name')
                ->get();

            $students = $studentsQuery->filter(function ($student) {
                $history = $student->statusHistories->first();
                $statusOnDate = $history ? $history->status : $student->status;

                return $statusOnDate === 'active';
            })->values();

            $existing = Attendance::where('circle_id', $circle->id)
                ->whereDate('date', $date)
                ->pluck('status', 'student_id')
                ->toArray();

            foreach ($students as $student) {
                $student->attendance_status = $existing[$student->id] ?? '';
            }

            return response()->json([
                'circles' => CircleResource::collection($circles),
                'selected_circle' => new CircleResource($circle),
                'students' => StudentAttendanceResource::collection($students),
            ]);
        }

        return response()->json([
            'circles' => CircleResource::collection($circles),
            'selected_circle' => null,
            'students' => [],
        ]);
    }

    public function store(Request $request)
    {
        $teacher = $request->user();
        $records = $request->input('records');
        $isSequential = is_array($records) && ! empty($records) && array_is_list($records);

        if ($isSequential) {
            $request->validate([
                'records' => 'required|array',
                'records.*.student_id' => 'required|exists:users,id',
                'records.*.status' => 'required|in:present,absent,late,excused',
                'records.*.date' => 'required|date',
                'records.*.updated_at' => 'nullable|date',
            ]);
        } else {
            $request->validate([
                'circle_id' => 'required|exists:circles,id',
                'date' => 'required|date',
                'records' => 'required|array',
            ]);

            $circle = $teacher->circles()->where('circles.id', $request->circle_id)->first();
            if (! $circle) {
                return response()->json([
                    'message' => 'غير مصرح لك بالوصول لهذه الحلقة.',
                ], 403);
            }
        }

        $syncedAttendances = [];

        DB::transaction(function () use ($request, $teacher, $isSequential, &$syncedAttendances) {
            if ($isSequential) {
                foreach ($request->input('records') as $record) {
                    $studentId = $record['student_id'];
                    $status = $record['status'];
                    $date = $record['date'];
                    $clientUpdatedAt = isset($record['updated_at']) ? Carbon::parse($record['updated_at']) : null;

                    $student = Student::find($studentId);
                    if (! $student) {
                        continue;
                    }

                    $circle = $teacher->circles()->where('circles.id', $student->circle_id)->first();
                    if (! $circle) {
                        continue;
                    }

                    $existing = Attendance::where('student_id', $studentId)
                        ->whereDate('date', $date)
                        ->first();

                    if ($existing) {
                        if ($clientUpdatedAt && $existing->updated_at->gt($clientUpdatedAt)) {
                            $syncedAttendances[] = $existing;

                            continue;
                        }

                        $existing->update([
                            'teacher_id' => $teacher->id,
                            'circle_id' => $circle->id,
                            'status' => $status,
                        ]);
                    } else {
                        $existing = Attendance::create([
                            'student_id' => $studentId,
                            'date' => $date,
                            'teacher_id' => $teacher->id,
                            'circle_id' => $circle->id,
                            'status' => $status,
                        ]);
                    }

                    GamificationService::syncStudentAttendanceXP($existing);
                    $syncedAttendances[] = $existing;
                }
            } else {
                $circle = $teacher->circles()->where('circles.id', $request->circle_id)->first();

                foreach ($request->records as $studentId => $status) {
                    if (! in_array($status, ['present', 'absent', 'late', 'excused'])) {
                        continue;
                    }

                    $student = Student::where('id', $studentId)->where('circle_id', $circle->id)->first();
                    if (! $student) {
                        continue;
                    }

                    $existing = Attendance::where('student_id', $studentId)
                        ->whereDate('date', $request->date)
                        ->first();

                    if ($existing) {
                        $existing->update([
                            'teacher_id' => $teacher->id,
                            'circle_id' => $circle->id,
                            'status' => $status,
                        ]);
                    } else {
                        $existing = Attendance::create([
                            'student_id' => $studentId,
                            'date' => $request->date,
                            'teacher_id' => $teacher->id,
                            'circle_id' => $circle->id,
                            'status' => $status,
                        ]);
                    }

                    GamificationService::syncStudentAttendanceXP($existing);
                    $syncedAttendances[] = $existing;
                }
            }
        });

        return response()->json([
            'message' => 'تم تسجيل حضور الطلاب بنجاح.',
            'synced_attendances' => AttendanceResource::collection($syncedAttendances),
        ]);
    }
}
