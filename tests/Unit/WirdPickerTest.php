<?php

use App\Support\WirdPicker;

/*
 * The teacher app's tests for pickWird() (src/domain/wird.test.ts), case for
 * case: the student's page must name the portion the teacher grades.
 */

function wirdRange(?string $key): ?array
{
    if ($key === null) {
        return null;
    }

    [$from, $to] = explode('-', $key);
    [$fs, $fv] = array_map('intval', explode(':', $from));
    [$ts, $tv] = array_map('intval', explode(':', $to));

    return ['from' => ['surah' => $fs, 'verse' => $fv], 'to' => ['surah' => $ts, 'verse' => $tv]];
}

function wirdDay(int $id, int $position, ?string $key): array
{
    return ['id' => $id, 'position' => $position, 'range' => wirdRange($key)];
}

function wirdMark(int $dayId, ?int $grade, ?string $gradedOn, ?string $gradedAt, ?string $recited = null): array
{
    return ['day_id' => $dayId, 'grade' => $grade, 'recited' => wirdRange($recited), 'graded_on' => $gradedOn, 'graded_at' => $gradedAt];
}

/** A forward hifz plan through النبإ and النازعات. */
function wirdHifz(): array
{
    return [wirdDay(11, 1, '78:1-78:20'), wirdDay(12, 2, '78:21-78:40'), wirdDay(13, 3, '79:1-79:20'), wirdDay(14, 4, '79:21-79:46')];
}

const WIRD_TODAY = '2026-10-04';

it('starts on the first portion when nothing has been graded', function () {
    expect(WirdPicker::pick(wirdHifz(), [], WIRD_TODAY))->toBe(['day_id' => 11, 'state' => 'start']);
});

it('ignores marks for days that are not in the plan', function () {
    expect(WirdPicker::pick(wirdHifz(), [wirdMark(99, 3, WIRD_TODAY, WIRD_TODAY.'T08:00:00Z')], WIRD_TODAY))
        ->toBe(['day_id' => 11, 'state' => 'start']);
});

it('carries what a student fell short of into the next day\'s portion', function () {
    expect(WirdPicker::pick(wirdHifz(), [wirdMark(11, 3, '2026-10-01', '2026-10-01T08:00:00Z', '78:1-78:10')], WIRD_TODAY))
        ->toBe(['day_id' => 12, 'state' => 'progress', 'range' => wirdRange('78:11-78:40')]);
});

it('moves to the next day once the portion is recited to its last ayah', function () {
    expect(WirdPicker::pick(wirdHifz(), [wirdMark(11, 2, '2026-10-01', '2026-10-01T08:00:00Z')], WIRD_TODAY))
        ->toBe(['day_id' => 12, 'state' => 'progress']);
    expect(WirdPicker::pick(wirdHifz(), [wirdMark(11, 1, '2026-10-01', '2026-10-01T08:00:00Z', '78:1-78:20')], WIRD_TODAY))
        ->toBe(['day_id' => 12, 'state' => 'progress']);
});

it('follows what was actually recited when the student went past the scheduled portion', function () {
    expect(WirdPicker::pick(wirdHifz(), [wirdMark(11, 3, '2026-10-01', '2026-10-01T08:00:00Z', '78:1-78:30')], WIRD_TODAY))
        ->toBe(['day_id' => 12, 'state' => 'progress']);
    expect(WirdPicker::pick(wirdHifz(), [wirdMark(11, 3, '2026-10-01', '2026-10-01T08:00:00Z', '78:1-78:40')], WIRD_TODAY))
        ->toBe(['day_id' => 13, 'state' => 'progress']);
});

it('never counts لم يسمع as progress, but pins it on the day it was given', function () {
    $marks = [wirdMark(11, 3, '2026-10-01', '2026-10-01T08:00:00Z'), wirdMark(12, 0, '2026-10-02', '2026-10-02T08:00:00Z')];

    expect(WirdPicker::pick(wirdHifz(), $marks, WIRD_TODAY))->toBe(['day_id' => 12, 'state' => 'progress']);
    expect(WirdPicker::pick(wirdHifz(), $marks, '2026-10-02'))->toBe(['day_id' => 12, 'state' => 'pinned']);
});

