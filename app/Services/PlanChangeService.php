<?php

namespace App\Services;

use App\Models\Ayah;
use App\Models\Student;
use App\Models\StudentPlan;
use App\Models\Teacher;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Quran plans written on the teacher's phone, saved as the phone built them.
 *
 * The app lays a plan out with a port of the site's own engine, so the days
 * are stored as they come: checked, never worked out again. A plan made by a
 * teacher is approved at once, as it is on the web.
 *
 * Another plan may have been made for the student — on the web, or by the
 * student — while the phone was offline. The phone's plan is still saved, and
 * the reply names the plans made since the phone last synced, for the app to
 * tell the teacher. Resending a plan the server already holds reports it as
 * applied, so a dropped connection never saves it twice.
 */
class PlanChangeService
{
    /** The plan wizard's names for the days of the week. */
    public const DAY_NAMES = [
        'Sunday' => 'الأحد',
        'Monday' => 'الاثنين',
        'Tuesday' => 'الثلاثاء',
        'Wednesday' => 'الأربعاء',
        'Thursday' => 'الخميس',
        'Friday' => 'الجمعة',
        'Saturday' => 'السبت',
    ];

    /** Which parts of a day each kind of plan schedules. */
    private const PARTS = [
        'hifz' => ['hifz'],
        'review' => ['review'],
        'hifz_review' => ['hifz', 'review'],
    ];

    /**
     * Apply a batch of plans, each on its own: one refused plan never holds
     * back the rest.
     *
     * @param  array<int, array<string, mixed>>  $changes
     * @return array<int, array<string, mixed>>
     */
    public static function apply(Teacher $teacher, array $changes): array
    {
        $changes = collect($changes);
        $students = Student::whereIn('id', $changes->pluck('student_id')->unique())
            ->whereIn('circle_id', $teacher->circles()->pluck('circles.id'))
            ->whereRoleState(fn ($q) => $q->where('is_approved', true))
            ->get()
            ->keyBy('id');

        // Every ayah, keyed "surah:verse": cheaper read whole than asked for
        // one by one across a batch of plans.
        $ayahIds = Ayah::query()->get(['id', 'surah_id', 'verse_number'])
            ->mapWithKeys(fn (Ayah $ayah) => ["{$ayah->surah_id}:{$ayah->verse_number}" => $ayah->id]);

        return $changes
            ->map(fn (array $change) => ['id' => $change['id']] + self::applyOne($teacher, $change, $students, $ayahIds))
            ->all();
    }

    /**
     * @param  array<string, mixed>  $change
     * @param  Collection<int, Student>  $students
     * @param  Collection<string, int>  $ayahIds
     * @return array<string, mixed>
     */
    private static function applyOne(Teacher $teacher, array $change, Collection $students, Collection $ayahIds): array
    {
        $student = $students->get($change['student_id']);

        if (! $student) {
            return self::rejected('student_unavailable', 'لم يعد هذا الطالب ضمن حلقاتك.');
        }

        $days = self::days($change, $ayahIds);

        if (is_string($days)) {
            return self::rejected('invalid_plan', $days);
        }

        try {
            return Cache::lock(self::studentLockKey($student->id), 10)->block(5, fn () => DB::transaction(
                fn () => self::create($teacher, $student, $change, $days),
            ));
        } catch (LockTimeoutException) {
            return self::rejected('busy', 'تُحفظ خطة أخرى لهذا الطالب الآن من جهاز آخر، ستُعاد المحاولة تلقائياً.');
        }
    }

