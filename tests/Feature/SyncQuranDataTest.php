<?php

use App\Models\Surah;
use App\Services\QuranPlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
});

/**
 * Quran.com's answers, cut down to the fields the command actually reads.
 */
function fakeQuranApi(): void
{
    Http::fake([
        'api.quran.com/api/v4/chapters*' => Http::response(['chapters' => [[
            'id' => 1,
            'name_arabic' => 'الفاتحة',
            'name_simple' => 'Al-Fatihah',
            'revelation_place' => 'makkah',
            'revelation_order' => 5,
            'verses_count' => 1,
            'pages' => [1, 1],
        ]]]),
        'api.quran.com/api/v4/verses/by_chapter/*' => Http::response([
            'verses' => [[
                'id' => 1,
                'verse_number' => 1,
                'verse_key' => '1:1',
                'juz_number' => 1,
                'hizb_number' => 1,
                'rub_el_hizb_number' => 1,
                'page_number' => 1,
                'ruku_number' => 1,
                'manzil_number' => 1,
                'sajdah_number' => null,
                'text_uthmani' => 'بِسْمِ اللَّهِ',
                'words' => [['char_type_name' => 'word', 'line_number' => 2]],
            ]],
            'pagination' => ['total_pages' => 1],
        ]),
    ]);
}

it('fills the surahs and ayahs from the api', function () {
    fakeQuranApi();

    $this->artisan('app:sync-quran')->assertSuccessful();

    expect(Surah::count())->toBe(1);
    expect(Surah::find(1)->name_arabic)->toBe('الفاتحة');
});

it('clears the plan wizard cache so a page opened before the sync is not stuck', function () {
    $service = app(QuranPlanService::class);

    // Somebody opens the plan wizard on a fresh copy: an empty surah list is
    // cached, and rememberForever means forever.
    expect($service->getAllSurahs())->toBeEmpty();
    expect(Cache::has(QuranPlanService::SURAHS_KEY))->toBeTrue();

    fakeQuranApi();
    $this->artisan('app:sync-quran')->assertSuccessful();

    expect(Cache::has(QuranPlanService::SURAHS_KEY))->toBeFalse();
    expect(Cache::has(QuranPlanService::REFERENCE_KEY))->toBeFalse();

    // Which is the whole point: the wizard now sees what was synced.
    expect($service->getAllSurahs()->pluck('id')->all())->toBe([1]);
});

it('fails loudly when the server cannot reach the api', function () {
    Http::fake(['api.quran.com/*' => Http::response('', 503)]);

    $this->artisan('app:sync-quran')
        ->expectsOutputToContain('api.quran.com')
        ->assertFailed();

    expect(Surah::count())->toBe(0);
});
