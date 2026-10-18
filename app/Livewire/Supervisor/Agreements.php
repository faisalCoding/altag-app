<?php

namespace App\Livewire\Supervisor;

use App\Models\Agreement;
use App\Models\Stage;
use App\Support\Agreements as AgreementRules;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * الإقرارات: the supervisor writes what the students of their stages, or the
 * students' guardians, give their word on — its title and kind, the stages,
 * who is asked, and its clauses — keeps it as a draft, and publishes it.
 * An edit of a published agreement says whether it asks for the word again.
 */
class Agreements extends Component
{
    /** The agreement in the editor: null for a new one, while the editor is open. */
    public ?int $editingId = null;

    public bool $editing = false;

    public string $title = '';

    public string $type = 'pledge';

    public string $audience = 'both';

    /** @var list<int|string> */
    public array $stageIds = [];

    /** @var list<string> */
    public array $clauses = [''];

    /** For a published agreement: 'substantive' asks for the word again, 'wording' keeps it. */
    public string $change = 'substantive';

    /** @return Collection<int, Stage> */
    #[Computed]
    public function stages(): Collection
    {
        return auth()->guard('supervisor')->user()->stages()->orderBy('stages.name')->get(['stages.id', 'stages.name']);
    }

    /**
     * The agreements of the supervisor's stages, and their own drafts not yet given one.
     *
     * @return Collection<int, Agreement>
     */
    #[Computed]
    public function agreements(): Collection
    {
        $stageIds = $this->stages->modelKeys();

        return Agreement::query()
            ->where(fn ($query) => $query
                ->whereHas('stages', fn ($stages) => $stages->whereIn('stages.id', $stageIds))
                ->orWhere('created_by', auth()->guard('supervisor')->id()))
            ->with(['stages:id,name', 'currentVersion'])
            ->orderByRaw("case status when 'published' then 0 when 'draft' then 1 else 2 end")
            ->latest('id')
            ->get();
    }

    private function find(int $id): Agreement
    {
        $agreement = $this->agreements->firstWhere('id', $id);
        abort_unless($agreement, 404);

        return $agreement;
    }

    public function create(): void
    {
        $this->resetErrorBag();
        $this->editingId = null;
        $this->title = '';
        $this->type = 'pledge';
        $this->audience = 'both';
        $this->stageIds = $this->stages->count() === 1 ? [$this->stages->first()->id] : [];
        $this->clauses = [''];
        $this->change = 'substantive';
        $this->editing = true;
    }

    public function edit(int $id): void
    {
        $agreement = $this->find($id);

        $this->resetErrorBag();
        $this->editingId = $agreement->id;
        $this->title = $agreement->title;
        $this->type = $agreement->type;
        $this->audience = $agreement->audience;
        $this->stageIds = $agreement->stages->modelKeys();
        $this->clauses = $agreement->clauses ?: [''];
        $this->change = 'substantive';
        $this->editing = true;
    }

    public function cancel(): void
    {
        $this->editing = false;
        $this->editingId = null;
    }

    public function addClause(): void
    {
        $this->clauses[] = '';
    }

    public function removeClause(int $index): void
    {
        unset($this->clauses[$index]);
        $this->clauses = array_values($this->clauses) ?: [''];
    }

    public function moveClause(int $index, int $by): void
    {
        $to = $index + $by;

        if (! isset($this->clauses[$index], $this->clauses[$to])) {
            return;
        }

        [$this->clauses[$index], $this->clauses[$to]] = [$this->clauses[$to], $this->clauses[$index]];
    }

    public function saveDraft(): void
    {
        $agreement = $this->store();

        Flux::toast($agreement->status === 'published' ? 'حُفظت التعديلات، ولم تُنشر بعد.' : 'حُفظ الإقرار مسودة.', variant: 'success');
        $this->editing = false;
    }

