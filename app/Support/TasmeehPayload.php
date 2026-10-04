<?php

namespace App\Support;

use App\Models\HadithPathDay;
use App\Models\OdePathDay;
use App\Models\StudentPlanDay;
use App\Models\Surah;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Shapes a Quran plan day for the tasmeeh card.
 *
 * The card renders its days in the browser from this data rather than from
 * server-rendered markup — twenty-five days weigh a few kilobytes here against
 * half a megabyte as HTML. Both the JSON endpoint and the card itself shape
 * days through this one place, so the two can never drift apart on how a range
 * reads or where a link points.
 */
class TasmeehPayload
{
    /**
     * @param  Collection<int, StudentPlanDay>  $days
     * @return array<int, array<string, mixed>>
     */
    public static function quranDays(Collection $days): array
    {
        return $days->map(fn (StudentPlanDay $day) => [
            'id' => $day->id,
            'date' => $day->date?->format('Y-m-d'),
            'day_name' => $day->day_name,
            'hifz' => [
                'range' => $day->formatRange('hifz', false),
                'recited_range' => $day->formatRecitedRange('hifz'),
                'achievement' => $day->hifz_achievement,
                'links' => self::quranLinks($day->fromAyah, $day->toAyah),
            ],
            'review' => [
                'range' => $day->formatRange('review', false),
                'recited_range' => $day->formatRecitedRange('review'),
                'achievement' => $day->review_achievement,
                'links' => self::quranLinks($day->reviewFromAyah, $day->reviewToAyah),
            ],
        ])->values()->all();
    }

    /**
     * Hadith path days, identified by path day — the same handle the grading
     * actions use. The days arrive already carrying the student's achievements.
     *
     * @param  Collection<int, HadithPathDay>  $days
     * @return array<int, array<string, mixed>>
     */
    public static function hadithDays(Collection $days): array
    {
        return $days->map(fn ($day) => [
            'id' => $day->id,
            'date' => $day->date?->format('Y-m-d'),
            'day_name' => $day->day_name,
            'from_hadith_name' => $day->fromHadith?->name,
            'review_hadith_name' => $day->reviewFromHadith?->name ?? $day->fromHadith?->name,
            'has_hifz' => (bool) $day->from_hadith_id,
            'has_review' => (bool) $day->review_from_hadith_id,
            'hifz' => [
                'range' => $day->formatHadithRange('hifz'),
                'achievement' => $day->hifz_achievement,
            ],
            'review' => [
                'range' => $day->formatHadithRange('review'),
                'achievement' => $day->review_achievement,
            ],
        ])->values()->all();
    }

    /**
     * Ode path days, identified by path day like the mutun ones. The days
     * arrive already carrying the student's achievements and grading dates.
     *
     * @param  Collection<int, OdePathDay>  $days
     * @return array<int, array<string, mixed>>
     */
    public static function odeDays(Collection $days): array
    {
        $gradedOn = fn ($value) => $value ? Carbon::parse($value)->format('Y-m-d') : null;

        return $days->map(fn ($day) => [
            'id' => $day->id,
            'date' => $day->date?->format('Y-m-d'),
            'day_name' => $day->day_name,
            'has_hifz' => (bool) ($day->from_verse_number && $day->to_verse_number),
            'has_review' => (bool) ($day->review_from_verse_number && $day->review_to_verse_number),
            'hifz' => [
                'range' => $day->formatOdeRange('hifz'),
                'achievement' => $day->hifz_achievement,
                'graded_on' => $gradedOn($day->hifz_graded_at),
            ],
            'review' => [
                'range' => $day->formatOdeRange('review'),
                'achievement' => $day->review_achievement,
                'graded_on' => $gradedOn($day->review_graded_at),
            ],
        ])->values()->all();
    }

    /**
     * Quran.com links for a range, one per surah it spans.
     *
     * @return array<int, array{name: string, url: string}>
     */
    public static function quranLinks($from, $to): array
    {
        if (! $from) {
            return [];
        }

        if (! $to || $from->surah_id === $to->surah_id) {
            $last = $to?->verse_number ?? $from->surah->verses_count;

            return [[
                'name' => $from->surah->name_arabic,
                'url' => 'https://quran.com/ar/'.$from->surah->number.'/'.$from->verse_number.'-'.$last,
            ]];
        }

        $surahs = Surah::whereBetween('id', [
            min($from->surah_id, $to->surah_id),
            max($from->surah_id, $to->surah_id),
        ])->orderBy('id', $from->surah_id <= $to->surah_id ? 'asc' : 'desc')->get();

        return $surahs->map(function (Surah $surah) use ($from, $to) {
            $start = $surah->id === $from->surah_id ? $from->verse_number : 1;
            $end = $surah->id === $to->surah_id ? $to->verse_number : $surah->verses_count;

            return [
                'name' => $surah->name_arabic,
                'url' => 'https://quran.com/ar/'.$surah->number.'/'.$start.'-'.$end,
            ];
        })->values()->all();
    }
}
