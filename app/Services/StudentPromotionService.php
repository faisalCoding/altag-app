<?php

namespace App\Services;

use App\Models\Circle;
use App\Models\Leaderboard;
use App\Models\PromotionRun;
use App\Models\PromotionRunItem;
use App\Models\Student;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Moving a year group, as three separate acts: propose, apply, undo.
 *
 * They are separate because a promotion is not an edit. It touches every active
 * student at once, its exceptions are the rule rather than the rarity — one
 * repeats, one finished early, one stopped coming — and nobody can hold three
 * hundred placements in their head well enough to approve them sight unseen.
 *
 * So the ladder proposes, a person reads it, and only then does anything move.
 */
class StudentPromotionService
{
    public function __construct(private PromotionLadder $ladder = new PromotionLadder) {}

    /**
     * Propose a placement for every active student, from the ladder.
     *
     * Students with no circle are left out entirely rather than guessed at:
     * there is no rung to read, and putting them in as "staying put" would bury
     * a question that needs answering in a list of three hundred answers.
     */
    public function draft(string $name, ?int $createdById = null): PromotionRun
    {
        return DB::transaction(function () use ($name, $createdById) {
            $run = PromotionRun::create([
                'name' => $name,
                'status' => PromotionRun::DRAFT,
                'created_by_id' => $createdById,
            ]);

            $students = Student::where('status', 'active')
                ->whereNotNull('circle_id')
                ->with('circle')
                ->get();

            $remaining = $this->occupancyAfterEveryoneLeaves($students);

            foreach ($students->groupBy('circle_id') as $cohort) {
                foreach ($this->proposeCohort($cohort, $remaining) as $item) {
                    $run->items()->create($item);
                }
            }

            return $run;
        });
    }

    /**
     * What should happen to one circle's students.
     *
     * Taken a cohort at a time rather than a student at a time because where a
     * grade splits into sections the answer is an allocation, not a lookup: send
     * every one of twenty-three to whichever section is currently smallest and
     * it stops being the smallest after the first.
     *
     * @param  Collection<int, Student>  $cohort
     * @param  array<int, int>  $remaining  circle id => students left there after this run
     * @return array<int, array{student_id: int, from_circle_id: int|null, to_circle_id: int|null, from_status: string, action: string}>
     */
    private function proposeCohort(Collection $cohort, array $remaining): array
    {
        $circle = $cohort->first()->circle;

        // Off the ladder — a track rather than a grade, or a circle holding two
        // grades at once. Staying put is the only honest proposal.
        if (! $circle || $circle->level === null) {
            return $this->sameForAll($cohort, null, PromotionRunItem::HOLD);
        }

        $rung = $this->ladder->nextRungFor($circle);

        if ($rung === null || $rung['circles']->isEmpty()) {
            return $this->sameForAll($cohort, null, PromotionRunItem::GRADUATE);
        }

        // A single destination, or a section of the same number as this circle's:
        // the cohort stays together, which is what a school expects.
        $together = $this->ladder->nextCircleFor($circle);

        if ($rung['circles']->count() === 1 || $this->keepsItsSection($circle, $rung['circles'])) {
            return $this->sameForAll($cohort, $together?->id, PromotionRunItem::PROMOTE);
        }

        return $this->spreadAcrossSections($cohort, $rung['circles'], $remaining);
    }

    /**
     * How many students each circle still holds once this run has emptied it.
     *
     * Sections must be balanced against who will be sitting there afterwards,
     * not who is sitting there now: a section's own students are promoted out by
     * the same run, so counting them makes the fuller section look fuller than
     * it will be and the arriving cohort lands lopsided.
     *
     * @param  Collection<int, Student>  $leaving
     * @return array<int, int>
     */
    private function occupancyAfterEveryoneLeaves(Collection $leaving): array
    {
        $stayingPut = $leaving
            ->filter(fn (Student $student) => $student->circle && $student->circle->level === null)
            ->groupBy('circle_id')
            ->map->count();

        return Circle::withCount('students')->get()
            ->mapWithKeys(fn (Circle $circle) => [
                $circle->id => $circle->students_count
                    - ($leaving->where('circle_id', $circle->id)->count() - ($stayingPut[$circle->id] ?? 0)),
            ])
            ->all();
    }

    /**
     * Whether the next rung has a section matching this circle's own number, in
     * which case the cohort follows it instead of being split.
     *
     * @param  Collection<int, Circle>  $sections
     */
    private function keepsItsSection(Circle $circle, Collection $sections): bool
    {
        $next = $this->ladder->nextCircleFor($circle);

        return $next !== null && $sections->contains('id', $next->id) && $this->hasMatchingSectionNumber($circle, $next);
    }

