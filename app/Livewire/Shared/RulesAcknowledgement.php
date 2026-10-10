<?php

namespace App\Livewire\Shared;

use App\Models\RuleAcknowledgement;
use App\Models\RuleVersion;
use App\Models\User;
use App\Support\CircleRules;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The circles' rules as a teacher or a supervisor reads them — the shared
 * clauses and those of every role they hold — and where they give their word.
 *
 * Always open to read again; the word is asked for only while it is owed.
 */
class RulesAcknowledgement extends Component
{
    #[Locked]
    public string $role = 'teacher';

    public bool $agreed = false;

    public function mount(string $role = 'teacher'): void
    {
        abort_unless(in_array($role, CircleRules::ROLES, true) && auth($role)->check(), 403);

        $this->role = $role;
    }

    public function acknowledge(): void
    {
        $this->resetErrorBag();

        if (! $this->agreed) {
            $this->addError('agreed', 'ضع علامة على الإقرار أولاً.');

            return;
        }

        $version = $this->version;

        if (! $version) {
            return;
        }

        CircleRules::acknowledge($this->person(), $version, request()->ip(), request()->userAgent());

        $this->agreed = false;
        unset($this->standing, $this->owes);

        Flux::toast('شكراً لك، حُفظ إقرارك بالأنظمة.', variant: 'success');
    }

    #[Computed]
    public function version(): ?RuleVersion
    {
        return CircleRules::current();
    }

    /** @return list<string> */
    #[Computed]
    public function roles(): array
    {
        return CircleRules::rolesOf($this->person());
    }

    /** @return list<array{title: string, clauses: list<array{number: int, audience: string, body: string}>}> */
    #[Computed]
    public function text(): array
    {
        return $this->version ? CircleRules::textFor($this->version, $this->roles) : [];
    }

    #[Computed]
    public function standing(): ?RuleAcknowledgement
    {
        return CircleRules::standingAcknowledgement($this->person());
    }

    /** Whether any role the person holds still owes the word. */
    #[Computed]
    public function owes(): bool
    {
        return collect($this->roles)->contains(fn (string $role) => CircleRules::owes($this->person(), $role));
    }

    private function person(): User
    {
        return auth($this->role)->user();
    }

    public function render()
    {
        return view('livewire.shared.rules-acknowledgement');
    }
}
