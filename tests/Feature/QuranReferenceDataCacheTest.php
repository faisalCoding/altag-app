<?php

use App\Models\Ayah;
use App\Models\Surah;
use App\Services\QuranPlanService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();

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

    for ($i = 1; $i <= 15; $i++) {
        Ayah::create([
            'id' => $i,
            'surah_id' => 1,
            'verse_number' => $i,
            'page_number' => 1,
            'line_number_start' => $i,
            'line_number_end' => $i,
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

it('builds the plan reference data from the ayahs', function () {
    $data = (new QuranPlanService)->getPlanReferenceData();

    expect($data)->toHaveKeys(['juzSurahs', 'versesData'])
        ->and($data['juzSurahs'])->toHaveKey(1)
        ->and($data['juzSurahs'][1])->toContain(1)
        ->and($data['versesData'])->toHaveKey(1)
        ->and($data['versesData'][1]['pages'])->toHaveKey(1);
});

it('caches plan reference data so the ayahs table is queried only once', function () {
    $service = new QuranPlanService;

    expect(Cache::has('quran.plan.reference_data'))->toBeFalse();

    $ayahQueries = 0;
    DB::listen(function ($query) use (&$ayahQueries) {
        if (str_contains($query->sql, 'ayahs')) {
            $ayahQueries++;
        }
    });

    $first = $service->getPlanReferenceData();
    $second = $service->getPlanReferenceData();

    expect($ayahQueries)->toBe(1)
        ->and(Cache::has('quran.plan.reference_data'))->toBeTrue()
        ->and($second)->toEqual($first);
});

it('caches the surah list as primitive rows and rehydrates models (queried once)', function () {
    $service = new QuranPlanService;

    $surahQueries = 0;
    DB::listen(function ($query) use (&$surahQueries) {
        if (str_contains($query->sql, 'surahs')) {
            $surahQueries++;
        }
    });

    $first = $service->getAllSurahs();
    $second = $service->getAllSurahs();

    expect($surahQueries)->toBe(1)
        ->and($first)->toBeInstanceOf(Collection::class)
        ->and($first->first())->toBeInstanceOf(Surah::class)
        ->and($first->pluck('id')->all())->toBe($second->pluck('id')->all());

    // Regression guard: the cached payload must be plain arrays, not Eloquent
    // objects, so a serializing store (file/redis/database) can round-trip it
    // instead of returning an __PHP_Incomplete_Class.
    $cached = Cache::get('quran.surahs.rows');
    expect($cached)->toBeArray()
        ->and($cached[0])->toBeArray()
        ->and(unserialize(serialize($cached)))->toEqual($cached);
});

it('drops both cached payloads on demand', function () {
    $service = new QuranPlanService;

    $service->getAllSurahs();
    $service->getPlanReferenceData();

    expect(Cache::has(QuranPlanService::SURAHS_KEY))->toBeTrue()
        ->and(Cache::has(QuranPlanService::REFERENCE_KEY))->toBeTrue();

    $service->forgetReferenceData();

    expect(Cache::has(QuranPlanService::SURAHS_KEY))->toBeFalse()
        ->and(Cache::has(QuranPlanService::REFERENCE_KEY))->toBeFalse();
});

it('serves surahs synced after an empty list was already cached', function () {
    // The shape of the bug on a fresh copy of the app: the plan wizard is opened
    // before the quran is synced, so "no surahs" is what gets cached forever.
    Surah::query()->delete();
    Ayah::query()->delete();

    $service = new QuranPlanService;
    expect($service->getAllSurahs())->toBeEmpty();

    Surah::create([
        'id' => 114, 'number' => 114, 'name_arabic' => 'الناس', 'name_simple' => 'An-Nas',
        'revelation_place' => 'makkah', 'revelation_order' => 21, 'verses_count' => 6,
        'start_page' => 604, 'end_page' => 604,
    ]);

    // Without the sync clearing up after itself, the wizard reads the empty list
    // for as long as the cache lives — which is forever.
    expect($service->getAllSurahs())->toBeEmpty();

    $service->forgetReferenceData();

    expect($service->getAllSurahs()->pluck('id')->all())->toBe([114]);
});
