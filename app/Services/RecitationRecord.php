<?php

namespace App\Services;

use App\Models\Ayah;
use App\Models\StudentHadithAchievement;
use App\Models\StudentOdeAchievement;
use App\Models\StudentPlanDay;
use App\Models\Surah;
use App\Support\AyahIndex;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Every graded recitation of some students between two days, on the day it
 * was graded, whatever it came from: each session of a Quran plan — any plan,
 * running or not — each free recitation, and each hifz or review of a hadith
 * text (المتون) or an ode (المنظومات). «لم يسمع» is among them, as 0.
 *
 * Read the same for the teacher app's student card and the supervisor's
 * record of students, so the two count alike; a filter picks the hifz, the
 * review, the mutun or the odes.
 */
final class RecitationRecord
{
    /** What a record can be filtered by, as the pages name it. */
    public const FILTERS = ['hifz' => 'الحفظ', 'review' => 'المراجعة', 'hadith' => 'المتون', 'ode' => 'المنظومات'];

    /**
     * @param  array<int, int>  $studentIds
     * @param  array<int, int>  $exceptPlanIds  plans whose sessions the caller holds already
     * @param  string|null  $freeBefore  the day the caller's own free recitations start, if it holds some
     * @return Collection<int, array{student_id: int, date: string, kind: string, part: string, free: bool, range: ?array, label: ?string, grade: int, by: ?string}>
     */
    public static function between(array $studentIds, string $from, string $to, array $exceptPlanIds = [], ?string $freeBefore = null): Collection
    {
        if ($studentIds === []) {
            return collect();
        }

        $planned = DB::table('plan_day_attempts')
            ->join('student_plan_days', 'student_plan_days.id', '=', 'plan_day_attempts.student_plan_day_id')
            ->join('student_plans', 'student_plans.id', '=', 'student_plan_days.student_plan_id')
            ->leftJoin('users as recorders', 'recorders.id', '=', 'plan_day_attempts.recorded_by')
            ->whereIn('student_plans.student_id', $studentIds)
            ->when($exceptPlanIds !== [], fn ($query) => $query->whereNotIn('student_plans.id', $exceptPlanIds))
            ->whereNotNull('plan_day_attempts.grade')
            ->whereDate('plan_day_attempts.recited_on', '>=', $from)
            ->whereDate('plan_day_attempts.recited_on', '<=', $to)
            ->get([
                'student_plans.student_id', 'plan_day_attempts.part', 'plan_day_attempts.recited_on', 'plan_day_attempts.grade',
                'plan_day_attempts.recited_from_ayah_id', 'plan_day_attempts.recited_to_ayah_id',
                'student_plan_days.from_ayah_id', 'student_plan_days.to_ayah_id',
                'student_plan_days.review_from_ayah_id', 'student_plan_days.review_to_ayah_id', 'recorders.name as by',
            ])
            ->map(function (object $row) {
                $review = $row->part === 'review';
                $recited = $row->recited_from_ayah_id && $row->recited_to_ayah_id;

                return [
                    'student_id' => (int) $row->student_id,
                    'date' => substr((string) $row->recited_on, 0, 10),
                    'kind' => 'quran',
                    'part' => $row->part,
                    'free' => false,
                    'range' => $recited
                        ? AyahIndex::presentRange((int) $row->recited_from_ayah_id, (int) $row->recited_to_ayah_id)
                        : AyahIndex::presentRange(
                            (int) ($review ? $row->review_from_ayah_id : $row->from_ayah_id) ?: null,
                            (int) ($review ? $row->review_to_ayah_id : $row->to_ayah_id) ?: null,
                        ),
                    'label' => null,
                    'grade' => (int) $row->grade,
                    'by' => $row->by,
                ];
            });

        $free = DB::table('free_recitations')
            ->leftJoin('users as recorders', 'recorders.id', '=', 'free_recitations.recorded_by')
            ->whereIn('free_recitations.student_id', $studentIds)
            ->whereNotNull('free_recitations.achievement')
            ->whereDate('free_recitations.recited_on', '>=', $from)
            ->whereDate('free_recitations.recited_on', '<=', $to)
            ->when($freeBefore !== null, fn ($query) => $query->whereDate('free_recitations.recited_on', '<', $freeBefore))
            ->get(['free_recitations.student_id', 'free_recitations.type', 'free_recitations.recited_on', 'free_recitations.achievement', 'free_recitations.from_ayah_id', 'free_recitations.to_ayah_id', 'recorders.name as by'])
            ->map(fn (object $row) => [
                'student_id' => (int) $row->student_id,
                'date' => substr((string) $row->recited_on, 0, 10),
                'kind' => 'quran',
                'part' => $row->type,
                'free' => true,
                'range' => AyahIndex::presentRange($row->from_ayah_id ? (int) $row->from_ayah_id : null, $row->to_ayah_id ? (int) $row->to_ayah_id : null),
                'label' => null,
                'grade' => (int) $row->achievement,
                'by' => $row->by,
            ]);

        return $planned->concat($free)
            ->concat(self::mutun($studentIds, $from, $to))
            ->concat(self::odes($studentIds, $from, $to))
            ->sortBy('date')
            ->values();
    }

