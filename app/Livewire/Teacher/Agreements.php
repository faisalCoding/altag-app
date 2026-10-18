<?php

namespace App\Livewire\Teacher;

use App\Models\Agreement;
use App\Models\AgreementCircleType;
use App\Models\AgreementRequest;
use App\Models\Circle;
use App\Models\Student;
use App\Support\Agreements as AgreementRules;
use App\Support\HijriDate;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * The agreements of the teacher's circles, in two tabs.
 *
 * - العامة: those the supervisor or the manager published for the circles'
 *   stages — who has given their word, each one's own link to copy or send,
 *   and the kind the agreement goes by in the teacher's circle.
 * - النماذج: templates the teacher sends to students they pick — to them,
 *   their guardians or both, under a kind and a note — as often as needed;
 *   each send has its own link and answer, kept with its date.
 */
class Agreements extends Component
{
    /** 'general' or 'templates'. */
    public string $tab = 'general';

    /** The general agreement on show; the first when none is picked. */
    public ?int $selected = null;

    /** The template to send. */
    public ?int $templateId = null;

    /** @var list<int|string> The students it goes to. */
    public array $recipients = [];

    /** 'student', 'guardian' or 'both'. */
    public string $sendTo = 'both';

    public string $sendType = 'pledge';

    public string $note = '';

    /** One circle of the teacher's for picking students, or '' for all. */
    public string $sendCircle = '';

    /** The sends listed: one template, or '' for all. */
    public string $historyTemplate = '';

    /** 'owed', 'signed', or '' for every send. */
    public string $historyShow = '';

    /** @return Collection<int, Circle> */
    #[Computed]
    public function circles(): Collection
    {
        return auth()->guard('teacher')->user()->circles()->orderBy('circles.name')->get(['circles.id', 'circles.name', 'circles.stage_id']);
    }

    /** @return list<int> */
    private function stageIds(): array
    {
        return $this->circles->pluck('stage_id')->filter()->unique()->values()->all();
    }

    /** @return Collection<int, Agreement> */
    #[Computed]
    public function agreements(): Collection
    {
        return Agreement::where('status', 'published')
            ->where('kind', 'general')
            ->reaching($this->stageIds())
            ->with(['currentVersion', 'stages:id', 'circleTypes'])
            ->orderBy('id')
            ->get()
            ->filter(fn (Agreement $agreement) => $agreement->currentVersion !== null)
            ->values();
    }

    /** @return SupportCollection<int, Agreement> */
    #[Computed]
    public function templates(): SupportCollection
    {
        return AgreementRules::templatesFor($this->stageIds());
    }

    #[Computed]
    public function template(): ?Agreement
    {
        return $this->templates->firstWhere('id', $this->templateId);
    }

    /**
     * The teacher's active students the template may go to: those of the
     * circles in its stages.
     *
     * @return Collection<int, Student>
     */
    #[Computed]
    public function sendable(): Collection
    {
        $template = $this->template;

        if (! $template) {
            return new Collection;
        }

        $stageIds = $template->stageIds();
        $circleIds = $this->circles->filter(fn (Circle $circle) => in_array($circle->stage_id, $stageIds, true))->modelKeys();

        return Student::where('status', 'active')
            ->whereIn('circle_id', $circleIds)
            ->with(['circle:id,name', 'guardian:id,name,phone'])
            ->orderBy('name')
            ->get();
    }

    /** Opening the templates, the first is ready to send: a picker on nothing would show one unpicked. */
    public function updatedTab(): void
    {
        if ($this->tab === 'templates' && ! $this->template && $this->templates->isNotEmpty()) {
            $this->templateId = $this->templates->first()->id;
            $this->updatedTemplateId();
        }
    }

    public function updatedTemplateId(): void
    {
        unset($this->template, $this->sendable);
        $this->recipients = [];
        $this->sendCircle = '';

        if ($template = $this->template) {
            $this->sendTo = $template->currentVersion->audience;
            $this->sendType = $template->currentVersion->type;
        }
    }

