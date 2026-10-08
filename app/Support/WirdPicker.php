<?php

namespace App\Support;

/**
 * Which plan day ("ورد") is due next for one part — hifz or review — of one
 * plan: the teacher app's pickWird() (src/domain/wird.ts), word for word, so
 * the student's page names the same portion the teacher grades on the phone.
 * Keep the two in step.
 *
 * The portion follows what was actually recited rather than the calendar:
 * a student who fell short owes the rest, carried into the next portion; one
 * who recited more keeps the next portion unless they covered it whole.
 *
 * A range is ['from' => ['surah' => s, 'verse' => v], 'to' => [...]].
 */
final class WirdPicker
{
    /** The verses of each surah, al-Fatihah first. */
    private const VERSES = [7, 286, 200, 176, 120, 165, 206, 75, 129, 109, 123, 111, 43, 52, 99, 128, 111, 110, 98, 135, 112, 78, 118, 64, 77, 227, 93, 88, 69, 60, 34, 30, 73, 54, 45, 83, 182, 88, 75, 85, 54, 53, 89, 59, 37, 35, 38, 29, 18, 45, 60, 49, 62, 55, 78, 96, 29, 22, 24, 13, 14, 11, 11, 18, 12, 12, 30, 52, 52, 44, 28, 28, 20, 56, 40, 31, 50, 40, 46, 42, 29, 19, 36, 25, 22, 17, 19, 26, 30, 20, 15, 21, 11, 8, 8, 19, 5, 8, 8, 11, 11, 8, 3, 9, 5, 4, 7, 3, 6, 3, 5, 4, 5, 6];

    /**
     * @param  array<int, array{id: int, position: int, range: ?array}>  $days
     * @param  array<int, array{day_id: int, grade: ?int, recited: ?array, graded_on: ?string, graded_at: ?string}>  $marks
     * @return array{day_id: int, state: string, range?: array}|null
     */
    public static function pick(array $days, array $marks, string $onDate): ?array
    {
        $ordered = $days;
        usort($ordered, fn (array $a, array $b) => $a['position'] <=> $b['position']);

        $firstWithRange = self::first($ordered, fn (array $day) => $day['range'] !== null);

        if ($firstWithRange === null) {
            return null;
        }

        $byId = [];
        foreach ($ordered as $day) {
            $byId[$day['id']] = $day;
        }

        $graded = [];
        foreach ($marks as $mark) {
            if (isset($byId[$mark['day_id']])) {
                $graded[] = ['day' => $byId[$mark['day_id']], 'cell' => $mark];
            }
        }

        // Ties settle the same way whatever order the sessions arrive in.
        usort($graded, fn (array $a, array $b) => ($a['day']['position'] <=> $b['day']['position'])
            ?: strcmp($a['cell']['graded_on'] ?? '', $b['cell']['graded_on'] ?? ''));

        $pinned = null;
        $last = null;

        foreach ($graded as $entry) {
            $grade = $entry['cell']['grade'];
            $gradedOn = $entry['cell']['graded_on'];

            if ($gradedOn === $onDate && $grade !== null && ($pinned === null || self::markedAfter($entry, $pinned))) {
                $pinned = $entry;
            }

            if (self::isRecited($grade) && ($gradedOn === null || $gradedOn < $onDate) && ($last === null || self::gradedAfter($entry, $last))) {
                $last = $entry;
            }
        }

        if ($pinned !== null) {
            return ['day_id' => $pinned['day']['id'], 'state' => 'pinned'];
        }

        if ($last === null) {
            return ['day_id' => $firstWithRange['id'], 'state' => 'start'];
        }

        // With no day holding the last recited ayah, the graded day counts as done.
        $target = $last['day'];
        $advance = true;
        $ayah = ($last['cell']['recited'] ?? $last['day']['range'])['to'] ?? null;
        $containing = $ayah === null ? null : self::containingDay($ordered, $last['day'], $ayah);

        if ($ayah !== null && $containing !== null && $containing['range'] !== null) {
            $target = $containing;
            $advance = self::sameAyah($containing['range']['to'], $ayah);
        }

        if (! $advance) {
            return self::owedPortion($ordered, $last['day'], $target, $ayah)
                ?? ['day_id' => $target['id'], 'state' => 'progress'];
        }

        $next = self::first($ordered, fn (array $day) => $day['position'] > $target['position'] && $day['range'] !== null);

        return $next === null
            ? ['day_id' => $target['id'], 'state' => 'finished']
            : ['day_id' => $next['id'], 'state' => 'progress'];
    }

    /**
     * Which way the plan runs: from a day that spans surahs, or from one day to
     * the next where they change surah — from an-Nas when the surahs count down.
     * A plan inside one surah throughout runs forward; within a surah the verses
     * always do.
     *
     * @param  array<int, array{id: int, position: int, range: ?array}>  $days
     */
    public static function planDirection(array $days): string
    {
        $previous = null;

        foreach ($days as $day) {
            $range = $day['range'];

            if ($range === null) {
                continue;
            }

            if ($range['from']['surah'] !== $range['to']['surah']) {
                return $range['from']['surah'] > $range['to']['surah'] ? 'backward' : 'forward';
            }

            if ($previous !== null && $previous['to']['surah'] !== $range['from']['surah']) {
                return $range['from']['surah'] < $previous['to']['surah'] ? 'backward' : 'forward';
            }

            $previous = $range;
        }

        return 'forward';
    }

