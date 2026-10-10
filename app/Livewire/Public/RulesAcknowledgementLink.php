<?php

namespace App\Livewire\Public;

use App\Models\RuleAcknowledgement;
use App\Models\RuleVersion;
use App\Models\Stage;
use App\Models\User;
use App\Support\CircleRules;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Renderless;
use Livewire\Component;

/**
 * The rules acknowledged without signing in, through the public link the
 * manager shares: pick the stage, then your name from its teachers and
 * supervisors, read the rules meant for you, and give your word. It is
 * recorded on that person's account, marked as given through the link.
 *
 * Everything the steps need — the stages, their people, who has already
 * given their word, and the text each kind of reader sees — comes with the
 * page, so moving between steps happens in the browser at once. Only the word
 * itself goes to the server.
 *
 * Whoever holds the link can pick any name on it, so the link is signed and
 * the manager's list says which acknowledgements came through it. A name that
 * has already given its word cannot be picked again.
 */
class RulesAcknowledgementLink extends Component
{
    /**
     * Record the word of the person picked. Answers without redrawing the
     * page; the browser moves on with what it is told.
     *
     * @return array{ok: bool, name?: string, message?: string, already?: bool}
     */
    #[Renderless]
    public function acknowledge(int $personId): array
    {
        $key = 'rules-link:'.request()->ip();

        if (RateLimiter::tooManyAttempts($key, 20)) {
            return ['ok' => false, 'message' => 'محاولات كثيرة، أعد المحاولة بعد دقائق.'];
        }

        RateLimiter::hit($key, 600);

        $version = CircleRules::current();
        $person = $this->eligible()->whereKey($personId)->first();

        if (! $version || ! $person) {
            return ['ok' => false, 'message' => 'تعذّر حفظ الإقرار، أعد فتح الرابط وحاول مرة أخرى.'];
        }

        if (! collect(CircleRules::rolesOf($person))->contains(fn (string $role) => CircleRules::owes($person, $role))) {
            return ['ok' => false, 'already' => true, 'message' => 'سبق أن أقرّ صاحب هذا الاسم بالأنظمة.'];
        }

        CircleRules::acknowledge($person, $version, request()->ip(), request()->userAgent(), 'public_link');

        return ['ok' => true, 'name' => $person->name];
    }

    public function render()
    {
        $version = CircleRules::current();

        return view('livewire.public.rules-acknowledgement-link', [
            'version' => $version,
            ...($version ? $this->steps($version) : ['stages' => [], 'texts' => []]),
        ])->layout('layouts.blank')->title('الإقرار بأنظمة الحلقات');
    }

    /**
     * The stages with their people, and the text for each set of roles they
     * hold — read in a handful of queries, whatever the size of the academy.
     *
     * @return array{stages: list<array{id: int, name: string, people: list<array{id: int, name: string, roles: string, owes: bool, text: string}>}>, texts: array<string, list<array{title: string, clauses: list<array{number: int, audience: string, body: string}>}>>}
     */
    private function steps(RuleVersion $version): array
    {
        $people = $this->eligible()
            ->with([
                'roles' => fn ($q) => $q->where('is_approved', true),
                'circles:id,stage_id',
                'stages:id',
            ])
            ->orderBy('name')
            ->get(['id', 'name']);

        $binding = CircleRules::binding();
        $covered = RuleAcknowledgement::whereIn('user_id', $people->modelKeys())
            ->when($binding, fn ($q) => $q->whereHas('version', fn ($v) => $v->where('number', '>=', $binding->number)))
            ->get(['user_id', 'roles'])
            ->groupBy('user_id')
            ->map(fn ($given) => $given->flatMap(fn (RuleAcknowledgement $acknowledgement) => $acknowledgement->roles)->unique()->all());

        $texts = [];
        $byStage = [];

        foreach ($people as $person) {
            $roles = array_values(array_intersect(CircleRules::ROLES, $person->roles->pluck('role')->all()));
            $textKey = implode('+', $roles);
            $texts[$textKey] ??= CircleRules::textFor($version, $roles);

            $entry = [
                'id' => $person->id,
                'name' => $person->name,
                'roles' => collect($roles)->map(fn ($role) => CircleRules::ROLE_LABELS[$role])->implode(' و'),
                'owes' => $binding !== null && array_diff($roles, $covered->get($person->id, [])) !== [],
                'text' => $textKey,
            ];

            $stageIds = collect()
                ->when(in_array('teacher', $roles, true), fn ($ids) => $ids->merge($person->circles->pluck('stage_id')))
                ->when(in_array('supervisor', $roles, true), fn ($ids) => $ids->merge($person->stages->modelKeys()))
                ->filter()
                ->unique();

            foreach ($stageIds as $stageId) {
                $byStage[$stageId][] = $entry;
            }
        }

        $stages = Stage::orderBy('position')->orderBy('id')->get(['id', 'name'])
            ->map(fn (Stage $stage) => ['id' => $stage->id, 'name' => $stage->name, 'people' => $byStage[$stage->id] ?? []])
            ->all();

        return ['stages' => $stages, 'texts' => $texts];
    }

    /** The approved teachers and supervisors, who may give their word here. */
    private function eligible()
    {
        return User::whereHas('roles', fn ($q) => $q->whereIn('role', CircleRules::ROLES)->where('is_approved', true));
    }
}
