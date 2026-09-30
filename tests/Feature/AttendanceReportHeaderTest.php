<?php

use App\Livewire\Manager\AttendanceReports;

it('says three different things in the three header formatters', function () {
    // They were one copy-pasted body returning 'MMM yyyy', so the day row
    // printed the month twice and there was nothing to count the days by.
    $report = new AttendanceReports;
    $date = '2026-09-30'; // ١٩ ربيع الآخر ١٤٤٨، الأربعاء

    expect($report->formatHijriMonthYear($date))->toBe('ربيع الآخر ١٤٤٨');
    expect($report->formatHijriDayNum($date))->toBe('١٩');
    expect($report->formatHijriDayName($date))->toBe('الأربعاء');
});

it('gives the day row the day and the month row the month', function () {
    $report = new AttendanceReports;

    foreach (['2026-09-30', '2026-10-01', '2026-10-15'] as $date) {
        expect($report->formatHijriDayNum($date))->not->toBe($report->formatHijriMonthYear($date));
        expect($report->formatHijriDayName($date))->not->toBe($report->formatHijriMonthYear($date));
    }
});

it('answers with nothing when handed nothing', function () {
    $report = new AttendanceReports;

    foreach ([null, ''] as $empty) {
        expect($report->formatHijriDayNum($empty))->toBe('');
        expect($report->formatHijriDayName($empty))->toBe('');
        expect($report->formatHijriMonthYear($empty))->toBe('');
    }
});