it('pins the latest grade given on the viewed date', function () {
    $t = WIRD_TODAY;

    expect(WirdPicker::pick(wirdHifz(), [wirdMark(11, 3, $t, "{$t}T08:00:00Z"), wirdMark(12, 2, $t, "{$t}T09:00:00Z")], $t))
        ->toBe(['day_id' => 12, 'state' => 'pinned']);
    expect(WirdPicker::pick(wirdHifz(), [wirdMark(11, 3, $t, "{$t}T10:00:00Z"), wirdMark(12, 2, $t, "{$t}T09:00:00Z")], $t))
        ->toBe(['day_id' => 11, 'state' => 'pinned']);
    expect(WirdPicker::pick(wirdHifz(), [wirdMark(11, 3, $t, "{$t}T09:00:00Z"), wirdMark(13, 2, $t, "{$t}T09:00:00Z")], $t))
        ->toBe(['day_id' => 13, 'state' => 'pinned']);
});

it('ignores grades given after a past date being viewed', function () {
    $marks = [wirdMark(11, 3, '2026-10-01', '2026-10-01T08:00:00Z'), wirdMark(12, 3, '2026-10-03', '2026-10-03T08:00:00Z')];

    expect(WirdPicker::pick(wirdHifz(), $marks, '2026-10-02'))->toBe(['day_id' => 12, 'state' => 'progress']);
    expect(WirdPicker::pick(wirdHifz(), $marks, WIRD_TODAY))->toBe(['day_id' => 13, 'state' => 'progress']);
});

it('treats a grade with no date as the oldest', function () {
    expect(WirdPicker::pick(wirdHifz(), [wirdMark(13, 3, null, null), wirdMark(11, 3, '2026-10-01', '2026-10-01T08:00:00Z')], WIRD_TODAY))
        ->toBe(['day_id' => 12, 'state' => 'progress']);
});

it('keeps a repeating review plan on its own day rather than the earlier day with the same portion', function () {
    $review = [
        wirdDay(1, 1, '114:1-101:11'), wirdDay(2, 2, '100:1-96:19'), wirdDay(3, 3, '95:1-90:20'), wirdDay(4, 4, '89:1-86:17'),
        wirdDay(5, 5, '85:1-82:19'), wirdDay(6, 6, '81:1-79:46'), wirdDay(7, 7, '78:1-78:40'), wirdDay(8, 8, '114:1-101:11'),
    ];
    $earlier = array_map(fn (array $d, int $i) => wirdMark($d['id'], 3, '2026-09-2'.($i + 1), '2026-09-2'.($i + 1).'T08:00:00Z'), array_slice($review, 0, 7), range(0, 6));

    expect(WirdPicker::pick($review, [...$earlier, wirdMark(8, 2, '2026-10-01', '2026-10-01T08:00:00Z', '114:1-105:5')], WIRD_TODAY))
        ->toBe(['day_id' => 8, 'state' => 'progress', 'range' => wirdRange('104:1-101:11')]);
    expect(WirdPicker::pick($review, [...$earlier, wirdMark(8, 2, '2026-10-01', '2026-10-01T08:00:00Z')], WIRD_TODAY))
        ->toBe(['day_id' => 8, 'state' => 'finished']);
});

it('finishes once the last portion is recited in full', function () {
    expect(WirdPicker::pick(wirdHifz(), [wirdMark(13, 3, '2026-10-01', '2026-10-01T08:00:00Z'), wirdMark(14, 3, '2026-10-02', '2026-10-02T08:00:00Z')], WIRD_TODAY))
        ->toBe(['day_id' => 14, 'state' => 'finished']);
});

