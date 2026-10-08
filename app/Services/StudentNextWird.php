<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Ayah;
use App\Models\FreeRecitation;
use App\Models\LeaderboardScore;
use App\Models\Student;
use App\Models\StudentPlan;
use App\Models\StudentPlanDay;
use App\Support\AyahIndex;
use App\Support\HijriDate;
use App\Support\WirdPicker;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * What a student owes next, and what they did last — read the way the teacher
 * app reads it, so the student's page names the portion their teacher grades.
 *
 * The plans, their days and every session they were recited in come from the
 * very snapshot the app syncs (TasmeehSnapshot), and the portion due is picked
 * by the app's own rule (WirdPicker): a shortfall carried into the next
 * portion, a portion covered whole skipped.
 */
final class StudentNextWird
{
    public const PART_LABELS = ['hifz' => 'الحفظ', 'review' => 'المراجعة'];

    public const PLAN_LABELS = ['hifz' => 'خطة الحفظ', 'review' => 'خطة المراجعة', 'hifz_review' => 'خطة الحفظ والمراجعة'];

    /**
     * The portion each part of each active plan asks for next. A plan recited
     * to its end asks nothing.
     *
     * @return Collection<int, array{plan: StudentPlan, part: string, day: StudentPlanDay, range: ?string, carried: bool, carried_range: ?array}>
     */
    public static function due(Student $student): Collection
    {
        $today = TeacherSyncSnapshot::today();
        // Asked as of the day after today: every session up to today counts as
        // done, so a portion graded today gives way to the next one.
        $asOf = CarbonImmutable::parse($today)->addDay()->toDateString();

        $read = self::read($student, $today);
        $picks = collect();

        foreach ($read['plans'] as $plan) {
            $planDays = $read['days']->where('plan_id', $plan->id);
            $dayIds = $planDays->pluck('id')->flip();

            foreach (TasmeehSnapshot::PARTS[$plan->plan_type] as $part) {
                $pick = WirdPicker::pick(
                    $planDays->map(fn (array $day) => ['id' => $day['id'], 'position' => $day['position'], 'range' => $day[$part]])->values()->all(),
                    $read['sessions']
                        ->filter(fn (array $session) => $session['part'] === $part && $dayIds->has($session['day_id']))
                        ->map(fn (array $session) => [
                            'day_id' => $session['day_id'],
                            'grade' => $session['grade'],
                            'recited' => $session['recited'],
                            'graded_on' => $session['date'],
                            'graded_at' => $session['graded_at'],
                        ])->values()->all(),
                    $asOf,
                );

                if ($pick === null || $pick['state'] === 'finished') {
                    continue;
                }

                $picks->push(['plan' => $plan, 'part' => $part, 'pick' => $pick]);
            }
        }

        $days = StudentPlanDay::with(['fromAyah.surah', 'toAyah.surah', 'reviewFromAyah.surah', 'reviewToAyah.surah'])
            ->findMany($picks->pluck('pick.day_id'))
            ->keyBy('id');

        return $picks
            ->filter(fn (array $entry) => $days->has($entry['pick']['day_id']))
            ->map(function (array $entry) use ($days) {
                $day = $days[$entry['pick']['day_id']];
                $carried = $entry['pick']['range'] ?? null;

                return [
                    'plan' => $entry['plan'],
                    'part' => $entry['part'],
                    'day' => $day,
                    'range' => $carried ? self::formatRange($carried) : $day->formatRange($entry['part']),
                    'carried' => $carried !== null,
                    'carried_range' => $carried,
                ];
            })
            ->values();
    }

    /**
     * The due portions as plan days, for the pages that list a student's
     * missions: the day picked, its part in `pendingPart`, its plan loaded —
     * and when what is owed was carried into it, the carried range in place
     * of the day's own. In memory only: these days are never saved.
     *
     * @return array<int, StudentPlanDay>
     */
    public static function missions(Student $student): array
    {
        $due = self::due($student);

        $pairs = $due->pluck('carried_range')->filter()->flatMap(fn (array $range) => [$range['from'], $range['to']]);
        $ayahs = Ayah::with('surah')
            ->findMany($pairs->map(fn (array $pair) => AyahIndex::id($pair['surah'], $pair['verse']))->filter()->unique())
            ->keyBy('id');
        $ayah = fn (array $pair) => $ayahs->get(AyahIndex::id($pair['surah'], $pair['verse']));

        return $due->map(function (array $item) use ($ayah) {
            $day = clone $item['day'];
            $day->setRelation('plan', $item['plan']);
            $day->pendingPart = $item['part'];

            if ($range = $item['carried_range']) {
                [$fromKey, $toKey, $fromRelation, $toRelation] = $item['part'] === 'review'
                    ? ['review_from_ayah_id', 'review_to_ayah_id', 'reviewFromAyah', 'reviewToAyah']
                    : ['from_ayah_id', 'to_ayah_id', 'fromAyah', 'toAyah'];

                $from = $ayah($range['from']);
                $to = $ayah($range['to']);

                $day->{$fromKey} = $from?->id;
                $day->{$toKey} = $to?->id;
                $day->setRelation($fromRelation, $from);
                $day->setRelation($toRelation, $to);
            }

            return $day;
        })->all();
    }

