<?php

namespace App\Livewire\Supervisor;

use App\Models\Agreement;
use App\Models\AgreementRequest;
use App\Models\Stage;
use App\Models\User;
use App\Support\Agreements as AgreementRules;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * الإقرارات: the supervisor writes what the students of their stages, or the
 * students' guardians, give their word on — its title and kind, the stages,
 * who is asked, and its topics, each with a title and its clauses — keeps it
 * as a draft, and publishes it. An edit of a published agreement says whether
 * it asks for the word again. Text in parentheses is highlighted, and text
 * between asterisks stands out (App\Support\AgreementText).
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

    /** @var list<array{title: string, clauses: list<string>}> */
    public array $topics = [['title' => '', 'clauses' => ['']]];

    /** The editor's topics shown as their reader will see them. */
    public bool $previewing = false;

    /** For a published agreement: 'substantive' asks for the word again, 'wording' keeps it. */
    public string $change = 'substantive';

    /** 'general' binds every student of the stages; 'template' waits for a teacher to send it. */
    public string $kind = 'general';

    /** Written for every stage: the manager's alone to choose. */
    public bool $allStages = false;

    /** The role writing here; the manager's page extends this one. */
    protected const ROLE = 'supervisor';

    protected function user(): ?User
    {
        return auth()->guard(static::ROLE)->user();
    }

    /** Whether the page may write an agreement for every stage at once. */
    public function writesForAllStages(): bool
    {
        return static::ROLE === 'manager';
    }

    /** @return Collection<int, Stage> */
    #[Computed]
    public function stages(): Collection
    {
        return $this->user()->stages()->orderBy('stages.name')->get(['stages.id', 'stages.name']);
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
                ->reaching($stageIds)
                ->orWhere('created_by', $this->user()->id))
            ->with(['stages:id,name', 'currentVersion'])
            ->orderByRaw("case status when 'published' then 0 when 'draft' then 1 else 2 end")
            ->latest('id')
            ->get();
    }

    /**
     * An agreement of the page's to change: the manager's are the manager's
     * alone, though they reach the supervisor's stages.
     */
    private function find(int $id): Agreement
    {
        $agreement = $this->agreements->firstWhere('id', $id);
        abort_unless($agreement && $this->mayEdit($agreement), 404);

        return $agreement;
    }

    /** Where an agreement is followed up, on the page's own side. */
    public function followUpUrl(Agreement $agreement): string
    {
        return route(static::ROLE.'.agreements.follow-up', $agreement);
    }

    public function mayEdit(Agreement $agreement): bool
    {
        return $this->writesForAllStages() || $agreement->author_role !== 'manager';
    }

    public function create(): void
    {
        $this->resetErrorBag();
        $this->editingId = null;
        $this->title = '';
        $this->type = 'pledge';
        $this->audience = 'both';
        $this->stageIds = $this->stages->count() === 1 ? [$this->stages->first()->id] : [];
        $this->kind = 'general';
        $this->allStages = false;
        $this->topics = [['title' => '', 'clauses' => ['']]];
        $this->change = 'substantive';
        $this->previewing = false;
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
        $this->kind = $agreement->kind;
        $this->allStages = $agreement->all_stages;
        $this->topics = array_map(
            fn (array $topic) => ['title' => $topic['title'], 'clauses' => $topic['clauses'] ?: ['']],
            AgreementRules::topicsOf($agreement->topics),
        ) ?: [['title' => '', 'clauses' => ['']]];
        $this->change = 'substantive';
        $this->previewing = false;
        $this->editing = true;
    }

    public function cancel(): void
    {
        $this->editing = false;
        $this->editingId = null;
    }

    public function addTopic(): void
    {
        $this->topics[] = ['title' => '', 'clauses' => ['']];
    }

    public function removeTopic(int $topic): void
    {
        unset($this->topics[$topic]);
        $this->topics = array_values($this->topics) ?: [['title' => '', 'clauses' => ['']]];
    }

    public function moveTopic(int $topic, int $by): void
    {
        $this->topics = self::swapped($this->topics, $topic, $topic + $by);
    }

    public function addClause(int $topic): void
    {
        if (isset($this->topics[$topic])) {
            $this->topics[$topic]['clauses'][] = '';
        }
    }

    public function removeClause(int $topic, int $index): void
    {
        if (! isset($this->topics[$topic])) {
            return;
        }

        unset($this->topics[$topic]['clauses'][$index]);
        $this->topics[$topic]['clauses'] = array_values($this->topics[$topic]['clauses']) ?: [''];
    }

    public function moveClause(int $topic, int $index, int $by): void
    {
        if (isset($this->topics[$topic])) {
            $this->topics[$topic]['clauses'] = self::swapped($this->topics[$topic]['clauses'], $index, $index + $by);
        }
    }

    /**
     * @param  list<mixed>  $items
     * @return list<mixed>
     */
    private static function swapped(array $items, int $from, int $to): array
    {
        if (isset($items[$from], $items[$to])) {
            [$items[$from], $items[$to]] = [$items[$to], $items[$from]];
        }

        return $items;
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
        $version = AgreementRules::publish($agreement, $this->user(), $this->change === 'substantive');

        Flux::toast(match (true) {
            ! $wasPublished => 'نُشر الإقرار، ويظهر الآن لمن يُطلب منهم.',
            $version->wasRecentlyCreated && $version->requires_acknowledgement => 'نُشرت التعديلات، ويُطلب الإقرار بها من جديد.',
            $version->wasRecentlyCreated => 'نُشرت التعديلات، وبقيت الإقرارات السابقة قائمة.',
            default => 'لا تعديل على ما نُشر.',
        }, variant: 'success');
        $this->editing = false;
    }

    /**
     * The editor's draft, checked and saved on the agreement. Empty clauses
     * go, and a topic left with neither a title nor a clause.
     */
    private function store(): Agreement
    {
        $this->title = trim($this->title);
        $this->topics = array_values(array_filter(array_map(fn ($topic) => [
            'title' => trim((string) ($topic['title'] ?? '')),
            'clauses' => array_values(array_filter(
                array_map(fn ($clause) => trim((string) $clause), $topic['clauses'] ?? []),
                fn (string $clause) => $clause !== '',
            )),
        ], $this->topics), fn (array $topic) => $topic['title'] !== '' || $topic['clauses'] !== []));

        $this->validate([
            'title' => 'required|string|max:150',
            'type' => ['required', Rule::in(array_keys(AgreementRules::TYPES))],
            'audience' => ['required', Rule::in(array_keys(AgreementRules::AUDIENCES))],
            'kind' => ['required', Rule::in(array_keys(Agreement::KINDS))],
            'stageIds' => [$this->writesForAllStages() && $this->allStages ? 'nullable' : 'required', 'array', $this->writesForAllStages() && $this->allStages ? 'min:0' : 'min:1'],
            'stageIds.*' => ['integer', Rule::in($this->stages->modelKeys())],
            'topics' => 'required|array|min:1|max:30',
            'topics.*.title' => 'required|string|max:200',
            'topics.*.clauses' => 'required|array|min:1|max:60',
            'topics.*.clauses.*' => 'string|max:2000',
        ], [
            'title.required' => 'اكتب عنوان الإقرار.',
            'stageIds.required' => 'اختر مرحلة واحدة على الأقل.',
            'stageIds.min' => 'اختر مرحلة واحدة على الأقل.',
            'topics.required' => 'أضف موضوعاً واحداً على الأقل.',
            'topics.*.title.required' => 'اكتب عنوان هذا الموضوع.',
            'topics.*.clauses.required' => 'اكتب بنداً واحداً على الأقل في هذا الموضوع.',
        ]);

        $values = [
            'title' => $this->title,
            'type' => $this->type,
            'audience' => $this->audience,
            'topics' => $this->topics,
            'kind' => $this->kind,
            'all_stages' => $this->writesForAllStages() && $this->allStages,
        ];

        $agreement = $this->editingId
            ? tap($this->find($this->editingId))->update($values)
            : Agreement::create($values + ['status' => 'draft', 'created_by' => $this->user()->id, 'author_role' => static::ROLE]);

        // Stages of other supervisors stay as they were: only the supervisor's own are theirs to change.
        $own = $this->stages->modelKeys();
        $kept = $agreement->stages()->pluck('stages.id')->diff($own)->all();
        $agreement->stages()->sync($values['all_stages'] ? [] : [...$kept, ...array_map('intval', $this->stageIds)]);

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
        $own = $this->stages->modelKeys();
        $published = $this->agreements->filter(fn (Agreement $agreement) => $agreement->status === 'published');

        // A general agreement: who of the students it binds, here, gave their word.
        $counts = $published->reject->isTemplate()->mapWithKeys(function (Agreement $agreement) use ($own) {
            $students = AgreementRules::studentsOf(array_values(array_intersect($agreement->stageIds(), $own)));
            $standings = AgreementRules::standings($agreement, $students->modelKeys());

            return [$agreement->id => collect(AgreementRules::partiesOf($agreement->currentVersion?->audience ?? $agreement->audience))
                ->mapWithKeys(fn (string $party) => [$party => [
                    'signed' => collect($standings)->filter(fn (array $parties) => $parties[$party] !== null)->count(),
                    'total' => $students->count(),
                ]])->all()];
        });

        // A template: how often teachers sent it to students here, and how often it was answered.
        $students = AgreementRules::studentsOf($own)->modelKeys();
        $sends = AgreementRequest::whereIn('agreement_id', $published->filter->isTemplate()->modelKeys())
            ->whereIn('student_id', $students)
            ->whereNull('cancelled_at')
            ->selectRaw('agreement_id, count(*) as sent, count(signed_at) as signed')
            ->groupBy('agreement_id')
            ->get()
            ->keyBy('agreement_id');

        return view('livewire.supervisor.agreements', ['counts' => $counts, 'sends' => $sends]);
    }
}
