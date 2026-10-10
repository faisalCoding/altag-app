<?php

namespace App\Livewire\Public;

use App\Models\RuleVersion;
use App\Models\Stage;
use App\Models\User;
use App\Support\CircleRules;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * The rules acknowledged without signing in, through the public link the
 * manager shares: pick the stage, then your name from its teachers and
 * supervisors, read the rules meant for you, and give your word. It is
 * recorded on that person's account, marked as given through the link.
 *
 * Whoever holds the link can pick any name on it, so the link is signed and
 * the manager's list says which acknowledgements came through it. A name that
 * has already given its word is shown as such and cannot be picked again.
 */
class RulesAcknowledgementLink extends Component
{
    public ?int $stageId = null;

    public ?int $personId = null;

    public bool $agreed = false;

    /** The name of whoever has just given their word, for the thanks. */
    public ?string $doneName = null;

    public function chooseStage(int $stageId): void
    {
        $this->stageId = Stage::whereKey($stageId)->exists() ? $stageId : null;
        $this->personId = null;
        $this->agreed = false;
        unset($this->people, $this->owing);
    }

    public function choosePerson(int $personId): void
    {
        $person = $this->people->firstWhere('id', $personId);

        $this->personId = $person && $this->owes($person) ? $person->id : null;
        $this->agreed = false;
        $this->resetErrorBag();
    }

    public function back(): void
    {
        if ($this->personId !== null) {
            $this->personId = null;
        } else {
            $this->stageId = null;
        }

        $this->agreed = false;
    }

    public function startOver(): void
    {
        $this->reset();
    }

    public function acknowledge(): void
    {
        $this->resetErrorBag();

        if (! $this->agreed) {
            $this->addError('agreed', 'ضع علامة على الإقرار أولاً.');

            return;
        }

        $key = 'rules-link:'.request()->ip();

        if (RateLimiter::tooManyAttempts($key, 20)) {
            $this->addError('agreed', 'محاولات كثيرة، أعد المحاولة بعد دقائق.');

            return;
        }

        RateLimiter::hit($key, 600);

        $person = $this->person;
        $version = $this->version;

        if (! $person || ! $version || ! $this->owes($person)) {
            $this->personId = null;

            return;
        }

        CircleRules::acknowledge($person, $version, request()->ip(), request()->userAgent(), 'public_link');

        $this->doneName = $person->name;
        $this->personId = null;
        $this->agreed = false;
        unset($this->people, $this->owing);
    }

    #[Computed]
    public function version(): ?RuleVersion
    {
        return CircleRules::current();
    }

    /** @return Collection<int, Stage> */
    #[Computed]
    public function stages(): Collection
    {
        return Stage::orderBy('position')->orderBy('id')->get(['id', 'name']);
    }

    /** @return Collection<int, User> */
    #[Computed]
    public function people(): Collection
    {
        return $this->stageId === null ? new Collection : CircleRules::peopleOfStage($this->stageId);
    }

    #[Computed]
    public function person(): ?User
    {
        return $this->personId === null ? null : $this->people->firstWhere('id', $this->personId);
    }

    /** @return list<array{title: string, clauses: list<array{number: int, audience: string, body: string}>}> */
    #[Computed]
    public function text(): array
    {
        return $this->person && $this->version ? CircleRules::textFor($this->version, CircleRules::rolesOf($this->person)) : [];
    }

    /**
     * The people of the stage who still owe their word.
     *
     * @return list<int>
     */
    #[Computed]
    public function owing(): array
    {
        return $this->people->filter(fn (User $person) => $this->owes($person))->modelKeys();
    }

    /** Whether any role the person holds still owes their word. */
    private function owes(User $person): bool
    {
        return collect(CircleRules::rolesOf($person))->contains(fn (string $role) => CircleRules::owes($person, $role));
    }

    public function render()
    {
        return view('livewire.public.rules-acknowledgement-link')
            ->layout('layouts.blank')
            ->title('الإقرار بأنظمة الحلقات');
    }
}
