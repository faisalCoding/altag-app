<?php

namespace App\Livewire\Supervisor;

use App\Models\Stage;
use App\Rules\WhatsappGroupLink;
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

    /** @var array<int, string> stage id => the WhatsApp group its absence messages go to */
    public array $whatsappGroupUrls = [];

    /** @var array<int, bool> stage id => memorises the mutun (hadith texts) */
    public array $hadithEnabled = [];

    /** @var array<int, bool> stage id => memorises the odes */
    public array $odesEnabled = [];

    public function mount(): void
    {
        $this->loadStages();
    }

    public function loadStages(): void
    {
        $stages = $this->stages();

        $this->requireEditReason = $stages
            ->mapWithKeys(fn (Stage $stage) => [$stage->id => (bool) $stage->require_edit_reason])
            ->all();

        $this->whatsappGroupUrls = $stages
            ->mapWithKeys(fn (Stage $stage) => [$stage->id => (string) $stage->whatsapp_group_url])
            ->all();

        $this->hadithEnabled = $stages
            ->mapWithKeys(fn (Stage $stage) => [$stage->id => (bool) $stage->hadith_enabled])
            ->all();

        $this->odesEnabled = $stages
            ->mapWithKeys(fn (Stage $stage) => [$stage->id => (bool) $stage->odes_enabled])
            ->all();
    }

    /**
     * Turn the mutun on or off for one stage. Off hides them from the teacher's
     * tasmeeh and the student's pages; the plans and grades already recorded
     * are kept, and come back as they were when it is turned on again.
     */
    public function toggleHadith(int $stageId): void
    {
        $this->toggleMemorisation($stageId, 'hadith_enabled', 'المتون');
    }

    /** Turn the odes on or off for one stage, on the same terms as the mutun. */
    public function toggleOdes(int $stageId): void
    {
        $this->toggleMemorisation($stageId, 'odes_enabled', 'المنظومات');
    }

    private function toggleMemorisation(int $stageId, string $column, string $label): void
    {
        $stage = $this->stages()->firstWhere('id', $stageId);

        if (! $stage) {
            Flux::toast(__('هذه المرحلة خارج نطاق صلاحياتك.'), variant: 'danger');

            return;
        }

        $stage->update([$column => ! $stage->{$column}]);

        if ($column === 'hadith_enabled') {
            $this->hadithEnabled[$stageId] = (bool) $stage->hadith_enabled;
        } else {
            $this->odesEnabled[$stageId] = (bool) $stage->odes_enabled;
        }

        Flux::toast(
            $stage->{$column}
                ? __('فُعِّلت :what في «:stage».', ['what' => $label, 'stage' => $stage->name])
                : __('أُخفيت :what من «:stage».', ['what' => $label, 'stage' => $stage->name]),
            variant: 'success',
        );
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
     * Save the WhatsApp group a stage's teachers paste their absence message
     * into, or clear it when the field is emptied. A circle given a group of
     * its own on the circles page keeps that one.
     */
    public function saveWhatsappGroupUrl(int $stageId): void
    {
        $stage = $this->stages()->firstWhere('id', $stageId);

        if (! $stage) {
            Flux::toast(__('هذه المرحلة خارج نطاق صلاحياتك.'), variant: 'danger');

            return;
        }

        $this->whatsappGroupUrls[$stageId] = WhatsappGroupLink::format($this->whatsappGroupUrls[$stageId] ?? null) ?? '';

        $this->validate([
            "whatsappGroupUrls.{$stageId}" => ['nullable', 'string', 'max:255', new WhatsappGroupLink],
        ]);

        $stage->update(['whatsapp_group_url' => $this->whatsappGroupUrls[$stageId] ?: null]);

        Flux::toast(
            $stage->whatsapp_group_url
                ? __('حُفظت مجموعة الواتساب لـ «:stage».', ['stage' => $stage->name])
                : __('أُزيلت مجموعة الواتساب من «:stage».', ['stage' => $stage->name]),
            variant: 'success',
        );
    }

    /**
     * @return Collection<int, Stage>
     */
    public function stages(): Collection
    {
        return Auth::guard('supervisor')->user()->stages()->get();
    }

    public function render()
    {
        return view('livewire.supervisor.settings', [
            'stages' => $this->stages(),
        ])->layout('layouts.role-shell');
    }
}
