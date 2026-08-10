<?php

namespace App\Livewire\Supervisor;

use App\Models\Circle;
use App\Models\Stage;
use App\Models\Student;
use App\Services\CircleReportService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url as UrlParam;
use Livewire\Component;

/**
 * An ad-hoc achievement report over any mix of circles and stages the
 * supervisor oversees, rather than the single circle or single stage the
 * per-circle report is fixed to.
 */
class CustomReport extends Component
{
    /** @var array<int, string> Selected circle ids. */
    #[UrlParam(as: 'circles')]
    public array $circleIds = [];

    /** @var array<int, string> Selected stage ids — every circle of the stage. */
    #[UrlParam(as: 'stages')]
    public array $stageIds = [];

    #[UrlParam]
    public string $preset = 'this_week';

    #[UrlParam]
    public string $fromDate = '';

    #[UrlParam]
    public string $toDate = '';

    public function mount(): void
    {
        if (! array_key_exists($this->preset, CircleReportService::PRESETS)) {
            $this->preset = 'this_week';
        }
    }

    public function updatedPreset(): void
    {
        if ($this->preset === 'custom') {
            [$from, $to] = CircleReportService::resolveRange('last_week');
            $this->fromDate = $this->fromDate ?: $from->toDateString();
            $this->toDate = $this->toDate ?: $to->toDateString();
        } else {
            $this->fromDate = '';
            $this->toDate = '';
        }
    }

    public function toggleCircle(int $circleId): void
    {
        $this->circleIds = $this->toggle($this->circleIds, $circleId);
    }

    public function toggleStage(int $stageId): void
    {
        $this->stageIds = $this->toggle($this->stageIds, $stageId);
    }

    /**
     * @param  array<int, string>  $selected
     * @return array<int, string>
     */
    protected function toggle(array $selected, int $id): array
    {
        $key = (string) $id;

        return in_array($key, array_map('strval', $selected), true)
            ? array_values(array_filter($selected, fn ($value) => (string) $value !== $key))
            : [...$selected, $key];
    }

    public function clearSelection(): void
    {
        $this->circleIds = [];
        $this->stageIds = [];
    }

    /** The stages this supervisor oversees, with their circles. */
    protected function allowedStages()
    {
        return auth()->guard('supervisor')->user()
            ->stages()
            ->with(['circles' => fn ($q) => $q->orderBy('name')])
            ->orderBy('stages.name')
            ->get();
    }

    /**
     * The students of every selected circle and stage, merged and de-duplicated
     * so a student counted twice (their circle picked alongside its stage) is
     * only reported once.
     *
     * @param  Collection<int, Stage>  $allowedStages
     * @return EloquentCollection<int, Student>
     */
    protected function selectedStudents($allowedStages): EloquentCollection
    {
        $allowedStageIds = $allowedStages->pluck('id')->all();
        $allowedCircleIds = $allowedStages->flatMap->circles->pluck('id')->all();

        // Only ever report on circles and stages within the supervisor's scope.
        $stageIds = array_intersect(array_map('intval', $this->stageIds), $allowedStageIds);
        $circleIds = array_intersect(array_map('intval', $this->circleIds), $allowedCircleIds);

        $students = new EloquentCollection;

        foreach (Stage::whereIn('id', $stageIds)->get() as $stage) {
            $students = $students->merge(CircleReportService::studentsForStage($stage));
        }

        foreach (Circle::whereIn('id', $circleIds)->get() as $circle) {
            $students = $students->merge(CircleReportService::studentsForCircle($circle));
        }

        return $students->unique('id')->sortBy('name')->values();
    }

    public function render()
    {
        [$from, $to] = CircleReportService::resolveRange($this->preset, $this->fromDate, $this->toDate);

        $allowedStages = $this->allowedStages();
        $students = $this->selectedStudents($allowedStages);
        $hasSelection = $students->isNotEmpty();

        return view('livewire.supervisor.custom-report', [
            'stages' => $allowedStages,
            'students' => $students,
            'hasSelection' => $hasSelection,
            'report' => $hasSelection ? CircleReportService::build($students, $from, $to) : null,
            'from' => $from,
            'to' => $to,
            'presets' => CircleReportService::PRESETS,
        ]);
    }
}
