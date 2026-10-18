<?php

namespace App\Livewire\Supervisor;

use App\Models\Agreement;
use App\Models\AgreementRequest;
use App\Models\Circle;
use App\Support\Agreements;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Who has given their word on an agreement across the stages the page
 * oversees. A general one: student by student and party by party, with each
 * one's own link to copy or send by WhatsApp. A template: every time a
 * teacher sent it — to whom, why, and the answer, with the link of a send
 * still waiting.
 */
class AgreementFollowUp extends Component
{
    #[Locked]
    public int $agreementId;

    /** One circle, or '' for all. */
    public string $circle = '';

    /** 'owed', 'signed', or '' for everyone. */
    public string $show = '';

    /** The role following up here; the manager's page extends this one. */
    protected const ROLE = 'supervisor';

    public function mount(int $agreementId): void
    {
        $this->agreementId = $agreementId;
        abort_unless($this->agreement->versions()->exists(), 404);
    }

    /**
     * The stages followed up: those of the agreement's the supervisor oversees.
     *
     * @return list<int>
     */
    protected function stageIds(): array
    {
        $own = auth()->guard('supervisor')->user()->stages()->pluck('stages.id');

        return $own->intersect(Agreement::findOrFail($this->agreementId)->stageIds())->values()->all();
    }

    public function backUrl(): string
    {
        return route(static::ROLE.'.agreements');
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
        $students = Agreements::studentsOf($this->stageIds(), $circleIds);

        return $this->agreement->isTemplate()
            ? $this->renderRequests($students->modelKeys())
            : $this->renderStandings($students);
    }

    private function renderStandings(Collection $students)
    {
        $rows = collect(Agreements::rows($this->agreement, $students));
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
            'requests' => null,
        ]);
    }

    /**
     * @param  array<int, int>  $studentIds
     */
    private function renderRequests(array $studentIds)
    {
        $requests = AgreementRequest::where('agreement_id', $this->agreementId)
            ->whereIn('student_id', $studentIds)
            ->whereNull('cancelled_at')
            ->with(['student.circle:id,name', 'student.guardian:id,name,phone', 'sender:id,name', 'version:id,title'])
            ->latest('sent_at')
            ->get();

        $totals = ['sent' => $requests->count(), 'signed' => $requests->whereNotNull('signed_at')->count(), 'students' => $requests->unique('student_id')->count()];

        if ($this->show !== '') {
            $requests = $requests->filter(fn (AgreementRequest $request) => ($request->signed_at === null) === ($this->show === 'owed'));
        }

        return view('livewire.supervisor.agreement-follow-up', [
            'rows' => [],
            'parties' => [],
            'totals' => $totals,
            'requests' => $requests->values(),
        ]);
    }
}
