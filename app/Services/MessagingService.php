<?php

namespace App\Services;

use App\Models\Circle;
use App\Models\Guardian;
use App\Models\Manager;
use App\Models\Student;
use App\Models\Supervisor;
use App\Models\Teacher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class MessagingService
{
    public const ROLE_LABELS = [
        'student' => 'طالب',
        'teacher' => 'معلم',
        'supervisor' => 'مشرف',
        'guardian' => 'ولي أمر',
        'manager' => 'مدير',
    ];

    public const MODELS = [
        'student' => Student::class,
        'teacher' => Teacher::class,
        'supervisor' => Supervisor::class,
        'guardian' => Guardian::class,
        'manager' => Manager::class,
    ];

    protected const GUARDS = ['student', 'teacher', 'supervisor', 'guardian', 'manager'];

    /**
     * Resolve the currently authenticated participant across all five guards.
     *
     * @return array{type: string, id: int, model: Model}|null
     */
    public static function currentParticipant(): ?array
    {
        foreach (self::GUARDS as $guard) {
            if (Auth::guard($guard)->check()) {
                $user = Auth::guard($guard)->user();

                return ['type' => $guard, 'id' => $user->id, 'model' => $user];
            }
        }

        return null;
    }

    public static function resolveModel(string $type, int $id): ?Model
    {
        $class = self::MODELS[$type] ?? null;

        return $class ? $class::find($id) : null;
    }

    /**
     * Search every role's directory (except the searcher themself) by name, for
     * starting a new conversation. Restricted to people the searcher is actually
     * allowed to message — see isAllowedToMessage(). Returns a plain array,
     * newest-name-match first.
     *
     * @return array<int, array{type: string, id: int, name: string, role_label: string}>
     */
    public static function searchDirectory(string $term, string $excludeType, int $excludeId, int $limit = 15): array
    {
        $results = collect();

        foreach (self::MODELS as $type => $class) {
            $query = $class::query();

            if ($term !== '') {
                $query->where('name', 'like', "%{$term}%");
            }

            if ($type === $excludeType) {
                $query->where('id', '!=', $excludeId);
            }

            $query->limit($limit * 3)->get(['id', 'name'])
                ->filter(fn ($record) => self::isAllowedToMessage($excludeType, $excludeId, $type, $record->id))
                ->each(function ($record) use (&$results, $type) {
                    $results->push([
                        'type' => $type,
                        'id' => $record->id,
                        'name' => $record->name,
                        'role_label' => self::ROLE_LABELS[$type],
                    ]);
                });
        }

        return $results->take($limit)->values()->all();
    }

    /**
     * Whether two participants are allowed to message each other, based on real
     * organizational relationships (shared circle, guardian/child, supervision
     * scope) rather than free-for-all access. Managers can message — and be
     * messaged by — everyone, since they hold overall administrative oversight.
     */
    public static function isAllowedToMessage(string $typeA, int $idA, string $typeB, int $idB): bool
    {
        if ($typeA === $typeB && $idA === $idB) {
            return false;
        }

        if ($typeA === 'manager' || $typeB === 'manager') {
            return true;
        }

        // Normalize so the pair is checked in a single, consistent order.
        [$typeA, $idA, $typeB, $idB] = $typeA <= $typeB ? [$typeA, $idA, $typeB, $idB] : [$typeB, $idB, $typeA, $idA];

        return match ("{$typeA}:{$typeB}") {
            'guardian:student' => self::guardianOwnsStudent($idA, $idB),
            'guardian:teacher' => self::circlesOverlap(self::guardianCircleIds($idA), self::teacherCircleIds($idB)),
            'guardian:supervisor' => self::circlesOverlap(self::guardianCircleIds($idA), self::supervisorCircleIds($idB)),
            'student:teacher' => in_array(self::studentCircleId($idA), self::teacherCircleIds($idB), true),
            'student:supervisor' => in_array(self::studentCircleId($idA), self::supervisorCircleIds($idB), true),
            'supervisor:teacher' => self::circlesOverlap(self::supervisorCircleIds($idA), self::teacherCircleIds($idB)),
            default => false, // student:student, teacher:teacher, guardian:guardian, supervisor:supervisor
        };
    }

    private static function guardianOwnsStudent(int $guardianId, int $studentId): bool
    {
        return Student::where('id', $studentId)->where('guardian_id', $guardianId)->exists();
    }

    private static function studentCircleId(int $studentId): ?int
    {
        return Student::find($studentId)?->circle_id;
    }

    /** @return array<int, int> */
    private static function teacherCircleIds(int $teacherId): array
    {
        return Teacher::find($teacherId)?->circles()->pluck('circles.id')->all() ?? [];
    }

    /** @return array<int, int> */
    private static function guardianCircleIds(int $guardianId): array
    {
        return Guardian::find($guardianId)?->students()->pluck('circle_id')->filter()->unique()->values()->all() ?? [];
    }

    /** @return array<int, int> */
    private static function supervisorCircleIds(int $supervisorId): array
    {
        $supervisor = Supervisor::find($supervisorId);

        if (! $supervisor) {
            return [];
        }

        $stageIds = $supervisor->stages()->pluck('stages.id');

        return Circle::whereIn('stage_id', $stageIds)->pluck('id')->all();
    }

    /**
     * @param  array<int, int>  $a
     * @param  array<int, int>  $b
     */
    private static function circlesOverlap(array $a, array $b): bool
    {
        return count(array_intersect($a, $b)) > 0;
    }

    /**
     * All conversation ids the given participant belongs to.
     */
    public static function conversationIdsFor(string $type, int $id): array
    {
        return DB::table('conversation_participants')
            ->where('participant_type', $type)
            ->where('participant_id', $id)
            ->pluck('conversation_id')
            ->all();
    }

    public static function unreadCountFor(string $type, int $id): int
    {
        $participantRows = DB::table('conversation_participants')
            ->where('participant_type', $type)
            ->where('participant_id', $id)
            ->get(['conversation_id', 'last_read_at']);

        $total = 0;
        foreach ($participantRows as $row) {
            $total += DB::table('messages')
                ->where('conversation_id', $row->conversation_id)
                ->where(function ($q) use ($type, $id) {
                    $q->where('sender_type', '!=', $type)->orWhere('sender_id', '!=', $id);
                })
                ->when($row->last_read_at, fn ($q) => $q->where('created_at', '>', $row->last_read_at))
                ->count();
        }

        return $total;
    }
}
