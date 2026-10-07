<?php

namespace App\Services;

use App\Models\Circle;
use App\Models\PeerPair;
use App\Models\PlanDayAttempt;
use App\Models\Student;
use App\Models\StudentPlanDay;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Mutual recitation: the students of a circle present on a day, paired so each
 * recites their pending review to a classmate who has memorised it.
 *
 * A pair is mutual when both have a review the other holds; when only one
 * does, they recite and the other listens. The pairs are kept, so every
 * teacher of the circle — on the site or the app — sees the same ones, swaps
 * students between them, counts each recitation's mistakes, and grades it:
 * the grade is the student's own for that review, written to the plan day's
 * session of the pair's date as the tasmeeh page writes it.
 */
class PeerPairing
{
    /**
     * Pair the circle's students present on the day, in place of the day's
     * pairs and what was recorded on them.
     *
     * @return Collection<int, PeerPair>
     */
    public static function generate(Circle $circle, string $date, ?int $createdBy): Collection
    {
        $profiles = self::presentStudents($circle->id, $date)
            ->mapWithKeys(fn (Student $student) => [$student->id => self::profile($student)])
            ->filter()
            ->all();

        $pairs = self::match($profiles);

        DB::transaction(function () use ($circle, $date, $createdBy, $pairs) {
            PeerPair::where('circle_id', $circle->id)->where('date', $date)->delete();

            foreach ($pairs as $position => [$first, $second, $mutual]) {
                PeerPair::create([
                    'circle_id' => $circle->id,
                    'date' => $date,
                    'position' => $position + 1,
                    'mutual' => $mutual,
                    'first_id' => $first['student']->id,
                    'first_day_id' => $first['day_id'],
                    'first_from_ayah_id' => $first['portion'][0] ?? null,
                    'first_to_ayah_id' => $first['portion'][1] ?? null,
                    'second_id' => $second['student']->id,
                    'second_day_id' => $mutual ? $second['day_id'] : null,
                    'second_from_ayah_id' => $mutual ? ($second['portion'][0] ?? null) : null,
                    'second_to_ayah_id' => $mutual ? ($second['portion'][1] ?? null) : null,
                    'created_by' => $createdBy,
                ]);
            }
        });

        return self::forDay($circle->id, $date);
    }

    /**
     * Move a student into another's place: two paired students change places,
     * an unpaired one takes the place of a paired one, and two unpaired
     * students become a pair. What was recorded on a place moved goes.
     *
     * @return Collection<int, PeerPair>
     */
    public static function swap(Circle $circle, string $date, int $studentId, int $withId): Collection
    {
        $pairs = self::forDay($circle->id, $date);
        $own = $pairs->first(fn (PeerPair $pair) => $pair->placeOf($studentId) !== null);
        $other = $pairs->first(fn (PeerPair $pair) => $pair->placeOf($withId) !== null);

        DB::transaction(function () use ($circle, $date, $pairs, $studentId, $withId, $own, $other) {
            if ($own && $other) {
                $ownPlace = $own->placeOf($studentId);
                $otherPlace = $other->placeOf($withId);
                $moving = self::takePlace($own, $ownPlace);

                self::putPlace($own, $ownPlace, self::takePlace($other, $otherPlace));
                self::putPlace($other->is($own) ? $own : $other, $otherPlace, $moving);
            } elseif ($own || $other) {
                [$pair, $place, $incoming] = $own
                    ? [$own, $own->placeOf($studentId), $withId]
                    : [$other, $other->placeOf($withId), $studentId];

                self::putPlace($pair, $place, self::freshPlace(Student::findOrFail($incoming)));
            } else {
                self::putPlace($pair = new PeerPair([
                    'circle_id' => $circle->id,
                    'date' => $date,
                    'position' => ($pairs->max('position') ?? 0) + 1,
                    'mutual' => false,
                ]), 'first', self::freshPlace(Student::findOrFail($studentId)));
                self::putPlace($pair, 'second', self::freshPlace(Student::findOrFail($withId)));
            }

            foreach (array_filter([$own, $other, $pair ?? null]) as $changed) {
                self::settle($changed);
                $changed->save();
            }
        });

        return self::forDay($circle->id, $date);
    }

    /** The mistakes counted in a student's recitation. A listener recites nothing. */
    public static function recordMistakes(PeerPair $pair, string $place, ?int $mistakes): PeerPair
    {
        if ($pair->recites($place)) {
            $pair->update(["{$place}_mistakes" => $mistakes]);
        }

        return $pair;
    }

