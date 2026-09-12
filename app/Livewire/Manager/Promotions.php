<?php

namespace App\Livewire\Manager;

use App\Models\Circle;
use App\Models\PromotionRun;
use App\Models\PromotionRunItem;
use App\Services\StudentPromotionService;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * Where a year group is moved: drafted from the ladder, read by a person,
 * then applied — or put back.
 */
class Promotions extends Component
{
    public ?int $selectedRunId = null;

    public string $newRunName = '';

    public string $confirmRevertInput = '';

    public function mount(): void
    {
        $this->selectedRunId = PromotionRun::latest('id')->value('id');
    }

    public function createRun(StudentPromotionService $promotions): void
    {
        $this->validate([
            'newRunName' => 'required|string|max:255',
        ], [
            'newRunName.required' => 'سمِّ العملية، مثل «١٤٤٨/١٤٤٩».',
        ]);

        $run = $promotions->draft($this->newRunName, Auth::id());

        $this->selectedRunId = $run->id;
        $this->newRunName = '';

        Flux::modal('new-run-modal')->close();
        Flux::toast(__('أُنشئت مسوّدة بـ'.$run->items()->count().' طالباً. راجعها قبل التطبيق.'), variant: 'success');
    }

    /**
     * Change one student's fate within a draft.
     */
    public function setAction(int $itemId, string $action): void
    {
        $item = $this->editableItem($itemId);

        if (! $item) {
            return;
        }

        // Leaving the ladder's suggestion behind means the destination goes too,
        // except where a promotion still needs one.
        $item->update([
            'action' => $action,
            'to_circle_id' => $action === PromotionRunItem::PROMOTE ? $item->to_circle_id : null,
        ]);
    }

    public function setDestination(int $itemId, ?string $circleId): void
    {
        $item = $this->editableItem($itemId);

        if (! $item) {
            return;
        }

        $item->update([
            'to_circle_id' => $circleId ?: null,
            'action' => $circleId ? PromotionRunItem::PROMOTE : PromotionRunItem::HOLD,
        ]);
    }

    public function apply(StudentPromotionService $promotions): void
    {
        $run = $this->currentRun();

        if (! $run) {
            return;
        }

        try {
            $promotions->apply($run);
            Flux::modal('apply-modal')->close();
            Flux::toast(__('تم الترحيل. يمكنك التراجع عنه كاملاً متى شئت.'), variant: 'success');
        } catch (\RuntimeException $e) {
            Flux::toast($e->getMessage(), variant: 'danger');
        }
    }

    public function revert(StudentPromotionService $promotions): void
    {
        $run = $this->currentRun();

        if (! $run) {
            return;
        }

        if ($this->confirmRevertInput !== $run->name) {
            Flux::toast(__('اكتب اسم العملية بالضبط للتأكيد.'), variant: 'danger');

            return;
        }

        try {
            $promotions->revert($run);
            $this->confirmRevertInput = '';
            Flux::modal('revert-modal')->close();
            Flux::toast(__('أُعيد كل طالب إلى حلقته السابقة.'), variant: 'success');
        } catch (\RuntimeException $e) {
            Flux::toast($e->getMessage(), variant: 'danger');
        }
    }

    public function deleteRun(): void
    {
        $run = $this->currentRun();

        if (! $run || ! $run->isDraft()) {
            Flux::toast(__('لا تُحذف إلا المسوّدات.'), variant: 'danger');

            return;
        }

        $run->delete();
        $this->selectedRunId = PromotionRun::latest('id')->value('id');

        Flux::toast(__('حُذفت المسوّدة.'), variant: 'success');
    }

    public function currentRun(): ?PromotionRun
    {
        return $this->selectedRunId ? PromotionRun::find($this->selectedRunId) : null;
    }

    /**
     * An item is only editable while its run is still a draft — an applied run
     * is a record of what happened, not a form.
     */
    private function editableItem(int $itemId): ?PromotionRunItem
    {
        $run = $this->currentRun();

        if (! $run || ! $run->isDraft()) {
            Flux::toast(__('لا تُعدَّل إلا المسوّدات.'), variant: 'danger');

            return null;
        }

        return $run->items()->whereKey($itemId)->first();
    }

    public function render(StudentPromotionService $promotions)
    {
        $run = $this->currentRun();

        $groups = $run
            ? $run->items()
                ->with(['student:id,name', 'fromCircle:id,name', 'toCircle:id,name'])
                ->get()
                ->groupBy('from_circle_id')
            : collect();

        return view('livewire.manager.promotions', [
            'run' => $run,
            'runs' => PromotionRun::withCount('items')->latest('id')->get(),
            'groups' => $groups,
            'circles' => Circle::orderBy('name')->get(['id', 'name']),
            'orphans' => $run?->isDraft() ? $promotions->studentsWithoutACircle() : collect(),
            'competitions' => $run?->isDraft() ? $promotions->competitionsInFlight() : collect(),
        ]);
    }
}
