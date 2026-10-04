<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Ayah;
use App\Models\Student;
use App\Models\StudentPlan;
use App\Models\StudentPlanDay;
use App\Models\Surah;
use Carbon\Carbon;

class MemorizationJourneyService
{
    /**
     * Map every juz (1-30) to the student's memorization status: full, partial, none.
     * Computed by overlapping each juz's ayah-id range with the memorized range.
     *
     * @return array<int, array{juz: int, status: string}>
     */
    public static function juzMap(Student $student): array
    {
        $range = $student->getMemorizedRange();

        $juzBounds = Ayah::selectRaw('juz_number, MIN(id) as min_id, MAX(id) as max_id')
            ->groupBy('juz_number')
            ->get()
            ->keyBy('juz_number');

        $map = [];
        for ($juz = 1; $juz <= 30; $juz++) {
            $bounds = $juzBounds->get($juz);
            $status = 'none';

            if ($range && $bounds) {
                $overlaps = ! ($bounds->max_id < $range['min'] || $bounds->min_id > $range['max']);
                if ($overlaps) {
                    $status = ($bounds->min_id >= $range['min'] && $bounds->max_id <= $range['max']) ? 'full' : 'partial';
                }
            }

            $map[] = ['juz' => $juz, 'status' => $status];
        }

        return $map;
    }

    /**
     * Map every surah (1-114) to the student's memorization status and percentage.
     * Approximated the same way as juzMap(): overlaps each surah's ayah-id range
     * with the student's single contiguous memorized range.
     *
     * @return array<int, array{surah_id: int, number: int, name: string, verses_count: int, percentage: float, status: string}>
     */
    public static function surahMap(Student $student): array
    {
        $range = $student->getMemorizedRange();

        $surahBounds = Ayah::selectRaw('surah_id, MIN(id) as min_id, MAX(id) as max_id')
            ->groupBy('surah_id')
            ->get()
            ->keyBy('surah_id');

        $surahs = Surah::orderBy('number')->get(['id', 'number', 'name_arabic', 'verses_count']);

        $map = [];
        foreach ($surahs as $surah) {
            $bounds = $surahBounds->get($surah->id);
            $status = 'none';
            $percentage = 0.0;

            if ($range && $bounds) {
                $overlapMin = max($bounds->min_id, $range['min']);
                $overlapMax = min($bounds->max_id, $range['max']);
                $overlapCount = max(0, $overlapMax - $overlapMin + 1);

                if ($overlapCount > 0 && $surah->verses_count > 0) {
                    $percentage = round($overlapCount / $surah->verses_count * 100, 1);
                    $status = ($bounds->min_id >= $range['min'] && $bounds->max_id <= $range['max']) ? 'full' : 'partial';
                }
            }

            $map[] = [
                'surah_id' => $surah->id,
                'number' => $surah->number,
                'name' => $surah->name_arabic,
                'verses_count' => $surah->verses_count,
                'percentage' => $percentage,
                'status' => $status,
            ];
        }

        return $map;
    }

    /**
     * Ayahs graded (hifz_achievement >= 2) per calendar day within the current month,
     * approximated from each graded StudentPlanDay's ayah-range size.
     *
     * @return array<int, array{date: string, count: int}>
     */
    public static function monthlyAyahsMemorized(Student $student): array
    {
        $monthStart = now()->startOfMonth();
        $monthEnd = now()->endOfMonth();

        $days = StudentPlanDay::whereHas('plan', fn ($q) => $q->where('student_id', $student->id))
            ->where('hifz_achievement', '>=', 2)
            ->whereNotNull('from_ayah_id')
            ->whereNotNull('to_ayah_id')
            ->whereBetween('date', [$monthStart, $monthEnd])
            ->get(['date', 'from_ayah_id', 'to_ayah_id']);

        $buckets = [];
        foreach ($days as $day) {
            $dateStr = Carbon::parse($day->date)->toDateString();
            $count = abs($day->to_ayah_id - $day->from_ayah_id) + 1;
            $buckets[$dateStr] = ($buckets[$dateStr] ?? 0) + $count;
        }

        ksort($buckets);

        return collect($buckets)
            ->map(fn ($count, $date) => ['date' => $date, 'count' => $count])
            ->values()
            ->all();
    }

    /**
     * Total memorized amount expressed in juz (fractional), approximated from the
     * memorized page count out of 604 pages / 30 juz.
     */
    public static function memorizedJuzCount(Student $student): float
    {
        return round($student->memorizedPagesCount() / 604 * 30, 1);
    }

