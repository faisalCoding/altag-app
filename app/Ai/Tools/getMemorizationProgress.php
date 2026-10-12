<?php

namespace App\Ai\Tools;

use App\Models\PromotionRun;
use App\Models\PromotionRunItem;
use App\Models\Student;
use App\Services\MemorizationJourneyService;
use App\Support\ArabicSearch;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * How far the students have come in the Quran (مسيرة الحفظ), as their own
 * pages show it — the memorised range from days graded جيد or better, in
 * pages, juz and percent — how many verses each memorised over a span, and
 * the year's promotions (الترقيات) that moved them between circles.
 */
class getMemorizationProgress implements Tool
{
    private const MAX_STUDENTS = 400;

    private const ACTIONS = [PromotionRunItem::PROMOTE => 'ترقية', PromotionRunItem::GRADUATE => 'تخرّج', PromotionRunItem::HOLD => 'بقاء'];

    private const RUN_STATUSES = [PromotionRun::DRAFT => 'مسودة', PromotionRun::APPLIED => 'مطبّقة', PromotionRun::REVERTED => 'متراجع عنها'];

    public function description(): Stringable|string
    {
        return 'Get memorization progress (مسيرة الحفظ): the active students ranked by how much of the Quran they have memorized '
            .'(pages, juz, percent and the range in words, counting days graded جيد or better), with the verses each memorized between '
            .'"from" and "to" (Y-m-d) when given, and the promotion runs (الترقيات) that moved students between circles, with who was '
            .'promoted, graduated or held. Narrow with "circle" or "stage"; pass "promotion" (part of a run\'s name) for its students.';
    }

    public function handle(Request $request): Stringable|string
    {
        $students = Student::query()
            ->where('status', 'active')
            ->whereNotNull('circle_id')
            ->when($request['circle'] ?? null, fn ($q, $circle) => $q->whereHas('circle', fn ($c) => ArabicSearch::where($c, 'name', $circle)))
            ->when($request['stage'] ?? null, fn ($q, $stage) => $q->whereHas('circle.stage', fn ($s) => ArabicSearch::where($s, 'name', $stage)))
            ->with('circle:id,name')
            ->orderBy('name')
            ->limit(self::MAX_STUDENTS)
            ->get();

        $inPeriod = $this->versesMemorized($students->modelKeys(), $request['from'] ?? null, $request['to'] ?? null);

        $ranking = $students->map(function (Student $student) use ($inPeriod) {
            $pages = $student->memorizedPagesCount();

            return [
                'student' => trim((string) $student->name),
                'circle' => $student->circle?->name,
                'memorized' => $student->memorizationText(),
                'pages' => $pages,
                'juz' => round($pages / 604 * 30, 1),
                'percent' => round($pages / 604 * 100, 1),
                'verses_in_period' => $inPeriod === null ? null : (int) ($inPeriod[$student->id] ?? 0),
            ];
        })->sortByDesc('pages')->values();

        return json_encode([
            'students' => $ranking->all(),
            'period' => $inPeriod === null ? null : ['from' => $request['from'] ?? null, 'to' => $request['to'] ?? null],
            'note' => $students->count() === self::MAX_STUDENTS ? 'Capped at '.self::MAX_STUDENTS.' students; narrow by circle or stage.' : null,
            'promotions' => $this->promotions($request['promotion'] ?? null),
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Verses each student memorized in the span — hifz days graded جيد or
     * better, each verse counted on the first day a range held it, as the
     * student's own monthly count reads them — or null when no span was asked
     * for.
     *
     * @param  array<int, int>  $studentIds
     * @return array<int, int>|null
     */
    private function versesMemorized(array $studentIds, ?string $from, ?string $to): ?array
    {
        if ($from === null && $to === null) {
            return null;
        }

        // Every graded day up to the span's end: those before it tell what was memorized already.
        return DB::table('student_plan_days as days')
            ->join('student_plans as plans', 'plans.id', '=', 'days.student_plan_id')
            ->whereIn('plans.student_id', $studentIds)
            ->where('days.hifz_achievement', '>=', 2)
            ->whereNotNull('days.from_ayah_id')
            ->whereNotNull('days.to_ayah_id')
            ->when($to, fn ($q) => $q->whereDate('days.date', '<=', $to))
            ->orderBy('days.date')
            ->orderBy('days.id')
            ->get(['plans.student_id', 'days.date', 'days.from_ayah_id', 'days.to_ayah_id'])
            ->groupBy('student_id')
            ->map(fn ($days) => (int) collect(MemorizationJourneyService::newVersesByDay($days))
                ->filter(fn (int $count, string $date) => $from === null || $date >= $from)
                ->sum())
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function promotions(?string $name): array
    {
        return PromotionRun::query()
            ->when($name, fn ($q) => ArabicSearch::where($q, 'name', $name))
            ->withCount([
                'items as promoted' => fn ($q) => $q->where('action', PromotionRunItem::PROMOTE),
                'items as graduated' => fn ($q) => $q->where('action', PromotionRunItem::GRADUATE),
                'items as held' => fn ($q) => $q->where('action', PromotionRunItem::HOLD),
            ])
            ->latest()
            ->limit($name ? 1 : 5)
            ->get()
            ->map(function (PromotionRun $run) use ($name) {
                $row = [
                    'name' => $run->name,
                    'type' => $run->type === PromotionRun::MERGE ? 'دمج حلقات' : 'ترقية سنوية',
                    'status' => self::RUN_STATUSES[$run->status] ?? $run->status,
                    'applied_at' => $run->applied_at?->format('Y-m-d'),
                    'promoted' => $run->promoted,
                    'graduated' => $run->graduated,
                    'held' => $run->held,
                ];

                if ($name) {
                    $row['students'] = $run->items()->with('student:id,name', 'fromCircle:id,name', 'toCircle:id,name')->get()
                        ->map(fn (PromotionRunItem $item) => [
                            'student' => trim((string) $item->student?->name),
                            'action' => self::ACTIONS[$item->action] ?? $item->action,
                            'from' => $item->fromCircle?->name,
                            'to' => $item->toCircle?->name,
                        ])->all();
                }

                return $row;
            })->all();
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'circle' => $schema->string()->description('Circle name (حلقة), or part of it.'),
            'stage' => $schema->string()->description('Stage name (مرحلة), or part of it.'),
            'from' => $schema->string()->description('Start of the span to count verses memorized in (Y-m-d).'),
            'to' => $schema->string()->description('End of the span to count verses memorized in (Y-m-d).'),
            'promotion' => $schema->string()->description('Part of a promotion run\'s name, to list its students.'),
        ];
    }
}
