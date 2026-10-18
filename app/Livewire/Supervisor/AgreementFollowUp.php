<?php

namespace App\Livewire\Supervisor;

use App\Models\Agreement;
use App\Models\Circle;
use App\Support\Agreements;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Who has given their word on an agreement, student by student and party by
 * party, across the supervisor's stages it binds — with each one's own link
 * to copy or send by WhatsApp.
 */
class AgreementFollowUp extends Component
{
    #[Locked]
    public int $agreementId;

    /** One circle, or '' for all. */
    public string $circle = '';

    /** 'owed', 'signed', or '' for everyone. */
    public string $show = '';

    public function mount(int $agreementId): void
    {
        $this->agreementId = $agreementId;
        abort_unless($this->agreement->versions()->exists(), 404);
    }

    /** @return list<int> */
    private function stageIds(): array
    {
        $own = auth()->guard('supervisor')->user()->stages()->pluck('stages.id');

        return $own->intersect(Agreement::findOrFail($this->agreementId)->stages()->pluck('stages.id'))->values()->all();
    }

    #[Computed]
    public function agreement(): Agreement
    {
        $agreement = Agreement::with(['currentVersion', 'stages:id,name'])->findOrFail($this->agreementId);
        abort_if($this->stageIds() === [], 404);

        return $agreement;
    }

    /** @return Collection<int, Circle> */
    #[Computed]
    public function circles(): Collection
    {
        return Circle::whereIn('stage_id', $this->stageIds())->orderBy('name')->get(['id', 'name']);
    }

    public function render()
    {
        $circleIds = $this->circle !== '' && $this->circles->contains('id', (int) $this->circle) ? [(int) $this->circle] : null;
        $rows = collect(Agreements::rows($this->agreement, Agreements::studentsOf($this->stageIds(), $circleIds)));
        $parties = Agreements::partiesOf($this->agreement->currentVersion->audience);

        $totals = collect($parties)->mapWithKeys(fn (string $party) => [$party => [
            'signed' => $rows->filter(fn (array $row) => $row['parties'][$party]['signature'] !== null)->count(),
            'total' => $rows->count(),
        ]]);

        if ($this->show !== '') {
            $rows = $rows->filter(fn (array $row) => collect($row['parties'])->contains(
                fn (array $standing) => ($standing['signature'] === null) === ($this->show === 'owed')
            ));
        }

        return view('livewire.supervisor.agreement-follow-up', [
            'rows' => $rows->values()->all(),
            'parties' => $parties,
            'totals' => $totals,
        ]);
    }
}