    /**
     * Grade a student's recitation: their own grade for the review, written to
     * the plan day's session of the pair's date — as the tasmeeh page writes
     * it, points and all. A range recorded for that session stays. False when
     * the place recites no plan day, or the day is being written elsewhere.
     */
    public static function grade(PeerPair $pair, string $place, ?int $grade, int $teacherId): bool
    {
        $dayId = $pair->{"{$place}_day_id"};

        if (! $pair->recites($place) || ! $dayId) {
            return false;
        }

        $today = TeacherSyncSnapshot::today();
        $date = min($pair->date, $today);

        try {
            return Cache::lock(TasmeehChangeService::dayLockKey($dayId), 10)->block(5, function () use ($dayId, $date, $today, $grade, $teacherId) {
                $day = StudentPlanDay::with('plan.student')->find($dayId);

                if (! $day) {
                    return false;
                }

                PlanDayAttempts::adopt($day, 'review');
                $attempt = PlanDayAttempts::on($day, 'review', $date);
                $recited = $attempt ? TasmeehSnapshot::withoutScheduled($day, 'review', $attempt->recitedAyahIds()) : null;

                PlanDayAttempts::record($day, 'review', $date, $today, $grade, $recited, $teacherId, $attempt);

                return true;
            });
        } catch (LockTimeoutException) {
            return false;
        }
    }

    /**
     * The grade each reciting place holds for its review on the pair's date,
     * keyed "pair id:place".
     *
     * @param  Collection<int, PeerPair>  $pairs
     * @return array<string, ?int>
     */
    public static function grades(Collection $pairs): array
    {
        $dayIds = $pairs->flatMap(fn (PeerPair $pair) => [$pair->first_day_id, $pair->second_day_id])->filter()->unique();

        if ($dayIds->isEmpty()) {
            return [];
        }

        $sessions = PlanDayAttempt::whereIn('student_plan_day_id', $dayIds)
            ->where('part', 'review')
            ->get()
            ->groupBy(fn (PlanDayAttempt $attempt) => $attempt->student_plan_day_id.'|'.substr((string) $attempt->recited_on, 0, 10));

        $grades = [];

        foreach ($pairs as $pair) {
            foreach (PeerPair::PLACES as $place) {
                $dayId = $pair->{"{$place}_day_id"};

                if ($dayId && $pair->recites($place)) {
                    $grades["{$pair->id}:{$place}"] = $sessions->get("{$dayId}|{$pair->date}")?->first()?->grade;
                }
            }
        }

        return $grades;
    }

    /**
     * The day's pairs of some circles, in order.
     *
     * @param  int|array<int, int>  $circleIds
     * @return Collection<int, PeerPair>
     */
    public static function forDay(int|array $circleIds, string $date): Collection
    {
        return PeerPair::whereIn('circle_id', (array) $circleIds)
            ->where('date', $date)
            ->with(['first:id,name', 'second:id,name', 'firstFromAyah.surah', 'firstToAyah.surah', 'secondFromAyah.surah', 'secondToAyah.surah'])
            ->orderBy('circle_id')
            ->orderBy('position')
            ->get();
    }

    /**
     * The circle's students present or late on the day.
     *
     * @return Collection<int, Student>
     */
    public static function presentStudents(int $circleId, string $date): Collection
    {
        return Student::where('circle_id', $circleId)
            ->whereHas('attendances', fn ($query) => $query->whereDate('date', $date)->whereIn('status', ['present', 'late']))
            ->orderBy('name')
            ->get();
    }

    /**
     * Present students in no pair, each with why: no memorisation on record to
     * pair by, or no classmate holding their review.
     *
     * @param  Collection<int, PeerPair>  $pairs
     * @return Collection<int, array{student: Student, reason: string}>
     */
    public static function unpaired(int $circleId, string $date, Collection $pairs): Collection
    {
        $paired = $pairs->flatMap(fn (PeerPair $pair) => [$pair->first_id, $pair->second_id])->all();

        return self::presentStudents($circleId, $date)
            ->reject(fn (Student $student) => in_array($student->id, $paired, true))
            ->map(fn (Student $student) => [
                'student' => $student,
                'reason' => $student->getMemorizedRange() ? 'لم يُوجد زميل يحفظ ورده' : 'ليس له سجل حفظ معتمد',
            ])
            ->values();
    }

    /**
     * What pairing reads of a student: the range they have memorised, and
     * the review they have pending — the oldest of their active plans not
     * recited yet. Null with nothing memorised on record to pair by.
     *
     * @return array{student: Student, day_id: ?int, portion: array{0: int, 1: int}|null, min: int, max: int}|null
     */
    public static function profile(Student $student): ?array
    {
        $memorised = $student->getMemorizedRange();

        if (! $memorised) {
            return null;
        }

        $day = StudentPlanDay::whereHas('plan', fn ($query) => $query
            ->where('student_id', $student->id)
            ->where('is_approved', true)
            ->where('status', 'active'))
            ->whereNotNull('review_from_ayah_id')
            ->whereNotNull('review_to_ayah_id')
            // A «لم يسمع» (0) was not recited, so that review is still pending.
            ->where(fn ($query) => $query->whereNull('review_achievement')->orWhere('review_achievement', 0))
            ->orderBy('date')
            ->first();

        $portion = $day ? [(int) $day->review_from_ayah_id, (int) $day->review_to_ayah_id] : null;

        return [
            'student' => $student,
            'day_id' => $day?->id,
            'portion' => $portion,
            // What a listener can follow: their memorisation, and the review
            // they are going over themselves.
            'min' => $portion ? min($memorised['min'], ...$portion) : $memorised['min'],
            'max' => $portion ? max($memorised['max'], ...$portion) : $memorised['max'],
        ];
    }

