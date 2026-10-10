<?php

namespace App\Console\Commands;

use App\Models\Ayah;
use App\Services\QuranPlanFiller;
use App\Services\QuranPlanService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * The teacher app writes plans offline with a port of the site's plan engine.
 * This writes what the port needs from the site: the mushaf layout it
 * measures with, and fixtures of the site's own answers that the app's tests
 * replay, so the two can never quietly part ways.
 */
class ExportPlanEngine extends Command
{
    protected $signature = 'app:export-plan-engine
        {--layout= : Where to write the mushaf layout the app bundles}
        {--fixtures= : The directory to write the parity fixtures to}
        {--seed=20261009 : Seeds the fill scenarios, so every run writes the same ones}
        {--scenarios=400 : How many fill scenarios to write}
        {--stride=1 : Measure the volumes from every this-many-th ayah}';

    protected $description = 'Export the mushaf layout and the plan engine parity fixtures for the teacher app';

    /** Volumes measured in lines, the ones a review-only plan rounds to page and surah edges. */
    private const LINE_VOLUMES = ['half', 'third', 'page', '5_pages', 'half_juz', 'custom_pages_1', 'custom_pages_2', 'custom_pages_3', 'custom_pages_10'];

    private const WHOLE_VOLUMES = ['surah', '1_surah', '2_surahs', '3_surahs', 'custom_surahs_5', 'juz', 'custom_juz_2', 'custom_juz_3'];

    /** The buttons the wizard shows for a hifz day and for a review day. */
    private const HIFZ_BUTTONS = ['surah', 'page', 'half', 'third'];

    private const REVIEW_BUTTONS = ['juz', 'half_juz', '5_pages', '3_surahs', '2_surahs', '1_surah'];

    /** @var array<int, int> verses in each surah */
    private array $verses = [];

    public function handle(QuranPlanService $service): int
    {
        $layoutPath = $this->option('layout');
        $fixturesDir = $this->option('fixtures');

        if (! $layoutPath && ! $fixturesDir) {
            $this->error('Name --layout, --fixtures or both.');

            return self::FAILURE;
        }

        $ayahs = Ayah::orderBy('id')->get(['id', 'surah_id', 'verse_number', 'page_number', 'line_number_start', 'line_number_end', 'juz_number']);

        if ($problem = $this->layoutProblem($ayahs)) {
            $this->error($problem);

            return self::FAILURE;
        }

        $this->verses = $ayahs->countBy('surah_id')->all();

        if ($layoutPath) {
            File::ensureDirectoryExists(dirname($layoutPath));
            File::put($layoutPath, $this->layout($ayahs));
            $this->info("Layout written to {$layoutPath}");
        }

        if ($fixturesDir) {
            File::ensureDirectoryExists($fixturesDir);

            $volumes = $this->volumeFixtures($service, $ayahs, max(1, (int) $this->option('stride')));
            File::put("{$fixturesDir}/end-ayahs.json.gz", gzencode(json_encode($volumes), 9));

            $scenarios = $this->fillScenarios(max(0, (int) $this->option('scenarios')));
            File::put("{$fixturesDir}/fill-scenarios.json.gz", gzencode(json_encode(['seed' => (int) $this->option('seed'), 'scenarios' => $scenarios]), 9));

            $this->info("Fixtures written to {$fixturesDir}");
        }

        return self::SUCCESS;
    }

    /**
     * Why the ayahs cannot be exported as they are, if they cannot: the app
     * reads an ayah's id as its place in the mushaf, as the engine does when
     * it walks a page.
     *
     * @param  Collection<int, Ayah>  $ayahs
     */
    private function layoutProblem(Collection $ayahs): ?string
    {
        if ($ayahs->isEmpty()) {
            return 'There are no ayahs to export.';
        }

        $previous = null;

        foreach ($ayahs->values() as $index => $ayah) {
            $follows = $previous === null
                ? $ayah->surah_id === 1 && $ayah->verse_number === 1
                : ($ayah->surah_id === $previous->surah_id && $ayah->verse_number === $previous->verse_number + 1)
                    || ($ayah->surah_id === $previous->surah_id + 1 && $ayah->verse_number === 1);

            if ($ayah->id !== $index + 1 || ! $follows) {
                return "Ayah {$ayah->id} ({$ayah->surah_id}:{$ayah->verse_number}) is out of mushaf order.";
            }

            if ($ayah->line_number_start === null || $ayah->line_number_end === null) {
                return "Ayah {$ayah->surah_id}:{$ayah->verse_number} has no lines.";
            }

            $previous = $ayah;
        }

        return null;
    }