    /**
     * A student who stopped short of the end of the day they were graded on owes
     * the rest, carried into the next day's portion: from the ayah after the last
     * recited to the end of the next day's own portion (or of the graded day's,
     * when it is the plan's last). Null when the portions do not run on from
     * each other, and the day's own portion is shown instead.
     */
    private static function owedPortion(array $ordered, array $graded, array $holding, array $ayah): ?array
    {
        if ($holding['position'] > $graded['position']) {
            return null;
        }

        $next = self::first($ordered, fn (array $day) => $day['position'] > $graded['position'] && $day['range'] !== null);
        $opens = $next ?? $graded;
        $chain = array_values(array_filter($ordered, fn (array $day) => $day['range'] !== null
            && $day['position'] >= $holding['position'] && $day['position'] <= $opens['position']));

        // The plan's own way, read off the whole plan: two days inside one surah
        // run on by the next verse whichever way the plan goes.
        $direction = self::planDirection($ordered);

        for ($i = 1; $i < count($chain); $i++) {
            $next = self::following($chain[$i - 1]['range']['to'], $direction);

            if ($next === null || ! self::sameAyah($next, $chain[$i]['range']['from'])) {
                return null;
            }
        }

        $from = self::following($ayah, $direction);

        if ($from === null) {
            return null;
        }

        return ['day_id' => $opens['id'], 'state' => 'progress', 'range' => ['from' => $from, 'to' => $opens['range']['to']]];
    }

    /**
     * The ayah recited after this one: the next verse of its surah, else the
     * first of the surah after it — the next one on in a forward plan, the one
     * before in a plan that runs back from an-Nas. Null past the mushaf's end.
     */
    private static function following(array $ayah, string $direction): ?array
    {
        if ($ayah['verse'] < (self::VERSES[$ayah['surah'] - 1] ?? 0)) {
            return ['surah' => $ayah['surah'], 'verse' => $ayah['verse'] + 1];
        }

        $surah = $direction === 'forward' ? $ayah['surah'] + 1 : $ayah['surah'] - 1;

        return $surah >= 1 && $surah <= 114 ? ['surah' => $surah, 'verse' => 1] : null;
    }

    /**
     * The day whose range holds the ayah: the graded day itself when it does —
     * review plans repeat portions — then the nearest day after it, then the
     * nearest day before it.
     */
    private static function containingDay(array $ordered, array $own, array $ayah): ?array
    {
        $holds = fn (array $day) => $day['range'] !== null && self::rangeContains($day['range'], $ayah);

        if ($holds($own)) {
            return $own;
        }

        $later = self::first($ordered, fn (array $day) => $day['position'] > $own['position'] && $holds($day));

        if ($later !== null) {
            return $later;
        }

        for ($i = count($ordered) - 1; $i >= 0; $i--) {
            if ($ordered[$i]['position'] < $own['position'] && $holds($ordered[$i])) {
                return $ordered[$i];
            }
        }

        return null;
    }

    /**
     * Whether the ayah falls inside the range. A reverse range covers the tail
     * of its first surah, every surah between, and the head of its last one.
     */
    public static function rangeContains(array $range, array $ayah): bool
    {
        [$from, $to] = [$range['from'], $range['to']];

        if ($from['surah'] < $to['surah']) {
            return self::compare($ayah, $from) >= 0 && self::compare($ayah, $to) <= 0;
        }

        if ($from['surah'] > $to['surah']) {
            if ($ayah['surah'] < $to['surah'] || $ayah['surah'] > $from['surah']) {
                return false;
            }

            if ($ayah['surah'] === $from['surah']) {
                return $ayah['verse'] >= $from['verse'];
            }

            if ($ayah['surah'] === $to['surah']) {
                return $ayah['verse'] <= $to['verse'];
            }

            return true;
        }

        return $ayah['surah'] === $from['surah']
            && $ayah['verse'] >= min($from['verse'], $to['verse'])
            && $ayah['verse'] <= max($from['verse'], $to['verse']);
    }

    /** Whether a was marked after b — decides what stays pinned on the viewed date. */
    private static function markedAfter(array $a, array $b): bool
    {
        $atA = $a['cell']['graded_at'] ?? '';
        $atB = $b['cell']['graded_at'] ?? '';

        return $atA !== $atB ? strcmp($atA, $atB) > 0 : $a['day']['position'] > $b['day']['position'];
    }

    /** Whether a was recited after b: by day, then the further portion, then the time. */
    private static function gradedAfter(array $a, array $b): bool
    {
        $onA = $a['cell']['graded_on'] ?? '';
        $onB = $b['cell']['graded_on'] ?? '';

        if ($onA !== $onB) {
            return strcmp($onA, $onB) > 0;
        }

        if ($a['day']['position'] !== $b['day']['position']) {
            return $a['day']['position'] > $b['day']['position'];
        }

        return strcmp($a['cell']['graded_at'] ?? '', $b['cell']['graded_at'] ?? '') > 0;
    }

    /** Any grade but «لم يسمع» (0), and not ungraded. */
    private static function isRecited(?int $grade): bool
    {
        return $grade !== null && $grade >= 1 && $grade <= 3;
    }

    private static function sameAyah(array $a, array $b): bool
    {
        return $a['surah'] === $b['surah'] && $a['verse'] === $b['verse'];
    }

    private static function compare(array $a, array $b): int
    {
        return $a['surah'] !== $b['surah'] ? $a['surah'] - $b['surah'] : $a['verse'] - $b['verse'];
    }

    /**
     * @template T
     *
     * @param  array<int, T>  $items
     * @return T|null
     */
    private static function first(array $items, callable $test): mixed
    {
        foreach ($items as $item) {
            if ($test($item)) {
                return $item;
            }
        }

        return null;
    }
}
