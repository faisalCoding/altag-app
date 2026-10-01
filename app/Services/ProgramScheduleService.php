<?php

namespace App\Services;

use App\Models\ScheduleCell;
use App\Models\ScheduleTrack;
use App\Models\ScheduleWeek;
use App\Models\Setting;
use App\Models\Student;
use App\Support\ArabicDigits;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * The evening programme's calendar and who may read which track of it.
 *
 * Weeks are numbered from the week (Sunday to Saturday) that holds the
 * programme's start date, so a week's dates follow from its number and moving
 * the start date moves the whole programme with it.
 */
class ProgramScheduleService
{
    public const SETTING_KEY = 'program_schedule';

    /**
     * @var array<int, string>
     */
    public const WEEKDAY_NAMES = ['الأحد', 'الإثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'];

    /**
     * @var array<int, string>
     */
    public const WEEKDAY_SHORT_NAMES = ['أحد', 'إثنين', 'ثلاثاء', 'أربعاء', 'خميس', 'جمعة', 'سبت'];

    /**
     * The printed poster's colour themes: the day, programme and memorisation
     * headings, and the week title.
     *
     * @var array<string, array<string, string>>
     */
    public const THEMES = [
        'sky' => ['name' => 'سماوي', 'day' => '#1b9dd9', 'program_from' => '#16a6bd', 'program_to' => '#1b9dd9', 'memo' => '#f2794d', 'title' => '#0f6ea0', 'title_from' => '#eaf7fe', 'title_to' => '#d7eefb', 'title_border' => '#c3e6f7', 'stage' => '#16a6bd'],
        'mint' => ['name' => 'نعناعي', 'day' => '#1f9e7a', 'program_from' => '#35b894', 'program_to' => '#1f9e7a', 'memo' => '#f2994d', 'title' => '#157a5e', 'title_from' => '#eafaf4', 'title_to' => '#d3f1e5', 'title_border' => '#b5e5d2', 'stage' => '#1f9e7a'],
        'violet' => ['name' => 'بنفسجي', 'day' => '#6b5bd6', 'program_from' => '#8f7be6', 'program_to' => '#6b5bd6', 'memo' => '#e0508f', 'title' => '#4f40b5', 'title_from' => '#f2effe', 'title_to' => '#e3ddfb', 'title_border' => '#cfc6f6', 'stage' => '#6b5bd6'],
        'sunset' => ['name' => 'غروب', 'day' => '#e2703a', 'program_from' => '#f0a33a', 'program_to' => '#e2703a', 'memo' => '#1b9dd9', 'title' => '#b65220', 'title_from' => '#fff4ea', 'title_to' => '#fde3cc', 'title_border' => '#f8cfa8', 'stage' => '#e2703a'],
        'navy' => ['name' => 'كحلي', 'day' => '#1d3f72', 'program_from' => '#2c6ab0', 'program_to' => '#1d3f72', 'memo' => '#c9a227', 'title' => '#1d3f72', 'title_from' => '#eef3fa', 'title_to' => '#dbe5f3', 'title_border' => '#c3d3ea', 'stage' => '#2c6ab0'],
    ];

    /**
     * @return array{title: string, tagline: string, start_date: string, weekdays: array<int, int>, logo: array{path: string, height: int}|null, partners: array<int, array{path: string, alt: string, height: int}>, table: array<string, mixed>}
     */
    public function settings(): array
    {
        return once(function () {
            $stored = json_decode((string) Setting::getVal(self::SETTING_KEY, ''), true);
            $stored = is_array($stored) ? $stored : [];
            $defaults = $this->defaults();

            $settings = array_merge($defaults, array_intersect_key($stored, $defaults));
            $settings['table'] = array_merge($defaults['table'], (array) ($stored['table'] ?? []));
            $settings['table']['heads'] = array_merge($defaults['table']['heads'], (array) ($stored['table']['heads'] ?? []));
            $settings['weekdays'] = collect($settings['weekdays'])->map(fn ($day) => (int) $day)
                ->filter(fn (int $day) => $day >= 0 && $day <= 6)->unique()->sort()->values()->all() ?: $defaults['weekdays'];

            return $settings;
        });
    }

    /**
     * @return array<string, string>
     */
    public function theme(): array
    {
        return self::THEMES[$this->settings()['table']['theme']] ?? self::THEMES['sky'];
    }