    /**
     * One line per surah, each verse as [page, first line, last line, juz].
     *
     * @param  Collection<int, Ayah>  $ayahs
     */
    private function layout(Collection $ayahs): string
    {
        $surahs = $ayahs->groupBy('surah_id')->map(fn (Collection $verses) => json_encode(
            $verses->map(fn (Ayah $ayah) => [$ayah->page_number, $ayah->line_number_start, $ayah->line_number_end, $ayah->juz_number])->values()->all()
        ));

        return "{\"surahs\":[\n".$surahs->implode(",\n")."\n]}\n";
    }

    /**
     * Where each volume ends from each start, both ways and, for the volumes
     * measured in lines, for review-only plans too. An end of 0 is a start
     * the site fails on.
     *
     * @param  Collection<int, Ayah>  $ayahs
     * @return array{starts: list<int>, cases: list<array{type: string, direction: string, reviewOnly: bool, ends: list<int>}>}
     */
    private function volumeFixtures(QuranPlanService $service, Collection $ayahs, int $stride): array
    {
        $starts = $ayahs->values()->filter(fn (Ayah $ayah, int $index) => $index % $stride === 0)->values();
        $cases = [];

        foreach ([...self::LINE_VOLUMES, ...self::WHOLE_VOLUMES] as $type) {
            foreach (['forward', 'reverse'] as $direction) {
                foreach (in_array($type, self::LINE_VOLUMES, true) ? [false, true] : [false] as $reviewOnly) {
                    $ends = [];

                    foreach ($starts as $start) {
                        try {
                            $ends[] = $service->getEndAyah($start, $type, $direction, null, $reviewOnly)->id;
                        } catch (Throwable) {
                            $ends[] = 0;
                        }
                    }

                    $cases[] = ['type' => $type, 'direction' => $direction, 'reviewOnly' => $reviewOnly, 'ends' => $ends];
                }
            }
        }

        return ['starts' => $starts->pluck('id')->all(), 'cases' => $cases];
    }

    /**
     * Plans laid out as the wizard lays them and then filled, edited by hand
     * and filled again, with the days as the site leaves them after each step.
     *
     * @return list<array<string, mixed>>
     */
    private function fillScenarios(int $count): array
    {
        mt_srand((int) $this->option('seed'));

        $scenarios = [];

        for ($n = 0; $n < $count; $n++) {
            $scenarios[] = $this->fillScenario();
        }

        return $scenarios;
    }