    /**
     * Pair the students: mutual pairs first — both having a review the other
     * holds — matched from the students with the fewest options, then one
     * reciting to another who holds their review. A student with no review
     * only ever listens, and two with none are not paired.
     *
     * @param  array<int, array{student: Student, portion: array{0: int, 1: int}|null, min: int, max: int}>  $profiles
     * @return array<int, array{0: array<string, mixed>, 1: array<string, mixed>, 2: bool}>
     */
    private static function match(array $profiles): array
    {
        $holds = fn (array $listener, array $reciter) => $reciter['portion'] !== null
            && min($reciter['portion']) >= $listener['min']
            && max($reciter['portion']) <= $listener['max'];

        $edges = array_fill_keys(array_keys($profiles), []);

        foreach ($profiles as $a => $first) {
            foreach ($profiles as $b => $second) {
                if ($a < $b && $holds($second, $first) && $holds($first, $second)) {
                    $edges[$a][] = $b;
                    $edges[$b][] = $a;
                }
            }
        }

        $degrees = array_map('count', $edges);
        asort($degrees);
        $matched = [];
        $pairs = [];

        foreach ($degrees as $id => $degree) {
            if ($degree === 0 || isset($matched[$id])) {
                continue;
            }

            $partner = collect($edges[$id])
                ->reject(fn (int $candidate) => isset($matched[$candidate]))
                ->sortBy(fn (int $candidate) => $degrees[$candidate])
                ->first();

            if ($partner !== null) {
                $matched[$id] = $matched[$partner] = true;
                $pairs[] = [$profiles[$id], $profiles[$partner], true];
            }
        }

        // The rest, the most memorised first: they can listen to the most.
        $rest = collect($profiles)
            ->reject(fn (array $profile, int $id) => isset($matched[$id]))
            ->sortByDesc(fn (array $profile) => $profile['max'] - $profile['min'])
            ->keys()
            ->all();

        foreach ($rest as $a) {
            foreach ($rest as $b) {
                if ($a === $b || isset($matched[$a]) || isset($matched[$b])) {
                    continue;
                }

                if ($holds($profiles[$b], $profiles[$a])) {
                    $matched[$a] = $matched[$b] = true;
                    $pairs[] = [$profiles[$a], $profiles[$b], false];
                } elseif ($holds($profiles[$a], $profiles[$b])) {
                    $matched[$a] = $matched[$b] = true;
                    $pairs[] = [$profiles[$b], $profiles[$a], false];
                }
            }
        }

        return $pairs;
    }

    /**
     * A student newly placed in a pair, with the review they have pending.
     *
     * @return array{id: int, day: ?int, from: ?int, to: ?int, mistakes: null}
     */
    private static function freshPlace(Student $student): array
    {
        $profile = self::profile($student);
        $portion = $profile['portion'] ?? null;

        return ['id' => $student->id, 'day' => $profile['day_id'] ?? null, 'from' => $portion[0] ?? null, 'to' => $portion[1] ?? null, 'mistakes' => null];
    }

    /**
     * A student leaving a place takes their portion along; the mistakes
     * counted stay behind with the place. A grade given is the student's own
     * and stays on their plan day.
     *
     * @return array{id: int, day: ?int, from: ?int, to: ?int, mistakes: null}
     */
    private static function takePlace(PeerPair $pair, string $place): array
    {
        return [
            'id' => $pair->{"{$place}_id"},
            'day' => $pair->{"{$place}_day_id"},
            'from' => $pair->{"{$place}_from_ayah_id"},
            'to' => $pair->{"{$place}_to_ayah_id"},
            'mistakes' => null,
        ];
    }

    /**
     * @param  array{id: int, day: ?int, from: ?int, to: ?int, mistakes: ?int}  $student
     */
    private static function putPlace(PeerPair $pair, string $place, array $student): void
    {
        $pair->fill([
            "{$place}_id" => $student['id'],
            "{$place}_day_id" => $student['day'],
            "{$place}_from_ayah_id" => $student['from'],
            "{$place}_to_ayah_id" => $student['to'],
            "{$place}_mistakes" => $student['mistakes'],
        ]);
    }

    /**
     * After a swap the pair recites as its students can: each one with a
     * review does, the one reciting comes first, and a listener's own review
     * is set aside until they are paired to recite it.
     */
    private static function settle(PeerPair $pair): void
    {
        $firstRecites = $pair->first_from_ayah_id !== null;
        $secondRecites = $pair->second_from_ayah_id !== null;

        if (! $firstRecites && $secondRecites) {
            $first = self::takePlace($pair, 'first');
            self::putPlace($pair, 'first', self::takePlace($pair, 'second'));
            self::putPlace($pair, 'second', $first);
        }

        $pair->mutual = $firstRecites && $secondRecites;
    }
}
