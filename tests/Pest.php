<?php

use App\Models\Ayah;
use App\Models\Circle;
use App\Models\Stage;
use App\Models\Surah;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * Open tasmeeh turn booking on a circle's stage every day of the week, as the
 * supervisor sets it: all day, or — with $openNow false — at hours already
 * past or still to come, so the booking card shows without a button.
 */
function openTurnBooking(Circle $circle, bool $openNow = true): Stage
{
    $stage = $circle->stage ?? Stage::factory()->create();

    if (! $circle->stage_id) {
        $circle->update(['stage_id' => $stage->id]);
    }

    $closed = Carbon\Carbon::now('Asia/Riyadh')->hour >= 1 ? ['00:00', '00:30'] : ['23:00', '23:30'];
    [$startsAt, $endsAt] = $openNow ? ['00:00', '23:59'] : $closed;

    $stage->update([
        'turn_booking_enabled' => true,
        'turn_booking_days' => [0, 1, 2, 3, 4, 5, 6],
        'turn_booking_starts_at' => $startsAt,
        'turn_booking_ends_at' => $endsAt,
    ]);

    return $stage;
}

/**
 * A plan day's portion, for both parts: al-Fatihah's first verse, created
 * the first time it is asked for. A day with no portion for a part owes
 * nothing for it, so a test that wants a day owed gives it one.
 *
 * @return array{from_ayah_id: int, to_ayah_id: int, review_from_ayah_id: int, review_to_ayah_id: int}
 */
function planDayPortion(): array
{
    Surah::firstOrCreate(['id' => 1], [
        'number' => 1, 'name_arabic' => 'الفاتحة', 'name_simple' => 'Al-Fatihah',
        'revelation_place' => 'makkah', 'revelation_order' => 1, 'verses_count' => 7,
        'start_page' => 1, 'end_page' => 1,
    ]);

    $ayah = Ayah::firstOrCreate(['id' => 1], [
        'surah_id' => 1, 'verse_number' => 1, 'page_number' => 1,
        'line_number_start' => 1, 'line_number_end' => 1, 'verse_key' => '1:1',
        'juz_number' => 1, 'hizb_number' => 1, 'rub_number' => 1, 'ruku_number' => 1,
        'manzil_number' => 1, 'text_uthmani' => 'آية',
    ])->id;

    return ['from_ayah_id' => $ayah, 'to_ayah_id' => $ayah, 'review_from_ayah_id' => $ayah, 'review_to_ayah_id' => $ayah];
}
