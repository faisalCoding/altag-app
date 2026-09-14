<?php

namespace App\Livewire\Supervisor;

use App\Models\Stage;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * The rules a supervisor sets for their own stages.
 *
 * Scoped per stage rather than kept as one switch for the academy, because a
 * supervisor holds particular stages: a single setting would let one of them
 * change how teachers work in circles they do not supervise.
 */
class Settings extends Component
{
    /** @var array<int, bool> stage id => requires a reason on an off-day edit */
    public array $requireEditReason = [];

    public function mount(): void
    {
        $this->loadStages();
    }

    public function loadStages(): void
    {
        $this->requireEditReason = $this->stages()
            ->mapWithKeys(fn (Stage $stage) => [$stage->id => (bool) $stage->require_edit_reason])
            ->all();
    }

    /**
     * Toggle the rule for one stage, refusing any stage this supervisor does
     * not hold — the id arrives from a browser like anything else.
     */
    public function toggleReason(int $stageId): void
    {
        $stage = $this->stages()->firstWhere('id', $stageId);

        if (! $stage) {
            Flux::toast(__('هذه المرحلة خارج نطاق صلاحياتك.'), variant: 'danger');

            return;
        }

        $stage->update(['require_edit_reason' => ! $stage->require_edit_reason]);

        $this->requireEditReason[$stageId] = (bool) $stage->require_edit_reason;

        Flux::toast(
            $stage->require_edit_reason
                ? __('صار ذكر السبب إلزامياً في «'.$stage->name.'».')
                : __('أُلغي إلزام ذكر السبب في «'.$stage->name.'».'),
            variant: 'success',
        );
    }

    /**
     * @return Collection<int, Stage>
     */
    public function stages(): Collection
    {
        return Auth::guard('supervisor')->user()->stages()->orderBy('name')->get();
    }

    public function render()
    {
        return view('livewire.supervisor.settings', [
            'stages' => $this->stages(),
        ])->layout('layouts.role-shell');
    }
}
