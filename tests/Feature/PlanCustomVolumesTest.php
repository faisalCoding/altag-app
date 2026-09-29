<?php

use App\Models\Ayah;
use App\Models\Surah;
use App\Services\QuranPlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * A small mushaf that carries the shapes these volumes turn on: a juz that ends
 * in the middle of a surah — as juz 1 really does, at 2:141 — and a juz that
 * opens on a surah boundary, which is what the reverse case reads.
 *
 *   surah 1: 7 verses   juz 1
 *   surah 2: 20 verses  verses 1-10 juz 1, verses 11-20 juz 2
 *   surah 3: 10 verses  juz 2
 *   surah 4: 10 verses  juz 3
 *   surah 5: 5 verses   juz 3
 */
beforeEach(function () {
    $plan = [1 => [7, [1 => 1]], 2 => [20, [1 => 1, 11 => 2]], 3 => [10, [1 => 2]], 4 => [10, [1 => 3]], 5 => [5, [1 => 3]]];

    $id = 0;
    $page = 1;
    $line = 1;

    foreach ($plan as $surahId => [$verses, $juzAt]) {
        Surah::create([
            'id' => $surahId, 'number' => $surahId,
            'name_arabic' => 'سورة '.$surahId, 'name_simple' => 'Surah '.$surahId,
            'revelation_place' => 'makkah', 'revelation_order' => $surahId,
            'verses_count' => $verses, 'start_page' => $page, 'end_page' => $page,
        ]);

        $juz = 1;
        for ($v = 1; $v <= $verses; $v++) {
            $juz = $juzAt[$v] ?? $juz;

            Ayah::create([
                'id' => ++$id, 'surah_id' => $surahId, 'verse_number' => $v,
                'page_number' => $page, 'line_number_start' => $line, 'line_number_end' => $line,
                'verse_key' => "{$surahId}:{$v}", 'juz_number' => $juz,
                'hizb_number' => 1, 'rub_number' => 1, 'ruku_number' => 1, 'manzil_number' => 1,
                'text_uthmani' => "آية {$v}",
            ]);

            if (++$line > 15) {
                $line = 1;
                $page++;
            }
        }
    }

    $this->service = new QuranPlanService;
});

function ayah(string $key): Ayah
{
    return Ayah::where('verse_key', $key)->firstOrFail();
}

// ── السور ───────────────────────────────────────────────────────────────────

it('ends a surah run on a surah boundary', function () {
    expect($this->service->surahEnd(ayah('2:5'), 1, 'forward')->verse_key)->toBe('2:20');
    expect($this->service->surahEnd(ayah('2:5'), 2, 'forward')->verse_key)->toBe('3:10');
    expect($this->service->surahEnd(ayah('2:5'), 3, 'forward')->verse_key)->toBe('4:10');
});

it('reproduces the surah buttons that were already there', function () {
    // Generalising must not move what existed: 2_surahs was id+1, 3_surahs id+2.
    foreach (['1_surah' => 2, 'surah' => 2, '2_surahs' => 3, '3_surahs' => 4, 'custom_surahs_3' => 4] as $type => $surah) {
        expect($this->service->getEndAyah(ayah('2:5'), $type, 'forward')->surah_id)
            ->toBe($surah, "forward {$type}");
    }

    expect($this->service->getEndAyah(ayah('3:1'), '2_surahs', 'reverse')->surah_id)->toBe(2);
    expect($this->service->getEndAyah(ayah('3:1'), '3_surahs', 'reverse')->surah_id)->toBe(1);
});

it('clamps a surah run at both ends of the mushaf', function () {
    expect($this->service->surahEnd(ayah('5:1'), 9, 'forward')->surah_id)->toBe(5);
    expect($this->service->surahEnd(ayah('2:1'), 9, 'reverse')->surah_id)->toBe(1);
});

// ── الأجزاء ─────────────────────────────────────────────────────────────────

it('ends a juz where the juz really ends, mid-surah and all', function () {
    // Juz 1 closes at 2:10 here, as the real one closes at 2:141 — a measured
    // twenty pages would have walked straight past it.
    expect($this->service->juzEnd(ayah('1:1'), 1, 'forward')->verse_key)->toBe('2:10');
    expect($this->service->juzEnd(ayah('1:1'), 2, 'forward')->verse_key)->toBe('3:10');
    expect($this->service->juzEnd(ayah('2:15'), 1, 'forward')->verse_key)->toBe('3:10');
});

it('reads the far end of the juz when the plan runs backwards', function () {
    // A day reads forward inside a surah and only then jumps to the one before,
    // so the last verse read in a reverse juz is the end of the surah that
    // opens it — which is what the old hand-written juz thirty case said.
    expect($this->service->juzEnd(ayah('5:1'), 1, 'reverse')->verse_key)->toBe('4:10');
    expect($this->service->getEndAyah(ayah('5:1'), 'juz', 'reverse')->verse_key)->toBe('4:10');
});

it('clamps a juz run at both ends', function () {
    expect($this->service->juzEnd(ayah('4:1'), 9, 'forward')->juz_number)->toBe(3);
    expect($this->service->juzEnd(ayah('2:15'), 9, 'reverse')->juz_number)->toBe(1);
});

it('does not round a juz away to finish a surah', function () {
    // The page-fill rules would carry the day past the boundary the volume is
    // named after, so juz came off their list.
    expect($this->service->getEndAyah(ayah('1:1'), 'juz', 'forward', null, true)->verse_key)->toBe('2:10');
});

it('still rounds the volumes that are measured in lines', function () {
    // half_juz has no real boundary to land on, so it keeps its measurement and
    // keeps the rounding that goes with it.
    expect($this->service->getEndAyah(ayah('1:1'), 'half_juz', 'forward', null, true))->toBeInstanceOf(Ayah::class);
});

it('measures lines when an ayah carries no juz at all', function () {
    Ayah::query()->update(['juz_number' => 0]);

    // Narrow fixtures exist, and a row the sync never filled must not crash.
    expect($this->service->juzEnd(ayah('1:1')->fresh(), 1, 'forward'))->toBeInstanceOf(Ayah::class);
});

it('reaches the new volumes through the type string the buttons send', function () {
    expect($this->service->getEndAyah(ayah('1:1'), 'custom_juz_2', 'forward')->verse_key)->toBe('3:10');
    expect($this->service->getEndAyah(ayah('2:5'), 'custom_surahs_2', 'forward')->verse_key)->toBe('3:10');
});
