<?php

namespace App\Support;

use App\Models\GamificationTrack;
use App\Models\Leaderboard;
use App\Models\Student;
use App\Services\LeaderboardService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * Who led each week of a competition once the week is over: the student with
 * the most points that week — or every student tied on them — for the week
 * chips of the student's leaderboard. A week nobody scored in has no leader.
 *
 * Where the competition ranks in tracks, a student sees the leaders of their
 * own track, as the leaderboard ranks them; one in no track, those of «عام».
 * Each week's leaders are read once for every student, and kept a while: a
 * week gone by changes only when a late grade lands in it.
 */
final class CompetitionWeekLeaders
{
    private const MINUTES = 10;

    /**
     * The leaders of every week that is over, by week number.
     *
     * @param  list<array{number: int, from: string, to: string}>  $weeks  CompetitionWeeks::of()
     * @return array<int, list<array{id: int, name: string, avatar: ?string, initials: string, style: string}>>
     */
    public static function for(Leaderboard $competition, array $weeks, int $viewerId, ?string $today = null): array
    {
        $today ??= now('Asia/Riyadh')->toDateString();
        $ended = array_filter($weeks, fn (array $week) => $week['to'] < $today);

        if ($ended === []) {
            return [];
        }

        $trackOf = self::tracks($competition);
        $group = $trackOf === [] ? 'all' : (string) ($trackOf[$viewerId] ?? 'general');
        $leaders = [];

        foreach ($ended as $week) {
            $leaders[$week['number']] = self::ofWeek($competition, $week, $trackOf)[$group] ?? [];
        }

        return array_filter($leaders);
    }

    /**
     * Student id → track id, for a competition that ranks in tracks.
     *
     * @return array<int, int>
     */
    private static function tracks(Leaderboard $competition): array
    {
        $trackOf = [];

        foreach (GamificationTrack::where('leaderboard_id', $competition->id)->with('students:id')->get() as $track) {
            foreach ($track->students as $student) {
                $trackOf[$student->id] = $track->id;
            }
        }

        return $trackOf;
    }

    /**
     * One week's leaders, group by group: 'all' without tracks; each track's
     * id, and 'general', with them.
     *
     * @param  array{number: int, from: string, to: string}  $week
     * @param  array<int, int>  $trackOf
     * @return array<string, list<array{id: int, name: string, avatar: ?string, initials: string, style: string}>>
     */
    private static function ofWeek(Leaderboard $competition, array $week, array $trackOf): array
    {
        $key = implode(':', ['competition-week-leaders', $competition->id, $competition->updated_at?->timestamp, $week['from'], $week['to'], md5(json_encode($trackOf))]);

        return Cache::remember($key, now()->addMinutes(self::MINUTES), function () use ($competition, $week, $trackOf) {
            $standings = (new LeaderboardService)->getStandings($competition, $week);

            return collect($standings)
                ->groupBy(fn (array $row) => $trackOf === [] ? 'all' : (string) ($trackOf[$row['student']->id] ?? 'general'))
                ->map(fn (Collection $rows) => self::top($rows))
                ->filter()
                ->all();
        });
    }

    /**
     * The students on the most points of a group; none when nobody scored.
     *
     * @param  Collection<int, array{student: Student, score: int|float}>  $rows
     * @return list<array{id: int, name: string, avatar: ?string, initials: string, style: string}>
     */
    private static function top(Collection $rows): array
    {
        $best = $rows->max('score');

        if (! $best || $best <= 0) {
            return [];
        }

        return $rows->filter(fn (array $row) => $row['score'] == $best)
            ->map(fn (array $row) => [
                'id' => $row['student']->id,
                'name' => $row['student']->name,
                'avatar' => $row['student']->avatar_path ? Storage::url($row['student']->avatar_path) : null,
                'initials' => $row['student']->initials(),
                'style' => $row['student']->avatarStyle(),
            ])
            ->values()
            ->all();
    }
}
