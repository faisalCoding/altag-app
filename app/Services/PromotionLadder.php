<?php

namespace App\Services;

use App\Models\Circle;
use App\Models\Stage;
use Illuminate\Support\Collection;

/**
 * The ordered ladder of circles a student climbs, one rung per school year.
 *
 * A rung is the pair (stage rank, circle rank). Circles sharing a rung are
 * sections of one grade, not successive grades — which is the whole reason the
 * ranks are not unique. Promotion moves a student from their rung to the next
 * one in ascending order, crossing into the next stage when their own runs out.
 *
 * Anything with no rank is off the ladder and is never moved automatically.
 */
class PromotionLadder
{
    /**
     * Every rung, in climbing order.
     *
     * @return Collection<int, array{stage: Stage, level: int, circles: Collection<int, Circle>}>
     */
    public function rungs(): Collection
    {
        return $this->rankedStages()
            ->flatMap(function (Stage $stage) {
                return $stage->circles
                    ->whereNotNull('level')
                    ->groupBy('level')
                    ->sortKeys()
                    ->map(fn (Collection $circles, $level) => [
                        'stage' => $stage,
                        'level' => (int) $level,
                        'circles' => $circles->sortBy('name')->values(),
                    ])
                    ->values();
            })
            ->values();
    }

    /**
     * Where a circle's students go next year, or null when the circle sits at
     * the top of the ladder — or off it altogether.
     *
     * @return array{stage: Stage, level: int, circles: Collection<int, Circle>}|null
     */
    public function nextRungFor(Circle $circle): ?array
    {
        if ($circle->level === null) {
            return null;
        }

        $rungs = $this->rungs();

        $position = $rungs->search(
            fn (array $rung) => $rung['stage']->id === $circle->stage_id && $rung['level'] === (int) $circle->level
        );

        return $position === false ? null : $rungs->get($position + 1);
    }

    /**
     * Which section of the next rung a student of this circle should land in.
     *
     * Keeps the student in the same section number where one exists, so the
     * first section of a grade feeds the first section of the next. Otherwise
     * the smallest section takes them, which keeps uneven sections from drifting
     * further apart year on year.
     */
    public function nextCircleFor(Circle $circle): ?Circle
    {
        $rung = $this->nextRungFor($circle);

        if ($rung === null || $rung['circles']->isEmpty()) {
            return null;
        }

        if ($rung['circles']->count() === 1) {
            return $rung['circles']->first();
        }

        $section = $this->sectionNumber($circle);

        if ($section !== null) {
            $sameSection = $rung['circles']->first(fn (Circle $c) => $this->sectionNumber($c) === $section);

            if ($sameSection) {
                return $sameSection;
            }
        }

        return $rung['circles']->sortBy(fn (Circle $c) => $c->students_count ?? $c->students()->count())->first();
    }

    /**
     * Everything that would make a promotion do the wrong thing, phrased for the
     * manager who has to fix it.
     *
     * @return Collection<int, array{level: string, text: string}>
     */
    public function warnings(): Collection
    {
        $warnings = collect();

        $unrankedStages = Stage::whereNull('level')->whereHas('circles')->pluck('name');

        foreach ($unrankedStages as $name) {
            $warnings->push([
                'level' => 'danger',
                'text' => "المرحلة «{$name}» بلا رتبة، فحلقاتها كلها خارج الترحيل.",
            ]);
        }

        $duplicateStageRanks = Stage::whereNotNull('level')
            ->get()
            ->groupBy('level')
            ->filter(fn (Collection $stages) => $stages->count() > 1);

        foreach ($duplicateStageRanks as $level => $stages) {
            $warnings->push([
                'level' => 'danger',
                'text' => 'المرحلتان «'.$stages->pluck('name')->implode('» و«')."» تحملان الرتبة {$level}. رتبة المرحلة يجب أن تكون فريدة.",
            ]);
        }

        // Filtered in PHP: a HAVING on an aggregate column without a GROUP BY is
        // not portable, and the circle count here is small either way.
        $unranked = Circle::whereNull('level')
            ->withCount('students')
            ->get()
            ->filter(fn (Circle $circle) => $circle->students_count > 0);

        foreach ($unranked as $circle) {
            $warnings->push([
                'level' => 'warning',
                'text' => "حلقة «{$circle->name}» بلا رتبة، فلن يُرحَّل طلابها الـ{$circle->students_count} تلقائياً.",
            ]);
        }

        foreach ($this->rungs() as $rung) {
            $empty = $rung['circles']->filter(fn (Circle $c) => ($c->students_count ?? $c->students()->count()) === 0);

            if ($empty->count() === $rung['circles']->count()) {
                $warnings->push([
                    'level' => 'warning',
                    'text' => 'رتبة '.$rung['level'].' في «'.$rung['stage']->name.'» كل حلقاتها فارغة، وسيُرحَّل إليها طلاب الرتبة السابقة.',
                ]);
            }
        }

        return $warnings;
    }

    /**
     * Stages that hold a rank, in order, with their circles already loaded.
     *
     * @return Collection<int, Stage>
     */
    private function rankedStages(): Collection
    {
        return Stage::whereNotNull('level')
            ->with(['circles' => fn ($q) => $q->withCount('students')])
            ->orderBy('level')
            ->get();
    }

    /**
     * The section number trailing a circle's name — the "2" of "اولى متوسط 2",
     * in Arabic-Indic digits too. Null when the name carries no section at all.
     */
    private function sectionNumber(Circle $circle): ?int
    {
        $name = strtr($circle->name, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);

        return preg_match('/(\d+)\s*$/u', trim($name), $matches) ? (int) $matches[1] : null;
    }
}
