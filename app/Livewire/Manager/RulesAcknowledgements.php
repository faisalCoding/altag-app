<?php

namespace App\Livewire\Manager;

use App\Models\RuleAcknowledgement;
use App\Models\RuleVersion;
use App\Support\CircleRules;
use App\Support\RulesReminder;
use Illuminate\Support\Facades\URL;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Who has given their word on the rules and who still owes it, measured
 * against the last version that asked for it. Owing never locks anyone out,
 * so this is where the manager follows up: a reminder over WhatsApp with a
 * link onto the rules page, and each acknowledgement on paper.
 */
class RulesAcknowledgements extends Component
{
    /** 'all', 'pending' or 'done'. */
    public string $status = 'all';

    /** 'all', 'teacher' or 'supervisor'. */
    public string $role = 'all';

    #[On('rules-published')]
    public function refreshAfterPublishing(): void
    {
        unset($this->binding, $this->people);
    }

    /** The signed link to share, where anyone picks their stage and name to acknowledge. */
    #[Computed]
    public function publicLink(): string
    {
        return URL::signedRoute('rules.public');
    }

    #[Computed]
    public function binding(): ?RuleVersion
    {
        return CircleRules::binding();
    }

    /**
     * Every approved teacher and supervisor, once each, with where they stand.
     *
     * @return list<array{id: int, name: string, roles: list<string>, owed: list<string>, acknowledgement: ?RuleAcknowledgement, reminder: ?string}>
     */
    #[Computed]
    public function people(): array
    {
        $binding = $this->binding;

        return CircleRules::standings()->map(fn (array $standing) => [
            'id' => $standing['person']->id,
            'name' => $standing['person']->name,
            'roles' => $standing['roles'],
            'owed' => $standing['owed'],
            'acknowledgement' => $standing['acknowledgement'],
            // The page of the first role still owed: a supervisor who also
            // teaches reads both from either.
            'reminder' => $standing['owed'] === [] ? null : RulesReminder::whatsappUrl($standing['person'], $standing['owed'][0], $binding),
        ])->all();
    }

    public function render()
    {
        $people = collect($this->people);

        return view('livewire.manager.rules-acknowledgements', [
            'counts' => [
                'all' => $people->count(),
                'done' => $people->where('owed', [])->count(),
            ],
            'shown' => $people
                ->filter(fn (array $person) => match ($this->status) {
                    'pending' => $person['owed'] !== [],
                    'done' => $person['owed'] === [],
                    default => true,
                })
                ->filter(fn (array $person) => $this->role === 'all' || in_array($this->role, $person['roles'], true))
                ->values(),
        ]);
    }
}
