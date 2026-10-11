<?php

namespace App\Ai\Tools;

use App\Models\Guardian;
use App\Models\Student;
use App\Models\Supervisor;
use App\Models\Teacher;
use App\Models\User;
use App\Support\ArabicSearch;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class getPeopleDirectory implements Tool
{
    private const MAX_LIMIT = 300;

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'List the people registered in the academy: students (طلاب), teachers (معلمون), supervisors (مشرفون) or guardians (أولياء أمور). '
            .'Returns names, contact details, and the circles/stages each person belongs to. '
            .'Filter with "search" (part of a name or email), "circle", "stage", or "status" (students only). '
            .'For a deep report about one single student use getStudentProfile instead.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $role = (string) (($request['role'] ?? null) ?: 'student');
        $limit = min(max((int) (($request['limit'] ?? null) ?: 100), 1), self::MAX_LIMIT);

        $people = match ($role) {
            'teacher' => $this->teachers($request, $limit),
            'supervisor' => $this->supervisors($request, $limit),
            'guardian' => $this->guardians($request, $limit),
            default => $this->students($request, $limit),
        };

        return json_encode([
            'role' => $role,
            'returned' => count($people),
            'note' => count($people) === $limit
                ? 'The result was capped at the limit; narrow the filters or raise "limit" to see more.'
                : null,
            'people' => $people,
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function students(Request $request, int $limit): array
    {
        $query = Student::with(['circle.stage', 'stage', 'guardian:id,name,phone']);

        $this->applySearch($query, $request);

        if ($circle = ($request['circle'] ?? null)) {
            $query->whereHas('circle', fn ($q) => ArabicSearch::where($q, 'name', $circle));
        }

        if ($stage = ($request['stage'] ?? null)) {
            // A circle's stage always wins; a student's own stage counts only without a circle.
            $query->where(function ($q) use ($stage) {
                $q->whereHas('circle.stage', fn ($s) => ArabicSearch::where($s, 'name', $stage))
                    ->orWhere(fn ($own) => $own->whereNull('circle_id')->whereHas('stage', fn ($s) => ArabicSearch::where($s, 'name', $stage)));
            });
        }

        if ($status = ($request['status'] ?? null)) {
            $query->where('status', $this->statusKey($status));
        }

        return $query->orderBy('name')->limit($limit)->get()->map(fn (Student $student) => [
            'name' => $student->name,
            'email' => $student->email,
            'phone' => $student->phone,
            'circle' => $student->circle?->name,
            'stage' => $student->circle?->stage?->name ?? $student->stage?->name,
            'guardian' => $student->guardian?->name,
            'guardian_phone' => $student->guardian?->phone,
            'status' => $this->statusLabel($student->status),
            'joined_at' => $student->joined_at?->format('Y-m-d'),
            'is_approved' => $student->is_approved,
        ])->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function teachers(Request $request, int $limit): array
    {
        $query = Teacher::with('circles.stage');

        $this->applySearch($query, $request);

        if ($circle = ($request['circle'] ?? null)) {
            $query->whereHas('circles', fn ($q) => ArabicSearch::where($q, 'name', $circle));
        }

        if ($stage = ($request['stage'] ?? null)) {
            $query->whereHas('circles.stage', fn ($q) => ArabicSearch::where($q, 'name', $stage));
        }

        return $query->orderBy('name')->limit($limit)->get()->map(fn (Teacher $teacher) => [
            'name' => $teacher->name,
            'email' => $teacher->email,
            'phone' => $teacher->phone,
            'circles' => $teacher->circles->pluck('name')->all(),
            'stages' => $teacher->circles->pluck('stage.name')->filter()->unique()->values()->all(),
            'is_approved' => $teacher->is_approved,
        ])->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function supervisors(Request $request, int $limit): array
    {
        $query = Supervisor::with('stages');

        $this->applySearch($query, $request);

        if ($stage = ($request['stage'] ?? null)) {
            $query->whereHas('stages', fn ($q) => ArabicSearch::where($q, 'name', $stage));
        }

        return $query->orderBy('name')->limit($limit)->get()->map(fn (Supervisor $supervisor) => [
            'name' => $supervisor->name,
            'email' => $supervisor->email,
            'phone' => $supervisor->phone,
            'stages' => $supervisor->stages->pluck('name')->all(),
            'is_approved' => $supervisor->is_approved,
        ])->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function guardians(Request $request, int $limit): array
    {
        $query = Guardian::with(['students:id,name,guardian_id,circle_id', 'students.circle:id,name']);

        $this->applySearch($query, $request);

        return $query->orderBy('name')->limit($limit)->get()->map(fn (Guardian $guardian) => [
            'name' => $guardian->name,
            'email' => $guardian->email,
            'phone' => $guardian->phone,
            'children' => $guardian->students->map(fn ($student) => [
                'name' => $student->name,
                'circle' => $student->circle?->name,
            ])->all(),
            'is_approved' => $guardian->is_approved,
        ])->all();
    }

    /**
     * @param  Builder<User>  $query
     */
    private function applySearch(Builder $query, Request $request): void
    {
        if ($search = ($request['search'] ?? null)) {
            $query->where(function ($q) use ($search) {
                ArabicSearch::where($q, 'name', $search);
                ArabicSearch::where($q, 'email', $search, 'or');
            });
        }
    }

    private function statusKey(string $status): string
    {
        return match ($status) {
            'مشارك' => 'active',
            'تحت التسجيل' => 'registering',
            'موقوف' => 'suspended',
            'غادر الحلقات' => 'left',
            default => $status,
        };
    }

    private function statusLabel(?string $status): ?string
    {
        return match ($status) {
            'active' => 'مشارك',
            'registering' => 'تحت التسجيل',
            'suspended' => 'موقوف',
            'left' => 'غادر الحلقات',
            default => $status,
        };
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'role' => $schema->string()->enum(['student', 'teacher', 'supervisor', 'guardian'])->required(),
            'search' => $schema->string()->description('Part of a name or email to search for.'),
            'circle' => $schema->string()->description('Circle name (حلقة). Students and teachers only.'),
            'stage' => $schema->string()->description('Stage name (مرحلة). Not applicable to guardians.'),
            'status' => $schema->string()
                ->enum(['active', 'registering', 'suspended', 'left'])
                ->description('Student status: active=مشارك, registering=تحت التسجيل, suspended=موقوف, left=غادر الحلقات.'),
            'limit' => $schema->integer()->description('Maximum people to return. Defaults to 100, maximum 300.'),
        ];
    }
}