    /**
     * Whether a recitation is one the filter asks for: the Quran's hifz or
     * review — free ones too — the mutun, or the odes; anything for none.
     *
     * @param  array{kind: string, part: string}  $entry
     */
    public static function matches(array $entry, ?string $filter): bool
    {
        return match ($filter) {
            null, '' => true,
            'hadith', 'ode' => $entry['kind'] === $filter,
            default => $entry['kind'] === 'quran' && $entry['part'] === $filter,
        };
    }

    /**
     * What a recitation was, as a line of a day's list reads it: «المراجعة ·
     * تسميع حر», «الربط», «المتون · حفظ» — the app's very wording.
     *
     * @param  array{kind: string, part: string, free: bool}  $entry
     */
    public static function title(array $entry): string
    {
        if ($entry['kind'] !== 'quran') {
            return self::FILTERS[$entry['kind']].' · '.($entry['part'] === 'review' ? 'مراجعة' : 'حفظ');
        }

        $part = ['hifz' => 'الحفظ', 'review' => 'المراجعة', 'link' => 'الربط'][$entry['part']] ?? $entry['part'];

        return $part.($entry['free'] && $entry['part'] !== 'link' ? ' · تسميع حر' : '');
    }

    /**
     * The portion a recitation covered, written as teachers write it: the
     * Quran range (StudentPlanDay::formatAyahRange), or the mutun's or odes' label.
     *
     * @param  array{kind: string, range: ?array, label: ?string}  $entry
     */
    public static function text(array $entry): ?string
    {
        if ($entry['kind'] !== 'quran') {
            return $entry['label'];
        }

        if ($entry['range'] === null) {
            return null;
        }

        $surahs = once(fn () => Surah::get(['id', 'name_arabic', 'verses_count'])->keyBy('id'));
        $ayah = function (array $pair) use ($surahs): ?Ayah {
            $surah = $surahs->get($pair['surah']);

            return $surah ? (new Ayah(['verse_number' => $pair['verse']]))->setRelation('surah', $surah) : null;
        };

        return StudentPlanDay::formatAyahRange($ayah($entry['range']['from']), $ayah($entry['range']['to']));
    }

