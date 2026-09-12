<?php

namespace App\Livewire\Concerns;

use App\Models\Circle;
use App\Models\PromotionRun;
use App\Services\CircleMergeService;
use App\Services\StudentPromotionService;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;

/**
 * The merge dialogue, shared by the manager's circles screen and the
 * supervisor's. Both ask the same question and carry the same risk; only the
 * set of circles they may touch differs.
 */
trait MergesCircles
{
    public ?int $mergeSourceId = null;

    public ?int $mergeTargetId = null;

    public function openMerge(int $circleId): void
    {
        $this->mergeSourceId = $circleId;
        $this->mergeTargetId = null;

        Flux::modal('merge-circle-modal')->show();
    }

    public function mergeCircles(CircleMergeService $merges): void
    {
        $this->validate([
            'mergeSourceId' => 'required|exists:circles,id',
            'mergeTargetId' => 'required|exists:circles,id|different:mergeSourceId',
        ], [
            'mergeTargetId.required' => 'اختر الحلقة التي ستستقبل الطلاب.',
            'mergeTargetId.different' => 'لا تُدمج الحلقة في نفسها.',
        ]);

        try {
            $run = $merges->merge(
                Circle::findOrFail($this->mergeSourceId),
                Circle::findOrFail($this->mergeTargetId),
                Auth::id(),
                $this->mergeableCircleIds(),
            );
        } catch (\RuntimeException $e) {
            Flux::toast($e->getMessage(), variant: 'danger');

            return;
        }

        $this->mergeSourceId = null;
        $this->mergeTargetId = null;
        $this->loadData();

        Flux::modal('merge-circle-modal')->close();
        Flux::toast(__('نُقل '.$run->items()->count().' طالباً. يمكنك التراجع عن الدمج.'), variant: 'success');
    }

    /**
     * Undo a merge, putting every student back in the circle they came from.
     */
    public function revertMerge(int $runId, StudentPromotionService $promotions): void
    {
        $run = PromotionRun::where('type', PromotionRun::MERGE)->find($runId);

        if (! $run || ! $this->mayRevert($run)) {
            Flux::toast(__('لا تملك صلاحية التراجع عن هذا الدمج.'), variant: 'danger');

            return;
        }

        try {
            $promotions->revert($run);
        } catch (\RuntimeException $e) {
            Flux::toast($e->getMessage(), variant: 'danger');

            return;
        }

        $this->loadData();
        Flux::toast(__('أُعيد الطلاب إلى حلقتهم السابقة.'), variant: 'success');
    }

    /**
     * Merges still open to being undone, newest first.
     */
    public function recentMerges()
    {
        $allowed = $this->mergeableCircleIds();

        return PromotionRun::where('type', PromotionRun::MERGE)
            ->where('status', PromotionRun::APPLIED)
            ->when($allowed !== null, fn ($q) => $q->whereHas(
                'items',
                fn ($i) => $i->whereIn('from_circle_id', $allowed)
            ))
            ->withCount('items')
            ->latest('id')
            ->limit(5)
            ->get();
    }

    private function mayRevert(PromotionRun $run): bool
    {
        $allowed = $this->mergeableCircleIds();

        if ($allowed === null) {
            return true;
        }

        return $run->items()->whereNotIn('from_circle_id', $allowed)->doesntExist()
            && $run->items()->whereNotIn('to_circle_id', $allowed)->doesntExist();
    }

    /**
     * Circle ids this role may merge, or null for "all of them".
     *
     * @return array<int, int>|null
     */
    abstract protected function mergeableCircleIds(): ?array;
}
