<?php

use App\Models\Ayah;
use App\Models\Surah;
use App\Services\QuranPlanFiller;
use App\Services\QuranPlanService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * A short mushaf, one verse a line: الفاتحة on page 1, then a surah of 30
 * verses that turns into juz 2 at verse 21, then one of 10.
 */
function seedExportMushaf(): void
{
    $id = 0;
    $line = 0;

    foreach ([1 => 7, 2 => 30, 3 => 10] as $surahId => $verses) {
        Surah::create([
            'id' => $surahId, 'number' => $surahId,
            'name_arabic' => 'سورة '.$surahId, 'name_simple' => 'Surah '.$surahId,
            'revelation_place' => 'makkah', 'revelation_order' => $surahId,
            'verses_count' => $verses, 'start_page' => 1, 'end_page' => 4,
        ]);

        for ($verse = 1; $verse <= $verses; $verse++) {
            $page = intdiv($line, 15) + 1;
            $onPage = $line % 15 + 1;
            $line++;

            Ayah::create([
                'id' => ++$id, 'surah_id' => $surahId, 'verse_number' => $verse, 'verse_key' => "{$surahId}:{$verse}",
                'page_number' => $page, 'line_number_start' => $onPage, 'line_number_end' => $onPage,
                'juz_number' => $surahId === 1 || ($surahId === 2 && $verse < 21) ? 1 : 2,
                'hizb_number' => 1, 'rub_number' => 1, 'ruku_number' => 1, 'manzil_number' => 1,
                'text_uthmani' => "آية {$verse}",
            ]);
        }
    }
}

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/plan-engine-'.Str::random(8);
});

afterEach(function () {
    File::deleteDirectory($this->dir);
});

function readGzipJson(string $path): array
{
    return json_decode(gzdecode(file_get_contents($path)), true);
}

it('writes each surah of the layout on a line, every verse as its page, lines and juz', function () {
    seedExportMushaf();

    $this->artisan('app:export-plan-engine', ['--layout' => "{$this->dir}/mushaf-layout.json"])->assertSuccessful();

    $written = file_get_contents("{$this->dir}/mushaf-layout.json");
    $layout = json_decode($written, true);

    expect(substr_count($written, "\n"))->toBe(5)
        ->and($layout['surahs'])->toHaveCount(3)
        ->and(array_map('count', $layout['surahs']))->toBe([7, 30, 10])
        ->and($layout['surahs'][0][0])->toBe([1, 1, 1, 1])
        ->and($layout['surahs'][1][20])->toBe([2, 13, 13, 2]);
});

it('refuses ayahs out of mushaf order, which the app reads by their place', function () {
    seedExportMushaf();
    Ayah::whereKey(5)->update(['verse_number' => 9]);

    $this->artisan('app:export-plan-engine', ['--layout' => "{$this->dir}/mushaf-layout.json"])
        ->expectsOutputToContain('Ayah 5 (1:9) is out of mushaf order.')
        ->assertFailed();

    expect(file_exists("{$this->dir}/mushaf-layout.json"))->toBeFalse();
});

it('asks where to write', function () {
    $this->artisan('app:export-plan-engine')->expectsOutputToContain('Name --layout, --fixtures or both.')->assertFailed();
});

it('writes the volumes as the site measures them, from every start', function () {
    seedExportMushaf();
    $service = new QuranPlanService;

    $this->artisan('app:export-plan-engine', ['--fixtures' => $this->dir, '--scenarios' => 0])->assertSuccessful();

    $volumes = readGzipJson("{$this->dir}/end-ayahs.json.gz");
    $page = collect($volumes['cases'])->firstWhere(fn (array $case) => $case['type'] === 'page' && $case['direction'] === 'forward' && ! $case['reviewOnly']);

    expect($volumes['starts'])->toBe(range(1, 47))
        ->and(collect($volumes['cases'])->pluck('type')->unique()->values()->all())->toContain('half', 'custom_pages_10', 'juz', 'custom_surahs_5')
        ->and($page['ends'])->toBe(Ayah::orderBy('id')->get()->map(fn (Ayah $start) => $service->getEndAyah($start, 'page', 'forward')->id)->all());
});

it('writes fill scenarios that replay to the same days, the same for the same seed', function () {
    seedExportMushaf();

    $this->artisan('app:export-plan-engine', ['--fixtures' => $this->dir, '--scenarios' => 25, '--stride' => 47])->assertSuccessful();
    $first = file_get_contents("{$this->dir}/fill-scenarios.json.gz");

    $this->artisan('app:export-plan-engine', ['--fixtures' => $this->dir, '--scenarios' => 25, '--stride' => 47])->assertSuccessful();
    expect(file_get_contents("{$this->dir}/fill-scenarios.json.gz"))->toBe($first);

    $scenarios = readGzipJson("{$this->dir}/fill-scenarios.json.gz")['scenarios'];
    $replayed = 0;

    foreach ($scenarios as $scenario) {
        $filler = new QuranPlanFiller($scenario['planType'], $scenario['fillDirection'], $scenario['reviewDirection'], ...$scenario['bulkStart'], ...$scenario['ceiling']);
        [$surah, $verse] = $scenario['opening'];
        $days = array_fill(0, $scenario['daysCount'], [
            'from_surah_id' => $surah, 'from_verse' => $verse, 'to_surah_id' => $surah, 'to_verse' => $verse,
            'review_from_surah_id' => $surah, 'review_from_verse' => $verse, 'review_to_surah_id' => $surah, 'review_to_verse' => $verse,
            'selected' => false,
        ]);

        foreach ($scenario['steps'] as $step) {
            if (isset($step['edit'])) {
                $days[$step['edit']['day']][$step['edit']['field'].'_surah_id'] = $step['edit']['ayah'][0];
                $days[$step['edit']['day']][$step['edit']['field'].'_verse'] = $step['edit']['ayah'][1];

                continue;
            }

            if (! empty($step['throws'])) {
                continue;
            }

            $days = $filler->fill($days, $step['fill']['type'], $step['fill']['target'], $step['fill']['selected']);

            expect(array_map(fn (array $day) => array_values(array_diff_key($day, ['selected' => true])), $days))->toBe($step['expect']);
            $replayed++;
        }
    }

    expect($replayed)->toBeGreaterThan(10);
});