    /**
     * @param  array<string, mixed>  $change
     * @param  list<array<string, mixed>>  $days
     * @return array<string, mixed>
     */
    private static function create(Teacher $teacher, Student $student, array $change, array $days): array
    {
        // The change id names the plan it created, so a resend finds it.
        $existing = StudentPlan::where('uuid', $change['id'])->first();

        if ($existing) {
            return (int) $existing->student_id === $student->id
                ? self::applied($existing, $change['synced_at'])
                : self::rejected('invalid_plan', 'تعذّر حفظ هذه الخطة، فأنشئها من جديد.');
        }

        $plan = StudentPlan::create([
            'uuid' => $change['id'],
            'student_id' => $student->id,
            'teacher_id' => $teacher->id,
            'start_date' => $change['start_date'],
            'days_count' => count($days),
            'active_days' => array_values($change['active_days']),
            'description' => $change['description'] ?? null,
            'plan_type' => $change['plan_type'],
            'direction' => $change['direction'],
            'review_direction' => $change['review_direction'],
            'status' => 'active',
            'is_approved' => true,
            'created_by_role' => 'teacher',
        ]);

        $plan->days()->createMany($days);

        // The new plan takes over, as it does when the web wizard is asked to.
        if ($change['deactivate_previous']) {
            StudentPlan::where('student_id', $student->id)
                ->whereKeyNot($plan->id)
                ->where('status', 'active')
                ->update(['status' => 'inactive']);
        }

        return self::applied($plan, $change['synced_at']);
    }

    /**
     * The plan saved, with the student's plans made after the phone last
     * synced and before this one — the ones its teacher has not seen.
     *
     * @return array<string, mixed>
     */
    private static function applied(StudentPlan $plan, string $syncedAt): array
    {
        $since = CarbonImmutable::parse($syncedAt)->setTimezone(config('app.timezone'));

        $meanwhile = StudentPlan::where('student_id', $plan->student_id)
            ->where('id', '<', $plan->id)
            ->where('created_at', '>', $since)
            ->orderBy('id')
            ->get()
            ->map(fn (StudentPlan $other) => [
                'id' => $other->id,
                'plan_type' => $other->plan_type,
                'start_date' => $other->start_date->toDateString(),
                'created_by_role' => $other->created_by_role,
                'status' => $other->status,
            ])
            ->all();

        return ['result' => 'applied', 'plan_id' => $plan->id, 'created_meanwhile' => $meanwhile];
    }

    /**
     * The plan's days as they are stored, or why they cannot be: dates in
     * order from the plan's start, and every ayah the plan's parts name one
     * the mushaf has.
     *
     * @param  array<string, mixed>  $change
     * @param  Collection<string, int>  $ayahIds
     * @return list<array<string, mixed>>|string
     */
    private static function days(array $change, Collection $ayahIds): array|string
    {
        $parts = self::PARTS[$change['plan_type']];
        $previous = null;
        $days = [];

        foreach ($change['days'] as $index => $day) {
            $date = $day['date'];

            if ($date < ($previous ?? $change['start_date']) || $date === $previous) {
                return 'أيام الخطة غير مرتبة، أو يسبق أحدها بداية الخطة.';
            }

            $row = ['date' => $date, 'day_name' => self::DAY_NAMES[CarbonImmutable::parse($date)->format('l')]];

            foreach (['hifz' => ['from_ayah_id', 'to_ayah_id'], 'review' => ['review_from_ayah_id', 'review_to_ayah_id']] as $part => [$fromColumn, $toColumn]) {
                $from = in_array($part, $parts, true) ? self::ayahId($day[$part]['from'] ?? null, $ayahIds) : null;
                $to = in_array($part, $parts, true) ? self::ayahId($day[$part]['to'] ?? null, $ayahIds) : null;

                if (in_array($part, $parts, true) && ($from === null || $to === null)) {
                    return 'في اليوم '.($index + 1).' من الخطة آية غير موجودة في المصحف.';
                }

                $row[$fromColumn] = $from;
                $row[$toColumn] = $to;
            }

            $days[] = $row;
            $previous = $date;
        }

        return $days;
    }

    /**
     * @param  Collection<string, int>  $ayahIds
     */
    private static function ayahId(mixed $ayah, Collection $ayahIds): ?int
    {
        if (! is_array($ayah) || ! is_int($ayah['surah'] ?? null) || ! is_int($ayah['verse'] ?? null)) {
            return null;
        }

        return $ayahIds->get("{$ayah['surah']}:{$ayah['verse']}");
    }

    /**
     * Held while a student's plans are written from the app, so two phones
     * sending plans for one student at once cannot each switch the other's off
     * half-way.
     */
    public static function studentLockKey(int $studentId): string
    {
        return "student-plans:{$studentId}";
    }

    /**
     * @return array{result: string, code: string, message: string}
     */
    private static function rejected(string $code, string $message): array
    {
        return ['result' => 'rejected', 'code' => $code, 'message' => $message];
    }
}