it('moves past the graded day when no day holds the last recited ayah', function () {
    expect(WirdPicker::pick(wirdHifz(), [wirdMark(11, 3, '2026-10-01', '2026-10-01T08:00:00Z', '2:1-2:5')], WIRD_TODAY))
        ->toBe(['day_id' => 12, 'state' => 'progress']);
});

it('skips days without a portion for this part', function () {
    $gappy = [wirdDay(21, 1, null), wirdDay(22, 2, '78:1-78:20'), wirdDay(23, 3, null), wirdDay(24, 4, '78:21-78:40')];

    expect(WirdPicker::pick($gappy, [], WIRD_TODAY))->toBe(['day_id' => 22, 'state' => 'start']);
    expect(WirdPicker::pick($gappy, [wirdMark(22, 3, '2026-10-01', '2026-10-01T08:00:00Z')], WIRD_TODAY))
        ->toBe(['day_id' => 24, 'state' => 'progress']);
});

it('returns nothing when no day has a portion for this part', function () {
    expect(WirdPicker::pick([wirdDay(1, 1, null), wirdDay(2, 2, null)], [], WIRD_TODAY))->toBeNull();
    expect(WirdPicker::pick([], [], WIRD_TODAY))->toBeNull();
});

it('moves on from the further portion of two recited in one session, whichever was graded first', function () {
    $days = [wirdDay(1, 0, '78:1-78:5'), wirdDay(2, 1, '78:6-78:10'), wirdDay(3, 2, '78:11-78:15')];
    $marks = [wirdMark(2, 3, '2026-10-03', '2026-10-03T07:02:00Z'), wirdMark(1, 3, '2026-10-03', '2026-10-03T07:05:00Z')];

    expect(WirdPicker::pick($days, $marks, WIRD_TODAY))->toBe(['day_id' => 3, 'state' => 'progress']);
});

it('keeps a portion marked «لم يسمع» due until a later session recites it', function () {
    $notHeard = [wirdMark(11, 0, '2026-10-01', '2026-10-01T08:00:00Z')];
    $recitedNext = [...$notHeard, wirdMark(11, 3, '2026-10-02', '2026-10-02T08:00:00Z')];

    expect(WirdPicker::pick(wirdHifz(), $notHeard, '2026-10-02'))->toBe(['day_id' => 11, 'state' => 'start']);
    expect(WirdPicker::pick(wirdHifz(), $recitedNext, '2026-10-02'))->toBe(['day_id' => 11, 'state' => 'pinned']);
    expect(WirdPicker::pick(wirdHifz(), $recitedNext, WIRD_TODAY))->toBe(['day_id' => 12, 'state' => 'progress']);
});

it('follows the latest session of a portion recited in parts', function () {
    $marks = [wirdMark(11, 3, '2026-10-01', '2026-10-01T08:00:00Z', '78:1-78:10'), wirdMark(11, 2, '2026-10-02', '2026-10-02T08:00:00Z', '78:11-78:20')];

    expect(WirdPicker::pick(wirdHifz(), array_slice($marks, 0, 1), '2026-10-02'))
        ->toBe(['day_id' => 12, 'state' => 'progress', 'range' => wirdRange('78:11-78:40')]);
    expect(WirdPicker::pick(wirdHifz(), $marks, WIRD_TODAY))->toBe(['day_id' => 12, 'state' => 'progress']);
});

