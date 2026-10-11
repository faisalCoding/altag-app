<?php

namespace App\Ai\Tools;

use App\Models\Attendance;
use App\Models\GamificationTeam;
use App\Models\GamificationTrack;
use App\Models\Leaderboard;
use App\Support\ArabicSearch;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class getCompetitionGroups implements Tool
{
    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'Get how a competition splits its students into groups, and each group\'s attendance. Two kinds of group exist: '
            .'teams (الفرق/المجموعات), which compete against each other and share coins, and tracks (المسارات), which rank students '
            .'separately by level. Returns every group with its members and, when "from" is given, that group\'s attendance over the '
            .'period including the average number of members present per recorded day. '
            .'This is the only tool that maps students to competition groups — use it for any question about groups rather than '
            .'reading student profiles one by one. Groups cut across circles: one group may hold students from several circles.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $title = trim((string) ($request['competition'] ?? null));

        if ($title === '') {
            return json_encode([
                'error' => 'A competition title is required.',
                'available_competitions' => Leaderboard::orderByDesc('start_date')->pluck('title')->all(),
            ], JSON_UNESCAPED_UNICODE);
        }

        $competitions = ArabicSearch::where(Leaderboard::query(), 'title', $title)->get();

        if ($competitions->isEmpty()) {
            return json_encode([
                'error' => 'No competition matches "'.$title.'".',
                'available_competitions' => Leaderboard::orderByDesc('start_date')->pluck('title')->all(),
            ], JSON_UNESCAPED_UNICODE);
        }

        if ($competitions->count() > 1) {
            return json_encode([
                'ambiguous' => true,
                'message' => 'Several competitions match; ask the user which one is meant.',
                'matches' => $competitions->pluck('title')->all(),
            ], JSON_UNESCAPED_UNICODE);
        }

        $competition = $competitions->first();
        $kind = (string) (($request['kind'] ?? null) ?: 'both');
        $from = $request['from'] ?? null;
        $to = $request['to'] ?? null;
        $includeMembers = (bool) ($request['include_members'] ?? null);

        $result = [
            'competition' => $competition->title,
            'competition_start' => $competition->start_date?->format('Y-m-d'),
            'competition_end' => $competition->end_date?->format('Y-m-d'),
            'attendance_period' => $from || $to
                ? ['from' => $from, 'to' => $to]
                : 'No period given, so no attendance was counted. Pass "from" (and optionally "to") to get it.',
        ];

        if ($kind === 'team' || $kind === 'both') {
            $result['teams'] = $this->groups(
                GamificationTeam::where('leaderboard_id', $competition->id)->with('students:id,name,circle_id')->orderBy('name')->get(),
                'team',
                $from,
                $to,
                $includeMembers,
            );
        }

        if ($kind === 'track' || $kind === 'both') {
            $result['tracks'] = $this->groups(
                GamificationTrack::where('leaderboard_id', $competition->id)->with('students:id,name,circle_id')->orderBy('sort_order')->get(),
                'track',
                $from,
                $to,
                $includeMembers,
            );
        }

        return json_encode($result, JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param  Collection<int, GamificationTeam|GamificationTrack>  $groups
     * @return array<int, array<string, mixed>>
     */
    private function groups(Collection $groups, string $label, ?string $from, ?string $to, bool $includeMembers): array
    {
        return $groups->map(function ($group) use ($label, $from, $to, $includeMembers) {
            $members = $group->students;

            $row = [
                $label => $group->name,
                'members_count' => $members->count(),
            ];

            if ($includeMembers) {
                $row['members'] = $members->pluck('name')->all();
            }

            if ($from !== null || $to !== null) {
                $row['attendance'] = $this->attendance($members->pluck('id')->all(), $from, $to);
            }

            return $row;
        })->all();
    }

    /**
     * Attendance of one group's members over the period, with the average
     * number present per day that actually has records — which is what
     * "how many of this group attend" means in practice.
     *
     * @param  array<int, int>  $studentIds
     * @return array<string, mixed>
     */
    private function attendance(array $studentIds, ?string $from, ?string $to): array
    {
        if ($studentIds === []) {
            return ['note' => 'This group has no members.'];
        }

        $query = Attendance::whereIn('student_id', $studentIds);

        if ($from !== null) {
            $query->whereDate('date', '>=', $from);
        }

        if ($to !== null) {
            $query->whereDate('date', '<=', $to);
        }

        $records = $query->get(['date', 'status']);

        if ($records->isEmpty()) {
            return ['note' => 'No attendance was recorded for this group in the period.'];
        }

        $counts = ['حاضر' => 0, 'غائب' => 0, 'متأخر' => 0, 'مستأذن' => 0];

        foreach ($records as $record) {
            $label = match ($record->status) {
                'present' => 'حاضر',
                'absent' => 'غائب',
                'late' => 'متأخر',
                'excused' => 'مستأذن',
                default => null,
            };

            if ($label !== null) {
                $counts[$label]++;
            }
        }

        $days = $records->pluck('date')->map(fn ($date) => $date instanceof \DateTimeInterface
            ? $date->format('Y-m-d')
            : (string) $date)->unique()->count();

        // Late still means the student showed up, so it counts as attending.
        $attended = $counts['حاضر'] + $counts['متأخر'];
        $total = array_sum($counts);

        return $counts + [
            'days_with_records' => $days,
            'average_attending_per_day' => $days > 0 ? round($attended / $days, 1) : 0,
            'attendance_rate_percentage' => $total > 0 ? round($attended / $total * 100, 1) : 0,
            'counts_late_as_attending' => true,
        ];
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'competition' => $schema->string()->description('Competition title, or part of it.')->required(),
            'kind' => $schema->string()->enum(['team', 'track', 'both'])
                ->description('team=الفرق/المجموعات, track=المسارات. Defaults to both.'),
            'from' => $schema->string()->description('Start of the attendance period (Y-m-d). Without it no attendance is counted.'),
            'to' => $schema->string()->description('End of the attendance period (Y-m-d). Defaults to the latest record.'),
            'include_members' => $schema->boolean()->description('List each group\'s member names, not just how many.'),
        ];
    }
}