    /**
     * @return array<int, int>
     */
    public function weekdays(): array
    {
        return $this->settings()['weekdays'];
    }

    public function isProgramDay(CarbonInterface $date): bool
    {
        return in_array($date->dayOfWeek, $this->weekdays(), true);
    }

    /**
     * The Sunday that opens week one.
     */
    public function firstSunday(): CarbonImmutable
    {
        $start = CarbonImmutable::parse($this->settings()['start_date'])->startOfDay();

        return $start->subDays($start->dayOfWeek);
    }

    /**
     * The programme week a date falls in; zero or less before the programme starts.
     */
    public function weekNumberFor(CarbonInterface $date): int
    {
        $days = (int) round($this->firstSunday()->diffInDays(CarbonImmutable::parse($date)->startOfDay()));

        return (int) floor($days / 7) + 1;
    }

    public function dateFor(int $weekNumber, int $weekday): CarbonImmutable
    {
        return $this->firstSunday()->addDays(($weekNumber - 1) * 7 + $weekday);
    }

    /**
     * The next (or previous) programme day after a date.
     */
    public function stepProgramDay(CarbonInterface $date, int $direction): CarbonImmutable
    {
        $day = CarbonImmutable::parse($date);

        for ($i = 1; $i <= 7; $i++) {
            $candidate = $day->addDays($direction * $i);

            if ($this->isProgramDay($candidate)) {
                return $candidate;
            }
        }

        return $day->addDays($direction);
    }