    /**
     * @return array<string, mixed>
     */
    private function fillScenario(): array
    {
        $planType = $this->pick(['hifz', 'review', 'hifz_review']);
        $fillDirection = $this->pick(['forward', 'reverse']);
        // Choosing the hifz direction sets the review one to match, and it
        // may then be changed.
        $reviewDirection = mt_rand(0, 1) ? $fillDirection : $this->pick(['forward', 'reverse']);
        // The ceiling sits at the far end of the mushaf unless it is moved.
        $ceiling = mt_rand(0, 1) ? ($fillDirection === 'reverse' ? [1, 7] : [114, 6]) : $this->randomAyah();
        // generateDays() opens a review plan at the end the hifz runs from.
        $bulkStart = $planType === 'review' ? [$fillDirection === 'reverse' ? 114 : 1, 1] : $this->randomAyah();
        $daysCount = mt_rand(3, 30);

        $filler = new QuranPlanFiller($planType, $fillDirection, $reviewDirection, $bulkStart[0], $bulkStart[1], $ceiling[0], $ceiling[1]);

        $opening = Ayah::where('surah_id', $bulkStart[0])->where('verse_number', $bulkStart[1])->first() ?: Ayah::first();
        $days = array_fill(0, $daysCount, [
            'from_surah_id' => $opening->surah_id, 'from_verse' => $opening->verse_number,
            'to_surah_id' => $opening->surah_id, 'to_verse' => $opening->verse_number,
            'review_from_surah_id' => $opening->surah_id, 'review_from_verse' => $opening->verse_number,
            'review_to_surah_id' => $opening->surah_id, 'review_to_verse' => $opening->verse_number,
            'selected' => false,
        ]);

        $steps = [];

        foreach (range(1, mt_rand(1, 5)) as $ignored) {
            if (mt_rand(1, 100) <= 15) {
                $edit = ['day' => mt_rand(0, $daysCount - 1), 'field' => $this->pick(['from', 'to', 'review_from', 'review_to']), 'ayah' => $this->randomAyah()];
                $days[$edit['day']][$edit['field'].'_surah_id'] = $edit['ayah'][0];
                $days[$edit['day']][$edit['field'].'_verse'] = $edit['ayah'][1];
                $steps[] = ['edit' => $edit];

                continue;
            }

            $target = $this->pick(['hifz', 'review']);
            $fill = ['type' => $this->volumeFor($planType, $target), 'target' => $target, 'selected' => $this->selection($daysCount)];

            try {
                $days = $filler->fill($days, $fill['type'], $target, $fill['selected']);
                $steps[] = ['fill' => $fill, 'expect' => array_map(fn (array $day) => [
                    $day['from_surah_id'], $day['from_verse'], $day['to_surah_id'], $day['to_verse'],
                    $day['review_from_surah_id'], $day['review_from_verse'], $day['review_to_surah_id'], $day['review_to_verse'],
                ], $days)];
            } catch (Throwable) {
                // The page keeps the days it had when a fill fails.
                $steps[] = ['fill' => $fill, 'throws' => true];
            }
        }

        return compact('planType', 'fillDirection', 'reviewDirection', 'bulkStart', 'ceiling', 'daysCount') + [
            'opening' => [$opening->surah_id, $opening->verse_number],
            'steps' => $steps,
        ];
    }

    /**
     * A volume the wizard offers for the days being filled, now and then any
     * volume at all, which the engine must answer the same way too.
     */
    private function volumeFor(string $planType, string $target): string
    {
        $custom = [
            'custom_pages_'.mt_rand(1, 30),
            'custom_surahs_'.mt_rand(1, 20),
            'custom_juz_'.mt_rand(1, 5),
        ];

        if (mt_rand(1, 10) === 1) {
            return $this->pick([...self::HIFZ_BUTTONS, ...self::REVIEW_BUTTONS, 'all_previous', ...$custom]);
        }

        $reviewing = $planType === 'review' || ($planType === 'hifz_review' && $target === 'review');

        if (! $reviewing) {
            return $this->pick([...self::HIFZ_BUTTONS, ...$custom]);
        }

        return $this->pick([...self::REVIEW_BUTTONS, ...$custom, ...($planType === 'hifz_review' ? ['all_previous'] : [])]);
    }

    /**
     * Every day, a run of days, or days picked here and there.
     *
     * @return list<int>
     */
    private function selection(int $daysCount): array
    {
        $roll = mt_rand(1, 100);

        if ($roll <= 40) {
            return range(0, $daysCount - 1);
        }

        if ($roll <= 80) {
            $first = mt_rand(0, $daysCount - 1);

            return range($first, mt_rand($first, $daysCount - 1));
        }

        if ($roll <= 95) {
            return array_values(array_filter(range(0, $daysCount - 1), fn () => mt_rand(0, 1) === 1));
        }

        return [];
    }

    /**
     * An ayah anywhere, its surah's first or last verse more often than not.
     *
     * @return array{int, int}
     */
    private function randomAyah(): array
    {
        $surah = mt_rand(1, 114);
        $verses = $this->verses[$surah] ?? 1;

        return [$surah, $this->pick([1, $verses, mt_rand(1, $verses)])];
    }

    /**
     * @template T
     *
     * @param  list<T>  $choices
     * @return T
     */
    private function pick(array $choices): mixed
    {
        return $choices[mt_rand(0, count($choices) - 1)];
    }
}