    /**
     * The hifz and review of the hadith texts graded between the days.
     *
     * @param  array<int, int>  $studentIds
     * @return Collection<int, array<string, mixed>>
     */
    private static function mutun(array $studentIds, string $from, string $to): Collection
    {
        $achievements = StudentHadithAchievement::query()
            ->whereHas('plan', fn ($plan) => $plan->whereIn('student_id', $studentIds))
            ->where(fn ($query) => $query->whereNotNull('hifz_achievement')->orWhereNotNull('review_achievement'))
            ->where(self::gradedAround($from, $to))
            ->with(['plan:id,student_id', 'pathDay.fromHadith:id,name', 'pathDay.toHadith:id,name', 'pathDay.reviewFromHadith:id,name', 'pathDay.reviewToHadith:id,name'])
            ->get();

        return self::parts($achievements, 'hadith', $from, $to, fn ($achievement, string $part) => $achievement->pathDay?->formatHadithRange($part));
    }

    /**
     * The hifz and review of the odes graded between the days.
     *
     * @param  array<int, int>  $studentIds
     * @return Collection<int, array<string, mixed>>
     */
    private static function odes(array $studentIds, string $from, string $to): Collection
    {
        $achievements = StudentOdeAchievement::query()
            ->whereHas('plan', fn ($plan) => $plan->whereIn('student_id', $studentIds))
            ->where(fn ($query) => $query->whereNotNull('hifz_achievement')->orWhereNotNull('review_achievement'))
            ->where(self::gradedAround($from, $to))
            ->with(['plan:id,student_id', 'pathDay.path.ode:id,name'])
            ->get();

        return self::parts($achievements, 'ode', $from, $to, function ($achievement, string $part) {
            $range = $achievement->pathDay?->formatOdeRange($part);
            $ode = $achievement->pathDay?->path?->ode?->name;

            return $range && $ode ? "{$ode}: {$range}" : ($range ?? $ode);
        });
    }

    /**
     * Achievements graded around the days — a day either side, as the time is
     * kept in UTC and read on Riyadh's day — or, graded before the time was
     * kept, on a path day among them. The exact day is settled in parts().
     */
    private static function gradedAround(string $from, string $to): \Closure
    {
        $start = CarbonImmutable::parse($from)->subDay()->toDateString();
        $end = CarbonImmutable::parse($to)->addDay()->toDateString();

        return fn ($query) => $query
            ->where(fn ($q) => $q->whereDate('hifz_graded_at', '>=', $start)->whereDate('hifz_graded_at', '<=', $end))
            ->orWhere(fn ($q) => $q->whereDate('review_graded_at', '>=', $start)->whereDate('review_graded_at', '<=', $end))
            ->orWhere(fn ($q) => $q->whereNull('hifz_graded_at')->whereNull('review_graded_at')
                ->whereHas('pathDay', fn ($day) => $day->whereDate('date', '>=', $from)->whereDate('date', '<=', $to)));
    }

    /**
     * Each graded part of the achievements, on the academy's day it was
     * graded — the path day's own date for one graded before the time was kept.
     *
     * @param  iterable<int, StudentHadithAchievement|StudentOdeAchievement>  $achievements
     * @return Collection<int, array<string, mixed>>
     */
    private static function parts(iterable $achievements, string $kind, string $from, string $to, callable $label): Collection
    {
        $entries = [];

        foreach ($achievements as $achievement) {
            foreach (['hifz', 'review'] as $part) {
                $grade = $achievement->{"{$part}_achievement"};

                if ($grade === null) {
                    continue;
                }

                $gradedAt = $achievement->{"{$part}_graded_at"};
                $date = $gradedAt
                    ? CarbonImmutable::parse($gradedAt)->setTimezone('Asia/Riyadh')->toDateString()
                    : $achievement->pathDay?->date?->toDateString();

                if ($date === null || $date < $from || $date > $to) {
                    continue;
                }

                $entries[] = [
                    'student_id' => (int) $achievement->plan->student_id,
                    'date' => $date,
                    'kind' => $kind,
                    'part' => $part,
                    'free' => false,
                    'range' => null,
                    'label' => $label($achievement, $part),
                    'grade' => (int) $grade,
                    'by' => null,
                ];
            }
        }

        return collect($entries);
    }
}
