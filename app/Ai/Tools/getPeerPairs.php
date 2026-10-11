<?php

namespace App\Ai\Tools;

use App\Models\PeerPair;
use App\Models\PlanDayAttempt;
use App\Models\StudentPlanDay;
use App\Services\TeacherSyncSnapshot;
use App\Support\ArabicSearch;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * The mutual recitation (التسميع المتبادل) the circles paired their students
 * in: per circle and day, who recited to whom, the portion, the mistakes the
 * listener counted, and the grade the teacher gave — the official review
 * grade, read from the session of the place's plan day on the pair's date.
 */
class getPeerPairs implements Tool
{
    private const MAX_PAIRS = 300;

    private const GRADES = [3 => 'ممتاز', 2 => 'جيد', 1 => 'مقبول', 0 => 'لم يسمع'];

    public function description(): Stringable|string
    {
        return 'Get the mutual recitation pairs (التسميع المتبادل): per circle how many days and pairs, and for each pair the date, '
            .'the two students, who recited (both in a mutual pair, else the first only), each one\'s portion, the mistakes counted and '
            .'the review grade the teacher gave. Pass "from" and "to" (Y-m-d, today when left out); narrow with "circle" or "stage".';
    }

    public function handle(Request $request): Stringable|string
    {
        $from = ($request['from'] ?? null) ?: TeacherSyncSnapshot::today();
        $to = ($request['to'] ?? null) ?: $from;

        $pairs = PeerPair::query()
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $to)
            ->when($request['circle'] ?? null, fn ($q, $circle) => $q->whereHas('circle', fn ($c) => ArabicSearch::where($c, 'name', $circle)))
            ->when($request['stage'] ?? null, fn ($q, $stage) => $q->whereHas('circle.stage', fn ($s) => ArabicSearch::where($s, 'name', $stage)))
            ->with([
                'circle:id,name', 'first:id,name', 'second:id,name',
                'firstFromAyah.surah', 'firstToAyah.surah', 'secondFromAyah.surah', 'secondToAyah.surah',
            ])
            ->orderBy('date')->orderBy('circle_id')->orderBy('position')
            ->get();

        $grades = $this->grades($pairs);

        return json_encode([
            'from' => $from,
            'to' => $to,
            'circles' => $pairs->groupBy(fn (PeerPair $pair) => $pair->circle?->name ?? 'حلقة محذوفة')
                ->map(fn (Collection $circlePairs) => [
                    'days' => $circlePairs->map(fn (PeerPair $pair) => $this->day($pair))->unique()->count(),
                    'pairs' => $circlePairs->count(),
                    'mutual' => $circlePairs->where('mutual', true)->count(),
                    'average_mistakes' => $this->averageMistakes($circlePairs),
                ])
                ->all(),
            'pairs' => $pairs->take(self::MAX_PAIRS)->map(fn (PeerPair $pair) => [
                'date' => $this->day($pair),
                'circle' => $pair->circle?->name,
                'mutual' => $pair->mutual,
                'first' => $this->place($pair, 'first', $grades),
                'second' => $this->place($pair, 'second', $grades),
            ])->values()->all(),
            'note' => $pairs->count() > self::MAX_PAIRS ? 'Only the first '.self::MAX_PAIRS.' pairs are listed; the circle counts cover all.' : null,
        ], JSON_UNESCAPED_UNICODE);
    }

    /** @return array<string, mixed> */
    private function place(PeerPair $pair, string $place, Collection $grades): array
    {
        $dayId = $pair->{$place.'_day_id'};

        return [
            'student' => trim((string) $pair->{$place}?->name),
            'recites' => $pair->recites($place),
            'portion' => StudentPlanDay::formatAyahRange($pair->{$place.'FromAyah'}, $pair->{$place.'ToAyah'}),
            'mistakes' => $pair->{$place.'_mistakes'},
            'grade' => self::GRADES[$grades->get($dayId.'|'.$this->day($pair))] ?? null,
        ];
    }

    /**
     * The review grade of each place's plan day on its pair's date, keyed "day|date".
     *
     * @param  Collection<int, PeerPair>  $pairs
     * @return Collection<string, int>
     */
    private function grades(Collection $pairs): Collection
    {
        $dayIds = $pairs->flatMap(fn (PeerPair $pair) => [$pair->first_day_id, $pair->second_day_id])->filter()->unique();

        return PlanDayAttempt::query()
            ->whereIn('student_plan_day_id', $dayIds)
            ->where('part', 'review')
            ->whereNotNull('grade')
            ->get(['student_plan_day_id', 'recited_on', 'grade'])
            ->mapWithKeys(fn ($attempt) => [$attempt->student_plan_day_id.'|'.substr((string) $attempt->recited_on, 0, 10) => (int) $attempt->grade]);
    }

    private function day(PeerPair $pair): string
    {
        return substr((string) ($pair->date instanceof \DateTimeInterface ? $pair->date->format('Y-m-d') : $pair->date), 0, 10);
    }

    private function averageMistakes(Collection $pairs): ?float
    {
        $counts = $pairs->flatMap(fn (PeerPair $pair) => array_filter(
            [$pair->first_mistakes, $pair->recites('second') ? $pair->second_mistakes : null],
            fn ($mistakes) => $mistakes !== null,
        ));

        return $counts->isEmpty() ? null : round($counts->avg(), 1);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'from' => $schema->string()->description('First day (Y-m-d). Today when left out.'),
            'to' => $schema->string()->description('Last day (Y-m-d). The same as "from" when left out.'),
            'circle' => $schema->string()->description('Circle name (حلقة), or part of it.'),
            'stage' => $schema->string()->description('Stage name (مرحلة), or part of it.'),
        ];
    }
}
