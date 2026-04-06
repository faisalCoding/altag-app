<?php

use App\Models\Ayah;
use App\Models\Surah;
use App\Services\QuranPlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    Surah::create([
        'id' => 1,
        'number' => 1,
        'name_arabic' => 'الفاتحة',
        'name_simple' => 'Al-Fatihah',
        'revelation_place' => 'makkah',
        'revelation_order' => 1,
        'verses_count' => 7,
        'start_page' => 1,
        'end_page' => 1,
    ]);

    // Create 15 ayahs for page 1, each on a different line
    for ($i = 1; $i <= 15; $i++) {
        Ayah::create([
            'id' => $i,
            'surah_id' => 1,
            'verse_number' => $i,
            'page_number' => 1,
            'line_number' => $i,
            'verse_key' => "1:$i",
            'juz_number' => 1,
            'hizb_number' => 1,
            'rub_number' => 1,
            'ruku_number' => 1,
            'manzil_number' => 1,
            'text_uthmani' => "Ayah $i text",
        ]);
    }
});

it('calculates full page end correctly', function () {
    $service = new QuranPlanService;
    $start = Ayah::find(1);

    $end = $service->getEndAyah($start, 'page');

    expect($end->line_number)->toBe(15);
});

it('calculates half page end correctly at line 7', function () {
    $service = new QuranPlanService;
    $start = Ayah::find(1);

    $end = $service->getEndAyah($start, 'half');

    expect($end->line_number)->toBe(7);
});

it('calculates third page end correctly at line 5', function () {
    $service = new QuranPlanService;
    $start = Ayah::find(1);

    $end = $service->getEndAyah($start, 'third');

    expect($end->line_number)->toBe(5);
});

it('finds next start ayah correctly', function () {
    $service = new QuranPlanService;
    $start = Ayah::find(1);
    $end = Ayah::find(5);

    $next = $service->getNextStartAyah($start, $end, 'page');

    expect($next->id)->toBe(6);
});
