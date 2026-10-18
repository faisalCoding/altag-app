<?php

namespace App\Livewire\Teacher;

use App\Models\Agreement;
use App\Models\AgreementCircleType;
use App\Models\Circle;
use App\Support\Agreements as AgreementRules;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * The agreements the supervisor published for the teacher's circles: who
 * among the students and their guardians has given their word, each one's
 * own link to copy or send, and the kind the agreement goes by in the
 * teacher's circle — the supervisor's, unless the teacher calls it another.
 */
class Agreements extends Component
{
    /** The agreement on show; the first when none is picked. */
    public ?int $selected = null;

    /** @return Collection<int, Circle> */
    #[Computed]
    public function circles(): Collection
    {
        return auth()->guard('teacher')->user()->circles()->orderBy('circles.name')->get(['circles.id', 'circles.name', 'circles.stage_id']);
    }

    /** @return Collection<int, Agreement> */
    #[Computed]
    public function agreements(): Collection
    {
        $stageIds = $this->circles->pluck('stage_id')->filter()->unique()->values()->all();

        return Agreement::where('status', 'published')
            ->whereHas('stages', fn ($stages) => $stages->whereIn('stages.id', $stageIds))
            ->with(['currentVersion', 'stages:id', 'circleTypes'])
            ->orderBy('id')
            ->get()
            ->filter(fn (Agreement $agreement) => $agreement->currentVersion !== null)
            ->values();
    }

    public function setType(int $agreementId, int $circleId, string $type): void
    {
        $agreement = $this->agreements->firstWhere('id', $agreementId);
        $circle = $this->circles->firstWhere('id', $circleId);
        abort_unless($agreement && $circle && $agreement->stages->contains('id', $circle->stage_id), 404);
        validator(['type' => $type], ['type' => ['nullable', Rule::in(array_keys(AgreementRules::TYPES))]])->validate();

        // The supervisor's own kind again is no kind of the circle's own.
        if ($type === '' || $type === $agreement->currentVersion->type) {
            AgreementCircleType::where('agreement_id', $agreement->id)->where('circle_id', $circle->id)->delete();
        } else {
            AgreementCircleType::updateOrCreate(
                ['agreement_id' => $agreement->id, 'circle_id' => $circle->id],
                ['type' => $type, 'set_by' => auth()->guard('teacher')->id()],
            );
        }

        unset($this->agreements);
        Flux::toast('تغيّر نوع الإقرار في '.$circle->name.'.', variant: 'success');
    }

    public function render()
    {
        $agreement = $this->agreements->firstWhere('id', $this->selected) ?? $this->agreements->first();
        $circles = $agreement ? $this->circles->filter(fn (Circle $circle) => $agreement->stages->contains('id', $circle->stage_id))->values() : collect();

        return view('livewire.teacher.agreements', [
            'agreement' => $agreement,
            'circlesOfAgreement' => $circles,
            'rows' => $agreement ? AgreementRules::rows($agreement, AgreementRules::studentsOf($agreement->stages->modelKeys(), $circles->modelKeys())) : [],
            'parties' => $agreement ? AgreementRules::partiesOf($agreement->currentVersion->audience) : [],
        ]);
    }
}
