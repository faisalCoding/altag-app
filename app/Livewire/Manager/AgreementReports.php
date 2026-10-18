<?php

namespace App\Livewire\Manager;

use App\Models\Agreement;
use App\Models\AgreementRequest;
use App\Models\Stage;
use App\Models\Student;
use App\Models\Teacher;
use App\Support\Agreements;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * تقارير الإقرارات, over every stage.
 *
 * - عام: each general agreement's rate party by party, one of them circle by
 *   circle; and the templates — how often they were sent and answered,
 *   template by template and teacher by teacher.
 * - فردي: one student — where they stand on every general agreement their
 *   stage asks, and every template sent them or their guardian, with dates.
 */
class AgreementReports extends Component
{
    /** 'general' or 'individual'. */
    public string $mode = 'general';

    /** One stage, or '' for all. */
    public string $stage = '';

    /** The general agreement broken down by circle; the first when none is picked. */
    public ?int $agreementId = null;

    /** One template, or '' for all, in the sends report. */
    public string $template = '';

    public string $search = '';

    #[Locked]
    public ?int $studentId = null;

    /** @return Collection<int, Stage> */
    #[Computed]
    public function stages(): Collection
    {
        return Stage::orderBy('name')->get(['id', 'name']);
    }

    /** @return SupportCollection<int, Agreement> */
    #[Computed]
    public function generalAgreements(): SupportCollection
    {
        return Agreement::where('status', 'published')
            ->where('kind', 'general')
            ->with(['currentVersion', 'stages:id,name'])
            ->orderBy('id')
            ->get()
            ->filter(fn (Agreement $agreement) => $agreement->currentVersion !== null)
            ->values();
    }

    /**
     * Every template ever published: an archived one's sends stay on record.
     *
     * @return SupportCollection<int, Agreement>
     */
    #[Computed]
    public function templates(): SupportCollection
    {
        return Agreement::where('kind', 'template')
            ->whereHas('versions')
            ->with('currentVersion')
            ->orderBy('title')
            ->get();
    }

    /** @return Collection<int, Student> */
    #[Computed]
    public function matches(): Collection
    {
        $search = trim($this->search);

        if (mb_strlen($search) < 2) {
            return new Collection;
        }

        return Student::where('name', 'like', '%'.$search.'%')
            ->with('circle:id,name')
            ->orderByRaw("case status when 'active' then 0 else 1 end")
            ->orderBy('name')
            ->limit(12)
            ->get();
    }

    public function pickStudent(int $studentId): void
    {
        $this->studentId = Student::findOrFail($studentId)->id;
        $this->search = '';
        unset($this->matches);
    }

    public function clearStudent(): void
    {
        $this->studentId = null;
    }

    public function render()
    {
        return view('livewire.manager.agreement-reports', $this->mode === 'individual' ? $this->individual() : $this->general());
    }