    /**
     * The school stages a set of students attend, taken from the student
     * when it is set and from their circle otherwise.
     *
     * @param  EloquentCollection<int, Student>  $students
     * @return array<int, int>
     */
    public function stageIdsFor(EloquentCollection $students): array
    {
        return $students->loadMissing('circle:id,stage_id')
            ->map(fn (Student $student) => $student->stage_id ?? $student->circle?->stage_id)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * The students a signed-in reader follows: a guardian's children, or the
     * student themself.
     *
     * @return EloquentCollection<int, Student>
     */
    public function readerStudents(string $role): EloquentCollection
    {
        $user = Auth::guard($role)->user();

        return match (true) {
            $user === null => new EloquentCollection,
            $role === 'guardian' => $user->students()->get(['id', 'name', 'stage_id', 'circle_id']),
            $role === 'student' => new EloquentCollection([$user]),
            default => new EloquentCollection,
        };
    }

    /**
     * @return Collection<int, ScheduleTrack>
     */
    public function tracksForReader(string $role): Collection
    {
        return $this->tracksForStages($this->stageIdsFor($this->readerStudents($role)));
    }

    /**
     * The programme tracks linked to any of the given school stages.
     *
     * @param  array<int, int>  $stageIds
     * @return Collection<int, ScheduleTrack>
     */
    public function tracksForStages(array $stageIds): Collection
    {
        if ($stageIds === []) {
            return collect();
        }

        return ScheduleTrack::query()
            ->whereHas('stages', fn ($query) => $query->whereIn('stages.id', $stageIds))
            ->with('stages:id')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * A track's week with everything the views need, or null when it has not
     * been published.
     */
    public function publishedWeek(ScheduleTrack $track, int $weekNumber): ?ScheduleWeek
    {
        $week = $track->weeks()
            ->published()
            ->where('week_number', $weekNumber)
            ->with('cells.activity')
            ->first();

        $week?->setRelation('track', $track);

        return $week;
    }

    /**
     * The published weeks of a track between two week numbers, keyed by number.
     *
     * @return Collection<int, ScheduleWeek>
     */
    public function publishedWeeksBetween(ScheduleTrack $track, int $from, int $to): Collection
    {
        return $track->weeks()
            ->published()
            ->whereBetween('week_number', [$from, $to])
            ->with('cells.activity')
            ->get()
            ->each(fn (ScheduleWeek $week) => $week->setRelation('track', $track))
            ->keyBy('week_number');
    }

    /**
     * @return array<int, int>
     */
    public function publishedWeekNumbers(ScheduleTrack $track): array
    {
        return $track->weeks()->published()->pluck('week_number')->all();
    }

    /**
     * Each programme day's boxes in order, padded with empty slots so every
     * row covers exactly the track's columns.
     *
     * @return array<int, array<int, array{cell: ScheduleCell|null, start: int, span: int}>>
     */
    public function grid(ScheduleWeek $week): array
    {
        $columns = $week->track->columns;
        $byDay = $week->cells->groupBy('weekday');
        $grid = [];

        foreach ($this->weekdays() as $weekday) {
            $row = [];
            $used = 0;

            foreach ($byDay->get($weekday, collect())->sortBy('position') as $cell) {
                if ($used >= $columns) {
                    break;
                }

                $span = max(1, min($cell->span, $columns - $used));
                $row[] = ['cell' => $cell->isEmpty() ? null : $cell, 'start' => $used, 'span' => $span];
                $used += $span;
            }

            for (; $used < $columns; $used++) {
                $row[] = ['cell' => null, 'start' => $used, 'span' => 1];
            }

            $grid[$weekday] = $row;
        }

        return $grid;
    }

    /**
     * Relative column widths for the poster, so a column of long lesson titles
     * gets more room than a column of prayers.
     *
     * @param  array<int, array<int, array{cell: ScheduleCell|null, start: int, span: int}>>  $grid
     * @return array<int, float>
     */
    public function columnWeights(array $grid, int $columns): array
    {
        $lengths = array_fill(0, $columns, 0);

        foreach ($grid as $row) {
            foreach ($row as $slot) {
                if ($slot['cell'] && $slot['span'] === 1) {
                    $lengths[$slot['start']] = max(
                        $lengths[$slot['start']],
                        mb_strlen($slot['cell']->displayName()),
                        mb_strlen((string) $slot['cell']->detail) * 0.92,
                    );
                }
            }
        }

        return array_map(fn ($length) => $length ? round(min(max($length / 17, 0.85), 1.6), 2) : 1.0, $lengths);
    }

    /**
     * "٢٨ سبتمبر" or, with the year, "٢٨ سبتمبر ٢٠٢٦".
     */
    public function gregorianLabel(CarbonInterface $date, bool $withYear = false): string
    {
        return ArabicDigits::toEastern(CarbonImmutable::parse($date)->locale('ar')->translatedFormat($withYear ? 'j F Y' : 'j F'));
    }

    /**
     * "٢٧ سبتمبر – ١ أكتوبر ٢٠٢٦": the first and last programme day of a week.
     */
    public function rangeLabel(int $weekNumber): string
    {
        $weekdays = $this->weekdays();
        $from = $this->dateFor($weekNumber, $weekdays[0]);
        $to = $this->dateFor($weekNumber, $weekdays[count($weekdays) - 1]);

        $start = $from->month === $to->month ? ArabicDigits::toEastern($from->day) : $this->gregorianLabel($from);

        return $start.' – '.$this->gregorianLabel($to, true);
    }

    /**
     * Everything the printed poster of a week shows.
     *
     * @return array{week: ScheduleWeek, track: ScheduleTrack, grid: array<int, array<int, array<string, mixed>>>, weights: array<int, float>, dates: array<int, CarbonImmutable>, date_labels: array<int, string>, range: string, settings: array<string, mixed>, theme: array<string, string>}
     */
    public function posterData(ScheduleWeek $week): array
    {
        $grid = $this->grid($week);
        $dates = collect($this->weekdays())->mapWithKeys(fn (int $weekday) => [$weekday => $this->dateFor($week->week_number, $weekday)]);

        return [
            'week' => $week,
            'track' => $week->track,
            'grid' => $grid,
            'weights' => $this->columnWeights($grid, $week->track->columns),
            'dates' => $dates->all(),
            'date_labels' => $dates->map(fn (CarbonImmutable $date) => $this->gregorianLabel($date))->all(),
            'range' => $this->rangeLabel($week->week_number),
            'settings' => $this->settings(),
            'theme' => $this->theme(),
        ];
    }

    /**
     * @return array{title: string, tagline: string, start_date: string, weekdays: array<int, int>, logo: null, partners: array<int, mixed>, table: array<string, mixed>}
     */
    private function defaults(): array
    {
        return [
            'title' => 'البرنامج',
            'tagline' => '',
            'start_date' => CarbonImmutable::today()->toDateString(),
            'weekdays' => [0, 1, 2, 3, 4],
            'logo' => null,
            'partners' => [],
            'table' => [
                'heads' => ['day' => 'اليوم', 'program' => 'البرنامج اليومي', 'memo' => 'محفوظ الأسبوع'],
                'show_dates' => true,
                'show_memo' => true,
                'show_icons' => true,
                'show_tagline' => true,
                'show_partners' => true,
                'theme' => 'sky',
            ],
        ];
    }
}
