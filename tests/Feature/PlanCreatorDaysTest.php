<?php

use App\Models\Ayah;
use App\Models\Circle;
use App\Models\Student;
use App\Models\StudentPlan;
use App\Models\Surah;
use App\Models\Teacher;
use Carbon\Carbon;
use Livewire\Livewire;

/*
 * Wednesday 8 July 2026. The last three surahs, each on a page of its own;
 * a plan from الناس back, on Sundays, Mondays and Tuesdays from the 12th.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-07-08 10:00:00');

    foreach ([112 => 4, 113 => 5, 114 => 6] as $surahId => $verses) {
        Surah::create([
            'id' => $surahId, 'number' => $surahId, 'name_arabic' => 'سورة '.$surahId, 'name_simple' => 'Surah '.$surahId,
            'revelation_place' => 'makkah', 'revelation_order' => $surahId, 'verses_count' => $verses,
            'start_page' => $surahId, 'end_page' => $surahId,
        ]);

        foreach (range(1, $verses) as $verse) {
            Ayah::create([
                'surah_id' => $surahId, 'verse_number' => $verse, 'page_number' => $surahId,
                'line_number_start' => $verse, 'line_number_end' => $verse, 'verse_key' => "{$surahId}:{$verse}",
                'juz_number' => 30, 'hizb_number' => 60, 'rub_number' => 240, 'ruku_number' => 1,
                'manzil_number' => 7, 'text_uthmani' => 'آية',
            ]);
        }
    }

    $this->circle = Circle::factory()->create();
    $this->teacher = Teacher::factory()->create();
    $this->teacher->circles()->attach($this->circle->id);
    $this->student = Student::factory()->create(['circle_id' => $this->circle->id]);

    $this->actingAs($this->teacher, 'teacher');
});

/** Two days of hifz, a surah each, reviewed all the way back. */
function twoDayPlan()
{
    return Livewire::test('shared.plan-creator')
        ->set('studentId', test()->student->id)
        ->set('planType', 'hifz_review')
        ->set('fillDirection', 'reverse')
        ->set('reviewDirection', 'reverse')
        ->set('startDate', '2026-07-12')
        ->set('daysCount', 2)
        ->set('activeDays', ['Sunday', 'Monday', 'Tuesday'])
        ->set('bulkStartSurah', 114)
        ->set('bulkStartVerse', 1)
        ->call('generateDays')
        ->call('fillSelected', 'surah', 'hifz', [0, 1])
        ->call('fillSelected', 'all_previous', 'review', [0, 1]);
}

/** A day's dates and ranges, as [date, hifz, review], each range "surah:verse-surah:verse" or null. */
function planDayShape(array $day): array
{
    $range = fn (string $prefix) => $day["{$prefix}_surah_id"] === null ? null
        : "{$day["{$prefix}_surah_id"]}:{$day["{$prefix}_verse"]}-".$day[str_replace('from', 'to', $prefix).'_surah_id'].':'.$day[str_replace('from', 'to', $prefix).'_verse'];

    return [$day['date'], $range('from'), $range('review_from')];
}

it('adds a day of hifz alone, carrying on with the surah a day it was filled with', function () {
    $plan = twoDayPlan()->call('addDay', 'hifz');

    expect(planDayShape($plan->get('planDays')[2]))->toBe(['2026-07-14', '112:1-112:4', null])
        ->and($plan->get('daysCount'))->toBe(3);
});

it('adds a day of review alone, reviewing up to where the hifz then stands, on the next day the plan meets', function () {
    $plan = twoDayPlan()->call('addDay', 'review');

    // Tuesday is the next day the plan meets; the hifz stood at الفلق's end.
    expect(planDayShape($plan->get('planDays')[2]))->toBe(['2026-07-14', null, '114:1-113:5']);
});

it('adds a day of both, the review running up to that day\'s hifz', function () {
    $plan = twoDayPlan()->call('addDay', 'both');

    expect(planDayShape($plan->get('planDays')[2]))->toBe(['2026-07-14', '112:1-112:4', '114:1-113:5']);
});

it('takes away the last day, the last hifz or the last review', function () {
    $plan = twoDayPlan()->call('addDay', 'both');

    // The last hifz goes: its day keeps its review.
    $plan->call('removeLast', 'hifz');
    expect(planDayShape($plan->get('planDays')[2]))->toBe(['2026-07-14', null, '114:1-113:5']);

    // The last review now goes from a day of review alone: the day goes with it.
    $plan->call('removeLast', 'review');
    expect($plan->get('planDays'))->toHaveCount(2)
        ->and($plan->get('daysCount'))->toBe(2);

    $plan->call('removeLast', 'day');
    expect($plan->get('planDays'))->toHaveCount(1);

    // The last one stays.
    $plan->call('removeLast', 'day');
    expect($plan->get('planDays'))->toHaveCount(1);
});

it('moves the days after one left empty up into its place', function () {
    $plan = twoDayPlan()->call('addDay', 'review')->call('addDay', 'review');

    // Monday's hifz goes, and Monday keeps its review: no day moves.
    $plan->call('removeLast', 'hifz');
    expect(array_column($plan->get('planDays'), 'date'))->toBe(['2026-07-12', '2026-07-13', '2026-07-14', '2026-07-19']);

    // A day of hifz alone in the middle, emptied, takes the days after it up a step.
    $days = $plan->get('planDays');
    $days[1]['review_from_surah_id'] = $days[1]['review_from_verse'] = $days[1]['review_to_surah_id'] = $days[1]['review_to_verse'] = null;
    $days[1]['from_surah_id'] = 113;
    $days[1]['from_verse'] = 1;
    $days[1]['to_surah_id'] = 113;
    $days[1]['to_verse'] = 5;
    $plan->set('planDays', $days)->call('removeLast', 'hifz');

    expect(array_column($plan->get('planDays'), 'date'))->toBe(['2026-07-12', '2026-07-13', '2026-07-14']);
});

it('saves the days of one part with the other left empty, and counts them all', function () {
    twoDayPlan()->call('addDay', 'review')->call('save')->assertHasNoErrors();

    $plan = StudentPlan::with('days')->sole();

    expect($plan->days_count)->toBe(3)
        ->and($plan->days->sortBy('date')->last()->only(['from_ayah_id', 'to_ayah_id']))->toBe(['from_ayah_id' => null, 'to_ayah_id' => null])
        ->and($plan->days->sortBy('date')->last()->review_to_ayah_id)->not->toBeNull();
});

it('keeps a day already graded when a plan is changed', function () {
    twoDayPlan()->call('save');
    $plan = StudentPlan::sole();
    $plan->days()->orderByDesc('date')->first()->update(['hifz_achievement' => 3]);

    $edit = Livewire::withQueryParams(['edit' => $plan->id])->test('shared.plan-creator')
        ->call('removeLast', 'day')
        ->assertDispatched('toast-show');

    expect($edit->get('planDays'))->toHaveCount(2);
});

it('shows a day without one part as such, and how many days each part has', function () {
    twoDayPlan()->call('addDay', 'review')
        ->assertSee('لا حفظ في هذا اليوم')
        ->assertSeeHtml('data-hifz-days>2<')
        ->assertSeeHtml('data-review-days>3<');
});