    /**
     * The last day the student was graded on, up to today: what they recited of
     * each part and how it was graded, whether they attended, and the criteria
     * they earned. Null before their first graded session.
     *
     * @return array{date: string, label: string, recitations: array<int, array{part: string, range: ?string, grade: int}>, attendance: ?string, criteria: array<int, string>}|null
     */
    public static function lastSession(Student $student): ?array
    {
        $today = TeacherSyncSnapshot::today();
        $read = self::read($student, $today);

        $graded = $read['sessions']->filter(fn (array $session) => $session['grade'] !== null && $session['date'] <= $today);
        $free = FreeRecitation::where('student_id', $student->id)
            ->whereNotNull('achievement')
            ->whereDate('recited_on', '<=', $today)
            ->orderByDesc('recited_on')
            ->first();

        $date = collect([$graded->max('date'), $free?->recited_on?->toDateString()])->filter()->max();

        if ($date === null) {
            return null;
        }

        $days = $read['days']->keyBy('id');
        $recitations = $graded->where('date', $date)
            ->sortBy(fn (array $session) => $session['part'] === 'hifz' ? 0 : 1)
            ->map(fn (array $session) => [
                'part' => $session['part'],
                'range' => self::formatRange($session['recited'] ?? $days[$session['day_id']][$session['part']] ?? null),
                'grade' => $session['grade'],
            ])
            ->values();

        $freeOnDay = FreeRecitation::where('student_id', $student->id)
            ->whereNotNull('achievement')
            ->whereDate('recited_on', $date)
            ->get()
            ->map(fn (FreeRecitation $recitation) => [
                'part' => $recitation->type,
                'range' => $recitation->formatRange(),
                'grade' => $recitation->achievement,
            ]);

        $status = Attendance::where('student_id', $student->id)->whereDate('date', $date)->value('status');

        return [
            'date' => $date,
            'label' => HijriDate::withWeekday($date),
            'recitations' => $recitations->concat($freeOnDay)->values()->all(),
            'attendance' => $status,
            'criteria' => LeaderboardScore::where('student_id', $student->id)
                ->whereDate('date', $date)
                ->with('criterion:id,name')
                ->get()
                ->pluck('criterion.name')
                ->filter()
                ->unique()
                ->values()
                ->all(),
        ];
    }

    /**
     * The student's active approved Quran plans, their days and every session
     * of them — the app's snapshot, read once per request.
     *
     * @return array{plans: Collection<int, StudentPlan>, days: Collection<int, array<string, mixed>>, sessions: Collection<int, array<string, mixed>>}
     */
    private static function read(Student $student, string $today): array
    {
        // Kept on the request: the card and the mission cards below it ask
        // alike, and a request — or a test — starts with nothing remembered.
        $request = request();
        $key = self::class."|{$student->id}|{$today}";

        if ($request->attributes->has($key)) {
            return $request->attributes->get($key);
        }

        $snapshot = TasmeehSnapshot::for([$student->id], $today, $today);
        $read = [
            'plans' => collect($snapshot['tasmeeh_plans'])->map(fn ($resource) => $resource->resource)->values(),
            'days' => collect($snapshot['tasmeeh_days'])->map(fn ($resource) => $resource->resolve($request))->values(),
            'sessions' => collect($snapshot['tasmeeh_attempts'])->map(fn ($resource) => $resource->resolve($request))->values(),
        ];

        $request->attributes->set($key, $read);

        return $read;
    }

    /**
     * A range of surah and verse pairs, as the student's page writes one.
     *
     * @param  array{from: array{surah: int, verse: int}, to: array{surah: int, verse: int}}|null  $range
     */
    private static function formatRange(?array $range): ?string
    {
        if ($range === null) {
            return null;
        }

        $ids = [
            AyahIndex::id($range['from']['surah'], $range['from']['verse']),
            AyahIndex::id($range['to']['surah'], $range['to']['verse']),
        ];
        $ayahs = Ayah::with('surah')->findMany(array_filter($ids))->keyBy('id');

        return StudentPlanDay::formatAyahRange($ayahs->get($ids[0]), $ayahs->get($ids[1]));
    }
}