    public function send(): void
    {
        $template = $this->template;
        abort_unless($template, 404);

        $this->note = trim($this->note);
        $this->validate([
            'recipients' => ['required', 'array', 'min:1'],
            'recipients.*' => ['integer', Rule::in($this->sendable->modelKeys())],
            'sendTo' => ['required', Rule::in(array_keys(AgreementRules::AUDIENCES))],
            'sendType' => ['required', Rule::in(array_keys(AgreementRules::TYPES))],
            'note' => ['nullable', 'string', 'max:300'],
        ], [
            'recipients.required' => 'اختر طالباً واحداً على الأقل.',
            'recipients.min' => 'اختر طالباً واحداً على الأقل.',
            'note.max' => 'السبب طويل؛ اختصره في ٣٠٠ حرف.',
        ]);

        $students = $this->sendable->whereIn('id', array_map('intval', $this->recipients));
        $sent = AgreementRules::send($template, $students, $this->sendTo, $this->sendType, $this->note, auth()->guard('teacher')->user());

        $this->recipients = [];
        $this->note = '';
        $this->historyTemplate = '';
        $this->historyShow = '';

        Flux::toast('أُرسل '.$template->currentVersion->title.' في '.HijriDate::arabicDigits($sent->count()).' طلب. أرسل لكل واحد رابطه من السجل أدناه.', variant: 'success');
    }

    /** Call off a send not yet answered: its link stops asking for the word. */
    public function cancelRequest(int $requestId): void
    {
        $request = $this->ownRequests()->find($requestId);
        abort_unless($request, 404);

        if ($request->isOpen()) {
            $request->update(['cancelled_at' => now()]);
            Flux::toast('أُلغي الطلب.', variant: 'success');
        }
    }

    /**
     * The sends the teacher follows: to students of their circles, and their
     * own to students since gone to another circle.
     *
     * @return Builder<AgreementRequest>
     */
    private function ownRequests(): Builder
    {
        $studentIds = Student::whereIn('circle_id', $this->circles->modelKeys())->pluck('id');

        return AgreementRequest::where(fn ($query) => $query
            ->whereIn('student_id', $studentIds)
            ->orWhere('sent_by', auth()->guard('teacher')->id()));
    }

    public function setType(int $agreementId, int $circleId, string $type): void
    {
        $agreement = $this->agreements->firstWhere('id', $agreementId);
        $circle = $this->circles->firstWhere('id', $circleId);
        abort_unless($agreement && $circle && in_array($circle->stage_id, $agreement->stageIds(), true), 404);
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
        return $this->tab === 'templates' ? $this->renderTemplates() : $this->renderGeneral();
    }

    private function renderGeneral()
    {
        $agreement = $this->agreements->firstWhere('id', $this->selected) ?? $this->agreements->first();
        $stageIds = $agreement?->stageIds() ?? [];
        $circles = $this->circles->filter(fn (Circle $circle) => in_array($circle->stage_id, $stageIds, true))->values();

        return view('livewire.teacher.agreements', [
            'agreement' => $agreement,
            'circlesOfAgreement' => $circles,
            'rows' => $agreement ? AgreementRules::rows($agreement, AgreementRules::studentsOf($stageIds, $circles->modelKeys())) : [],
            'parties' => $agreement ? AgreementRules::partiesOf($agreement->currentVersion->audience) : [],
            'requests' => null,
        ]);
    }

    private function renderTemplates()
    {
        $requests = $this->ownRequests()
            ->whereNull('cancelled_at')
            ->when($this->historyTemplate !== '', fn ($query) => $query->where('agreement_id', (int) $this->historyTemplate))
            ->when($this->historyShow !== '', fn ($query) => $this->historyShow === 'owed' ? $query->whereNull('signed_at') : $query->whereNotNull('signed_at'))
            ->with(['student.circle:id,name', 'student.guardian:id,name,phone', 'sender:id,name', 'version:id,title'])
            ->latest('sent_at')
            ->latest('id')
            ->limit(300)
            ->get();

        $students = $this->sendable;

        return view('livewire.teacher.agreements', [
            'agreement' => null,
            'circlesOfAgreement' => collect(),
            'rows' => [],
            'parties' => [],
            'requests' => $requests,
            'pickable' => $this->sendCircle !== '' ? $students->where('circle_id', (int) $this->sendCircle)->values() : $students,
            'pickableCircles' => $students->pluck('circle')->unique('id')->sortBy('name')->values(),
        ]);
    }
}
