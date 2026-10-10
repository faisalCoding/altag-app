<?php

namespace App\Livewire\Manager;

use App\Models\RuleClause;
use App\Models\RuleSection;
use App\Models\RuleVersion;
use App\Support\CircleRules;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * The manager's draft of the circles' rules, and its versions.
 *
 * Every edit is kept as it is made; nothing reaches teachers and supervisors
 * until the draft is published. Publishing asks whether the change is one of
 * substance, which asks everyone for their word again, or of wording, which
 * leaves the word already given standing.
 */
class RulesEditor extends Component
{
    /** @var array<int|string, string> section titles, by section id */
    public array $titles = [];

    /** @var array<int|string, string> clause bodies, by clause id */
    public array $bodies = [];

    /** @var array<int|string, string> clause audiences, by clause id */
    public array $audiences = [];

    /** 'substantive' asks everyone again; 'wording' does not. */
    public string $kind = 'substantive';

    public string $notes = '';

    public function mount(): void
    {
        $this->load();
    }

    public function updatedTitles(string $value, string $key): void
    {
        RuleSection::whereKey((int) $key)->update(['title' => mb_substr(trim($value), 0, 200)]);
    }

    public function updatedBodies(string $value, string $key): void
    {
        RuleClause::whereKey((int) $key)->update(['body' => mb_substr(trim($value), 0, 2000)]);
    }

    public function updatedAudiences(string $value, string $key): void
    {
        if (! array_key_exists($value, RuleClause::AUDIENCES)) {
            $this->audiences[$key] = RuleClause::whereKey((int) $key)->value('audience') ?? 'shared';

            return;
        }

        RuleClause::whereKey((int) $key)->update(['audience' => $value]);
    }

    public function addSection(): void
    {
        $section = RuleSection::create([
            'title' => 'قسم جديد',
            'position' => (int) RuleSection::max('position') + 1,
        ]);
        $section->clauses()->create(['audience' => 'shared', 'body' => '', 'position' => 1]);

        $this->load();
    }

    public function addClause(int $sectionId): void
    {
        $section = RuleSection::findOrFail($sectionId);
        $section->clauses()->create([
            'audience' => 'shared',
            'body' => '',
            'position' => (int) $section->clauses()->max('position') + 1,
        ]);

        $this->load();
    }

    public function removeClause(int $clauseId): void
    {
        RuleClause::whereKey($clauseId)->delete();
        $this->load();
    }

    public function removeSection(int $sectionId): void
    {
        RuleSection::whereKey($sectionId)->delete();
        $this->load();
    }

    /** Move a section one place up (-1) or down (1). */
    public function moveSection(int $sectionId, int $step): void
    {
        $this->move(RuleSection::orderBy('position')->orderBy('id')->get(), $sectionId, $step);
    }

    /** Move a clause one place up (-1) or down (1) within its section. */
    public function moveClause(int $clauseId, int $step): void
    {
        $clause = RuleClause::findOrFail($clauseId);

        $this->move(RuleClause::where('rule_section_id', $clause->rule_section_id)->orderBy('position')->orderBy('id')->get(), $clauseId, $step);
    }

    public function openPublish(): void
    {
        if (CircleRules::draft() === []) {
            Flux::toast('اكتب بنداً واحداً على الأقل قبل النشر.', variant: 'warning');

            return;
        }

        $this->kind = 'substantive';
        $this->notes = '';
        Flux::modal('publish-rules')->show();
    }

    public function publish(): void
    {
        $this->validate([
            'kind' => ['required', 'in:substantive,wording'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        if (CircleRules::draft() === []) {
            return;
        }

        $version = CircleRules::publish(auth('manager')->user(), $this->kind === 'substantive', $this->notes);

        Flux::modal('publish-rules')->close();
        Flux::toast(
            $version->requires_acknowledgement
                ? 'نُشر الإصدار '.$version->number.'، وأُرسل إلى المعلمين والمشرفين ليُقِرّوا به.'
                : 'نُشر الإصدار '.$version->number.'، وبقيت الإقرارات السابقة سارية.',
            variant: 'success',
        );

        unset($this->versions, $this->unpublished);
        $this->dispatch('rules-published');
    }

    /** @return Collection<int, RuleSection> */
    #[Computed]
    public function sections(): Collection
    {
        return RuleSection::with('clauses')->orderBy('position')->orderBy('id')->get();
    }

    /** @return Collection<int, RuleVersion> */
    #[Computed]
    public function versions(): Collection
    {
        return RuleVersion::withCount('acknowledgements')->orderByDesc('number')->get();
    }

    /** Whether the draft holds edits no version carries yet. */
    #[Computed]
    public function unpublished(): bool
    {
        return CircleRules::draft() !== (CircleRules::current()?->content ?? []);
    }

    /**
     * Swap a row with its neighbour, numbering the list afresh so positions
     * written before ordering existed — or tied — move as they are shown.
     *
     * @param  Collection<int, RuleSection|RuleClause>  $rows
     */
    private function move(Collection $rows, int $id, int $step): void
    {
        $ids = $rows->modelKeys();
        $from = array_search($id, $ids, true);
        $to = $from === false ? false : $from + $step;

        if ($from === false || $to < 0 || $to >= count($ids)) {
            return;
        }

        [$ids[$from], $ids[$to]] = [$ids[$to], $ids[$from]];

        foreach ($ids as $position => $rowId) {
            $rows->firstWhere('id', $rowId)->update(['position' => $position + 1]);
        }

        $this->load();
    }

    private function load(): void
    {
        unset($this->sections, $this->unpublished);

        $this->titles = $this->sections->mapWithKeys(fn (RuleSection $section) => [$section->id => $section->title])->all();
        $this->bodies = [];
        $this->audiences = [];

        foreach ($this->sections as $section) {
            foreach ($section->clauses as $clause) {
                $this->bodies[$clause->id] = $clause->body;
                $this->audiences[$clause->id] = $clause->audience;
            }
        }
    }

    public function render()
    {
        return view('livewire.manager.rules-editor');
    }
}