    public function publish(): void
    {
        $agreement = $this->store();
        $wasPublished = $agreement->versions()->exists();
        $version = AgreementRules::publish($agreement, auth()->guard('supervisor')->user(), $this->change === 'substantive');

        Flux::toast(match (true) {
            ! $wasPublished => 'نُشر الإقرار، ويظهر الآن لمن يُطلب منهم.',
            $version->wasRecentlyCreated && $version->requires_acknowledgement => 'نُشرت التعديلات، ويُطلب الإقرار بها من جديد.',
            $version->wasRecentlyCreated => 'نُشرت التعديلات، وبقيت الإقرارات السابقة قائمة.',
            default => 'لا تعديل على ما نُشر.',
        }, variant: 'success');
        $this->editing = false;
    }

    /**
     * The editor's draft, checked and saved on the agreement.
     */
    private function store(): Agreement
    {
        $this->clauses = array_map(fn ($clause) => trim((string) $clause), $this->clauses);
        $this->title = trim($this->title);

        $this->validate([
            'title' => 'required|string|max:150',
            'type' => ['required', Rule::in(array_keys(AgreementRules::TYPES))],
            'audience' => ['required', Rule::in(array_keys(AgreementRules::AUDIENCES))],
            'stageIds' => 'required|array|min:1',
            'stageIds.*' => ['integer', Rule::in($this->stages->modelKeys())],
            'clauses' => ['array', function ($attribute, $value, $fail) {
                if (array_filter($value, fn ($clause) => $clause !== '') === []) {
                    $fail('اكتب بنداً واحداً على الأقل.');
                }
            }],
            'clauses.*' => 'nullable|string|max:2000',
        ], [
            'title.required' => 'اكتب عنوان الإقرار.',
            'stageIds.required' => 'اختر مرحلة واحدة على الأقل.',
            'stageIds.min' => 'اختر مرحلة واحدة على الأقل.',
        ]);

        $values = [
            'title' => $this->title,
            'type' => $this->type,
            'audience' => $this->audience,
            'clauses' => array_values(array_filter($this->clauses, fn ($clause) => $clause !== '')),
        ];

        $agreement = $this->editingId
            ? tap($this->find($this->editingId))->update($values)
            : Agreement::create($values + ['status' => 'draft', 'created_by' => auth()->guard('supervisor')->id()]);

        // Stages of other supervisors stay as they were: only the supervisor's own are theirs to change.
        $own = $this->stages->modelKeys();
        $kept = $agreement->stages()->pluck('stages.id')->diff($own)->all();
        $agreement->stages()->sync([...$kept, ...array_map('intval', $this->stageIds)]);

        unset($this->agreements);

        return $agreement;
    }

    public function archive(int $id): void
    {
        $this->find($id)->update(['status' => 'archived']);
        unset($this->agreements);
        Flux::toast('أُرشف الإقرار، ولم يعد يُطلب من أحد.', variant: 'success');
    }

    public function restore(int $id): void
    {
        $agreement = $this->find($id);
        $agreement->update(['status' => $agreement->versions()->exists() ? 'published' : 'draft']);
        unset($this->agreements);
    }

    public function delete(int $id): void
    {
        $agreement = $this->find($id);

        // What anyone has given their word on stays on record: archive it instead.
        if ($agreement->versions()->exists()) {
            Flux::toast('نُشر هذا الإقرار، فيُؤرشف ولا يُحذف.', variant: 'warning');

            return;
        }

        $agreement->delete();
        unset($this->agreements);
    }

    public function render()
    {
        $counts = $this->agreements
            ->filter(fn (Agreement $agreement) => $agreement->status === 'published')
            ->mapWithKeys(function (Agreement $agreement) {
                $students = AgreementRules::studentsOf($agreement->stages->modelKeys());
                $standings = AgreementRules::standings($agreement, $students->modelKeys());

                return [$agreement->id => collect(AgreementRules::partiesOf($agreement->currentVersion?->audience ?? $agreement->audience))
                    ->mapWithKeys(fn (string $party) => [$party => [
                        'signed' => collect($standings)->filter(fn (array $parties) => $parties[$party] !== null)->count(),
                        'total' => $students->count(),
                    ]])->all()];
            });

        return view('livewire.supervisor.agreements', ['counts' => $counts]);
    }
}