    /**
     * @return array<string, mixed>
     */
    private function general(): array
    {
        $stageIds = $this->stage !== '' && $this->stages->contains('id', (int) $this->stage) ? [(int) $this->stage] : $this->stages->modelKeys();
        $students = Agreements::studentsOf($stageIds);
        $stageNames = $this->stages->pluck('name', 'id');

        // Each general agreement: who of the students it binds here gave their word.
        $overview = $this->generalAgreements->map(function (Agreement $agreement) use ($students) {
            $bound = $agreement->stageIds();
            $asked = $students->filter(fn (Student $student) => in_array($student->effective_stage_id, $bound, true))->values();
            $standings = Agreements::standings($agreement, $asked->modelKeys());
            $parties = Agreements::partiesOf($agreement->currentVersion->audience);

            return [
                'agreement' => $agreement,
                'students' => $asked,
                'standings' => $standings,
                'parties' => collect($parties)->mapWithKeys(fn (string $party) => [$party => [
                    'signed' => collect($standings)->filter(fn (array $standing) => $standing[$party] !== null)->count(),
                    'total' => $asked->count(),
                ]])->all(),
            ];
        })->filter(fn (array $entry) => $entry['students']->isNotEmpty())->values();

        // The one picked, circle by circle.
        $picked = $overview->firstWhere('agreement.id', $this->agreementId) ?? $overview->first();
        $byCircle = $picked ? $picked['students']->groupBy(fn (Student $student) => $student->circle_id ?? 0)
            ->map(function (Collection $group) use ($picked, $stageNames) {
                $first = $group->first();

                return [
                    'circle' => $first->circle?->name ?? 'بلا حلقة',
                    'stage' => $stageNames[$first->effective_stage_id] ?? '—',
                    'total' => $group->count(),
                    'parties' => collect($picked['parties'])->mapWithKeys(fn ($count, string $party) => [
                        $party => $group->filter(fn (Student $student) => ($picked['standings'][$student->id][$party] ?? null) !== null)->count(),
                    ])->all(),
                ];
            })->sortBy([['stage', 'asc'], ['circle', 'asc']])->values() : collect();

        // The templates sent to students here, by template and by the teacher who sent them.
        $sends = AgreementRequest::whereNull('cancelled_at')
            ->whereIn('student_id', $students->modelKeys())
            ->when($this->template !== '', fn ($query) => $query->where('agreement_id', (int) $this->template))
            ->selectRaw('agreement_id, sent_by, count(*) as sent, count(signed_at) as signed, count(distinct student_id) as students, max(sent_at) as last_sent')
            ->groupBy('agreement_id', 'sent_by')
            ->get();

        $teachers = Teacher::whereIn('id', $sends->pluck('sent_by')->filter()->unique())
            ->with('circles:id,name')
            ->get(['id', 'name'])
            ->keyBy('id');

        $byTemplate = $sends->groupBy('agreement_id')->map(fn (SupportCollection $rows, int $agreementId) => [
            'title' => $this->templates->firstWhere('id', $agreementId)?->currentVersion?->title ?? '—',
            'sent' => (int) $rows->sum('sent'),
            'signed' => (int) $rows->sum('signed'),
            'teachers' => $rows->pluck('sent_by')->filter()->unique()->count(),
        ])->sortByDesc('sent')->values();

        $byTeacher = $sends->groupBy(fn ($row) => (int) $row->sent_by)->map(fn (SupportCollection $rows, int $teacherId) => [
            'name' => $teachers->get($teacherId)?->name ?? '—',
            'circles' => $teachers->get($teacherId)?->circles->pluck('name')->implode('، ') ?? '',
            'sent' => (int) $rows->sum('sent'),
            'signed' => (int) $rows->sum('signed'),
            'last' => $rows->max('last_sent'),
        ])->sortByDesc('sent')->values();

        return [
            'overview' => $overview,
            'picked' => $picked,
            'byCircle' => $byCircle,
            'byTemplate' => $byTemplate,
            'byTeacher' => $byTeacher,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function individual(): array
    {
        $student = $this->studentId ? Student::with(['circle:id,name,stage_id', 'guardian:id,name,phone'])->find($this->studentId) : null;

        if (! $student) {
            return ['student' => null, 'standings' => [], 'requests' => collect()];
        }

        $standings = Agreements::forStudent($student)->map(fn (Agreement $agreement) => [
            'agreement' => $agreement,
            'parties' => collect(Agreements::partiesOf($agreement->currentVersion->audience))->mapWithKeys(fn (string $party) => [$party => [
                'signature' => Agreements::signatureFor($agreement, $student, $party),
                'link' => Agreements::link($student, $party),
            ]])->all(),
        ])->all();

        $requests = AgreementRequest::where('student_id', $student->id)
            ->with(['student.guardian:id,name,phone', 'sender:id,name', 'version:id,title'])
            ->latest('sent_at')
            ->latest('id')
            ->get();

        return [
            'student' => $student,
            'stageName' => $this->stages->firstWhere('id', $student->effective_stage_id)?->name,
            'standings' => $standings,
            'requests' => $requests->whereNull('cancelled_at')->values(),
            'cancelled' => $requests->whereNotNull('cancelled_at')->count(),
        ];
    }
}
