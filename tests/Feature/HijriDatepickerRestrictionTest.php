<?php

use Livewire\Livewire;

/**
 * The picker is what a supervisor actually reads, so the rule the service
 * enforces has to be visible in it: an offered day must be tappable, and a day
 * outside the window must not be — struck through rather than merely refused
 * after the tap.
 */
beforeEach(function () {
    Carbon\Carbon::setTestNow('2026-09-19 08:00:00'); // Saturday
});

/**
 * The opening tag of one day's button. Fails rather than returning nothing when
 * the day is absent, so an assertion about it can never pass on an empty string.
 */
function dayCell(string $html, string $date): string
{
    preg_match('/<button[^>]*selectDate\(\''.preg_quote($date, '/').'\'\)[^>]*>/', $html, $match);

    expect($match[0] ?? '')->not->toBe('', "the picker does not draw {$date} at all");

    return $match[0];
}

function openPicker(array $params): string
{
    return Livewire::test('shared.hijri-datepicker', $params)->set('open', true)->html();
}

it('offers every day of an eight-day Saturday-to-Saturday window', function () {
    $html = openPicker([
        'allowedWeekdays' => [1, 2, 3, 4, 5, 6, 7],
        'minDate' => '2026-09-19',
        'maxDate' => '2026-09-26',
    ]);

    foreach (['2026-09-19', '2026-09-22', '2026-09-25', '2026-09-26'] as $date) {
        expect(dayCell($html, $date))
            ->not->toContain('disabled', "{$date} is inside the window and must be tappable");
    }

    // The Sunday after the closing Saturday belongs to the week that has not opened.
    expect(dayCell($html, '2026-09-27'))->toContain('disabled');
    // As does the Friday before the window opened.
    expect(dayCell($html, '2026-09-18'))->toContain('disabled');
});

it('still bars a weekday the manager closed, even inside the window', function () {
    $html = openPicker([
        'allowedWeekdays' => [1, 4], // Sunday and Wednesday
        'minDate' => '2026-09-19',
        'maxDate' => '2026-09-26',
    ]);

    expect(dayCell($html, '2026-09-20'))->not->toContain('disabled'); // Sunday
    expect(dayCell($html, '2026-09-23'))->not->toContain('disabled'); // Wednesday
    expect(dayCell($html, '2026-09-26'))->toContain('disabled');      // Saturday, closed
});

it('restricts nothing when no rule is handed to it', function () {
    $html = openPicker([]);

    foreach (['2026-09-18', '2026-09-27', '2026-10-01'] as $date) {
        expect(dayCell($html, $date))->not->toContain('disabled');
    }
});