    private function hasMatchingSectionNumber(Circle $from, Circle $to): bool
    {
        $number = fn (Circle $c) => preg_match('/(\d+)\s*$/u', trim(strtr($c->name, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9'])), $m) ? (int) $m[1] : null;

        $fromNumber = $number($from);

        return $fromNumber !== null && $fromNumber === $number($to);
    }

    /**
     * Deal the cohort into the sections so they come out as even as the numbers
     * allow, counting whoever is already there.
     *
     * @param  Collection<int, Student>  $cohort
     * @param  Collection<int, Circle>  $sections
     * @param  array<int, int>  $remaining  circle id => students left there after this run
     * @return array<int, array<string, mixed>>
     */
    private function spreadAcrossSections(Collection $cohort, Collection $sections, array $remaining): array
    {
        $projected = $sections->mapWithKeys(
            fn (Circle $section) => [$section->id => $remaining[$section->id] ?? 0]
        )->all();

        $items = [];

        foreach ($cohort as $student) {
            $target = array_keys($projected, min($projected))[0];
            $projected[$target]++;

            $items[] = $this->item($student, $target, PromotionRunItem::PROMOTE);
        }

        return $items;
    }

    /**
     * @param  Collection<int, Student>  $cohort
     * @return array<int, array<string, mixed>>
     */
    private function sameForAll(Collection $cohort, ?int $toCircleId, string $action): array
    {
        return $cohort->map(fn (Student $student) => $this->item($student, $toCircleId, $action))->all();
    }

    /**
     * @return array{student_id: int, from_circle_id: int|null, to_circle_id: int|null, from_status: string, action: string}
     */
    private function item(Student $student, ?int $toCircleId, string $action): array
    {
        return [
            'student_id' => $student->id,
            'from_circle_id' => $student->circle_id,
            'to_circle_id' => $toCircleId,
            'from_status' => $student->status,
            'action' => $action,
        ];
    }

    /**
     * Carry out a reviewed run.
     */
    public function apply(PromotionRun $run): void
    {
        if (! $run->isDraft()) {
            throw new \RuntimeException('لا تُطبَّق إلا المسوّدات. هذه العملية '.($run->isApplied() ? 'مطبَّقة بالفعل' : 'ملغاة').'.');
        }

        DB::transaction(function () use ($run) {
            foreach ($run->items()->with('student')->get() as $item) {
                $student = $item->student;

                if (! $student) {
                    continue;
                }

                match ($item->action) {
                    PromotionRunItem::PROMOTE => $student->update(['circle_id' => $item->to_circle_id]),
                    PromotionRunItem::GRADUATE => StudentStatusService::changeStatus(
                        $student,
                        'left',
                        null,
                        'تخرّج ضمن ترحيل '.$run->name,
                    ),
                    default => null,
                };
            }

            $run->update(['status' => PromotionRun::APPLIED, 'applied_at' => now()]);
        });
    }

    /**
     * Put every student back where the run found them.
     *
     * Each item recorded the circle and status it moved a student out of, so
     * this restores from the run itself rather than trying to reason backwards
     * from the ladder — which may have been re-ordered since.
     */
    public function revert(PromotionRun $run): void
    {
        if (! $run->isApplied()) {
            throw new \RuntimeException('لا يُتراجَع إلا عن عملية مطبَّقة.');
        }

        DB::transaction(function () use ($run) {
            foreach ($run->items()->with('student')->get() as $item) {
                $student = $item->student;

                if (! $student) {
                    continue;
                }

                if ($item->action === PromotionRunItem::PROMOTE) {
                    $student->update(['circle_id' => $item->from_circle_id]);
                }

                if ($item->action === PromotionRunItem::GRADUATE && $student->status !== $item->from_status) {
                    StudentStatusService::changeStatus(
                        $student,
                        $item->from_status,
                        null,
                        'تراجع عن ترحيل '.$run->name,
                    );
                }
            }

            $run->update(['status' => PromotionRun::REVERTED, 'reverted_at' => now()]);
        });
    }

    /**
     * Active students who hold no circle, so were left out of the draft.
     *
     * @return Collection<int, Student>
     */
    public function studentsWithoutACircle(): Collection
    {
        return Student::where('status', 'active')->whereNull('circle_id')->get();
    }

    /**
     * Competitions running right now.
     *
     * Which competition a student belongs to is decided by their circle, so a
     * promotion mid-season moves people between leaderboards. The run does not
     * refuse — the academy may have good reason — but it says so first.
     *
     * @return Collection<int, Leaderboard>
     */
    public function competitionsInFlight(): Collection
    {
        $today = now('Asia/Riyadh')->format('Y-m-d');

        return Leaderboard::where('is_active', true)
            ->whereDate('start_date', '<=', $today)
            ->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $today))
            ->get();
    }
}