    /**
     * The surah currently "in progress" — the one containing the edge of the
     * student's memorized range — with how much of that specific surah is done.
     * Returns null when the student has no memorization yet.
     *
     * @return array{surah_name: string, from_verse: int, to_verse: int, memorized_in_surah: int, total_in_surah: int, percentage: float}|null
     */
    public static function currentSurahProgress(Student $student): ?array
    {
        $range = $student->getMemorizedRange();
        if (! $range) {
            return null;
        }

        $latestPlan = StudentPlan::where('student_id', $student->id)
            ->where('is_approved', true)
            ->latest('start_date')
            ->first();
        $direction = $latestPlan->direction ?? 'forward';
        $boundaryAyahId = $direction === 'reverse' ? $range['min'] : $range['max'];

        $boundaryAyah = Ayah::with('surah')->find($boundaryAyahId);
        if (! $boundaryAyah || ! $boundaryAyah->surah) {
            return null;
        }

        $surah = $boundaryAyah->surah;
        $surahBounds = Ayah::where('surah_id', $surah->id)->selectRaw('MIN(id) as min_id, MAX(id) as max_id')->first();

        $overlapMin = max($surahBounds->min_id, $range['min']);
        $overlapMax = min($surahBounds->max_id, $range['max']);
        $memorizedInSurah = max(0, $overlapMax - $overlapMin + 1);

        $fromAyah = Ayah::find($overlapMin);
        $toAyah = Ayah::find($overlapMax);

        if (! $fromAyah || ! $toAyah) {
            return null;
        }

        return [
            'surah_name' => $surah->name_arabic,
            'from_verse' => $fromAyah->verse_number,
            'to_verse' => $toAyah->verse_number,
            'memorized_in_surah' => $memorizedInSurah,
            'total_in_surah' => $surah->verses_count,
            'percentage' => $surah->verses_count > 0 ? round($memorizedInSurah / $surah->verses_count * 100, 1) : 0.0,
        ];
    }

    /**
     * How many of today's assigned hifz/review components (across the student's
     * active plans) have already been recited — an honest stand-in for "sessions".
     * A «لم يسمع» is graded but not completed.
     *
     * @return array{completed: int, total: int, percentage: int}
     */
    public static function todayMissionProgress(Student $student): array
    {
        $days = StudentPlanDay::whereHas('plan', fn ($q) => $q->where('student_id', $student->id))
            ->whereDate('date', now()->toDateString())
            ->get();

        $total = 0;
        $completed = 0;

        foreach ($days as $day) {
            if ($day->from_ayah_id && $day->to_ayah_id) {
                $total++;
                if ($day->isRecited('hifz')) {
                    $completed++;
                }
            }
            if ($day->review_from_ayah_id && $day->review_to_ayah_id) {
                $total++;
                if ($day->isRecited('review')) {
                    $completed++;
                }
            }
        }

        return [
            'completed' => $completed,
            'total' => $total,
            'percentage' => $total > 0 ? (int) round($completed / $total * 100) : 0,
        ];
    }

    /**
     * Distinct calendar dates within a given month where the student had either
     * attendance or a recited plan day — feeds the dashboard's activity calendar.
     *
     * @return array<int, string>
     */
    public static function activityDatesForMonth(Student $student, int $year, int $month): array
    {
        $start = Carbon::create($year, $month, 1)->startOfMonth();
        $end = $start->copy()->endOfMonth();

        $attendanceDates = Attendance::where('student_id', $student->id)
            ->whereIn('status', ['present', 'late'])
            ->whereBetween('date', [$start, $end])
            ->pluck('date')
            ->map(fn ($d) => Carbon::parse($d)->toDateString());

        $planDates = StudentPlanDay::whereHas('plan', fn ($q) => $q->where('student_id', $student->id))
            ->where(function ($q) {
                $q->where('hifz_achievement', '>=', 1)->orWhere('review_achievement', '>=', 1);
            })
            ->whereBetween('date', [$start, $end])
            ->pluck('date')
            ->map(fn ($d) => Carbon::parse($d)->toDateString());

        return $attendanceDates->merge($planDates)->unique()->values()->all();
    }

    /**
     * The student's most recent hifz evaluations, oldest-first for a left-to-right
     * timeline. A «لم يسمع» (0) is an evaluation too, so it shows as a point.
     *
     * @return array<int, array{date: string, achievement: int}>
     */
    public static function scoreTrend(Student $student, int $limit = 12): array
    {
        return StudentPlanDay::whereHas('plan', fn ($q) => $q->where('student_id', $student->id))
            ->whereNotNull('hifz_achievement')
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['date', 'hifz_achievement'])
            ->reverse()
            ->map(fn ($day) => ['date' => $day->date->toDateString(), 'achievement' => (int) $day->hifz_achievement])
            ->values()
            ->all();
    }

    /**
     * Present/total attendance per week for the last N weeks, oldest-first.
     *
     * @return array<int, array{label: string, present: int, total: int}>
     */
    public static function attendanceTrend(Student $student, int $weeks = 8): array
    {
        $oldestStart = now()->startOfWeek(Carbon::SATURDAY)->subWeeks($weeks - 1);

        $records = Attendance::where('student_id', $student->id)
            ->where('date', '>=', $oldestStart)
            ->get(['date', 'status']);

        $buckets = [];
        for ($i = $weeks - 1; $i >= 0; $i--) {
            $start = now()->startOfWeek(Carbon::SATURDAY)->subWeeks($i);
            $buckets[$start->toDateString()] = ['label' => $start->format('m/d'), 'present' => 0, 'total' => 0];
        }

        foreach ($records as $record) {
            $weekStart = Carbon::parse($record->date)->startOfWeek(Carbon::SATURDAY)->toDateString();
            if (! isset($buckets[$weekStart])) {
                continue;
            }
            $buckets[$weekStart]['total']++;
            if (in_array($record->status, ['present', 'late'], true)) {
                $buckets[$weekStart]['present']++;
            }
        }

        return array_values($buckets);
    }
}
