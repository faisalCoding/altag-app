<?php

namespace App\Livewire\Manager;

use App\Models\Circle;
use App\Models\Stage;
use App\Services\PromotionLadder as Ladder;
use Flux\Flux;
use Livewire\Component;

/**
 * Where the manager lays out the order students climb: which stage follows
 * which, and which grade follows which inside a stage.
 *
 * Nothing here moves a student. It only records the shape of the ladder, so the
 * promotion that comes later stands on an order the academy confirmed rather
 * than one guessed from circle names.
 */
class PromotionLadder extends Component
{
    /** @var array<int, int|string|null> stage id => rank */
    public array $stageLevels = [];

    /** @var array<int, int|string|null> circle id => rank */
    public array $circleLevels = [];

    public function mount(): void
    {
        $this->loadLevels();
    }

    public function loadLevels(): void
    {
        $this->stageLevels = Stage::pluck('level', 'id')->all();
        $this->circleLevels = Circle::pluck('level', 'id')->all();
    }

    public function save(): void
    {
        $this->validate([
            'stageLevels.*' => 'nullable|integer|min:1|max:99',
            'circleLevels.*' => 'nullable|integer|min:1|max:99',
        ], [
            'stageLevels.*.integer' => 'رتبة المرحلة يجب أن تكون رقماً.',
            'circleLevels.*.integer' => 'رتبة الحلقة يجب أن تكون رقماً.',
            'stageLevels.*.min' => 'أصغر رتبة هي ١.',
            'circleLevels.*.min' => 'أصغر رتبة هي ١.',
        ]);

        foreach ($this->stageLevels as $id => $level) {
            Stage::where('id', $id)->update(['level' => $this->rank($level)]);
        }

        foreach ($this->circleLevels as $id => $level) {
            Circle::where('id', $id)->update(['level' => $this->rank($level)]);
        }

        $this->loadLevels();

        Flux::toast(__('تم حفظ ترتيب السلّم'), variant: 'success');
    }

    /**
     * An empty box means "off the ladder", which is a null rank rather than a
     * zero — a zero would read as a real rung and quietly join the order.
     */
    private function rank(int|string|null $level): ?int
    {
        return ($level === null || $level === '') ? null : (int) $level;
    }

    public function render()
    {
        $ladder = new Ladder;

        return view('livewire.manager.promotion-ladder', [
            'stages' => Stage::with(['circles' => fn ($q) => $q->withCount('students')->orderByRaw('level is null, level, name')])
                ->orderByRaw('level is null, level, name')
                ->get(),
            'rungs' => $ladder->rungs(),
            'warnings' => $ladder->warnings(),
            'ladder' => $ladder,
        ]);
    }
}