describe('a portion recited short or long', function () {
    $on = fn (int $dayId, string $recited) => wirdMark($dayId, 3, '2026-10-01', '2026-10-01T08:00:00Z', $recited);

    it('moves on as planned once the carried portion is recited whole', function () use ($on) {
        expect(WirdPicker::pick(wirdHifz(), [$on(12, '78:11-78:40')], WIRD_TODAY))->toBe(['day_id' => 13, 'state' => 'progress']);
    });

    it('carries a shortfall again, across the end of a surah', function () use ($on) {
        expect(WirdPicker::pick(wirdHifz(), [$on(12, '78:11-78:30')], WIRD_TODAY))
            ->toBe(['day_id' => 13, 'state' => 'progress', 'range' => wirdRange('78:31-79:20')]);
    });

    it('carries everything owed when the student recited back into an earlier portion', function () use ($on) {
        expect(WirdPicker::pick(wirdHifz(), [$on(12, '78:5-78:9')], WIRD_TODAY))
            ->toBe(['day_id' => 13, 'state' => 'progress', 'range' => wirdRange('78:10-79:20')]);
    });

    it('keeps the next portion as it is after reciting into it, and skips it once covered whole', function () use ($on) {
        expect(WirdPicker::pick(wirdHifz(), [$on(11, '78:1-78:30')], WIRD_TODAY))->toBe(['day_id' => 12, 'state' => 'progress']);
        expect(WirdPicker::pick(wirdHifz(), [$on(11, '78:1-79:5')], WIRD_TODAY))->toBe(['day_id' => 13, 'state' => 'progress']);
    });

    it('carries a shortfall in a plan that runs back from an-Nas', function () use ($on) {
        expect(WirdPicker::pick([wirdDay(1, 1, '114:1-112:4'), wirdDay(2, 2, '111:1-109:6')], [$on(1, '114:1-113:3')], WIRD_TODAY))
            ->toBe(['day_id' => 2, 'state' => 'progress', 'range' => wirdRange('113:4-109:6')]);
    });

    it('shows the day\'s own portion again when the portions do not run on from each other', function () use ($on) {
        expect(WirdPicker::pick([wirdDay(1, 1, '2:1-2:10'), wirdDay(2, 2, '3:1-3:10')], [$on(1, '2:1-2:5')], WIRD_TODAY))
            ->toBe(['day_id' => 1, 'state' => 'progress']);
    });

    it('opens what is owed at the surah before, not the one after, in a plan from an-Nas', function () use ($on) {
        expect(WirdPicker::pick([wirdDay(1, 1, '3:191-2:5'), wirdDay(2, 2, '2:6-2:15')], [$on(1, '3:191-3:200')], WIRD_TODAY))
            ->toBe(['day_id' => 2, 'state' => 'progress', 'range' => wirdRange('2:1-2:15')]);
    });

    it('carries across days inside a surah and across its end alike', function () use ($on) {
        $days = [wirdDay(1, 1, '3:181-3:190'), wirdDay(2, 2, '3:191-3:200'), wirdDay(3, 3, '2:1-2:10')];

        expect(WirdPicker::pick($days, [$on(2, '3:181-3:185')], WIRD_TODAY))
            ->toBe(['day_id' => 3, 'state' => 'progress', 'range' => wirdRange('3:186-2:10')]);
    });

    it('opens the surah after in a plan from al-Fatihah', function () use ($on) {
        expect(WirdPicker::pick([wirdDay(1, 1, '2:280-3:5'), wirdDay(2, 2, '3:6-3:15')], [$on(1, '2:280-2:286')], WIRD_TODAY))
            ->toBe(['day_id' => 2, 'state' => 'progress', 'range' => wirdRange('3:1-3:15')]);
    });
});

describe('planDirection', function () {
    it('reads the way from a day spanning surahs', function () {
        expect(WirdPicker::planDirection([wirdDay(1, 1, '2:1-2:10'), wirdDay(2, 2, '3:191-2:5')]))->toBe('backward');
        expect(WirdPicker::planDirection([wirdDay(1, 1, '2:280-3:5')]))->toBe('forward');
    });

    it('reads the way from one day to the next where the surah changes', function () {
        expect(WirdPicker::planDirection([wirdDay(1, 1, '114:1-114:6'), wirdDay(2, 2, '113:1-113:5')]))->toBe('backward');
        expect(WirdPicker::planDirection([wirdDay(1, 1, '1:1-1:7'), wirdDay(2, 2, '2:1-2:5')]))->toBe('forward');
    });

    it('runs forward inside one surah throughout', function () {
        expect(WirdPicker::planDirection([wirdDay(1, 1, '2:1-2:10'), wirdDay(2, 2, '2:11-2:20')]))->toBe('forward');
    });
});
