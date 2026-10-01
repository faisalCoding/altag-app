<?php

namespace App\Livewire\Public;

use App\Models\Circle;
use App\Models\Stage;
use App\Services\CircleReportService;
use Illuminate\Http\Request;
use Livewire\Attributes\Locked;
use Livewire\Component;

class CircleReport extends Component
{
    #[Locked]
    public ?int $circleId = null;

    #[Locked]
    public ?int $stageId = null;

    #[Locked]
    public string $scope = 'circle';

    #[Locked]
    public string $studentId = '';

    #[Locked]
    public string $fromDate = '';

    #[Locked]
    public string $toDate = '';

    public function mount(Request $request): void
    {
        // The route is protected by the `signed` middleware; here we just read
        // the frozen report parameters out of the shared link. A link carries
        // either a circle (optionally widened to its stage) or a stage directly.
        $this->stageId = $request->query('stage') ? (int) $request->query('stage') : null;
        $this->circleId = $request->query('circle') ? (int) $request->query('circle') : null;
        $this->scope = $request->query('scope') === 'stage' ? 'stage' : 'circle';
        $this->studentId = (string) $request->query('student', '');
        $this->fromDate = (string) $request->query('from', '');
        $this->toDate = (string) $request->query('to', '');

        if ($this->stageId) {
            Stage::findOrFail($this->stageId);
        } else {
            Circle::findOrFail($this->circleId);
        }
    }

    public function render()
    {
        [$from, $to] = CircleReportService::resolveRange('custom', $this->fromDate, $this->toDate);

        if ($this->stageId) {
            $stage = Stage::findOrFail($this->stageId);
            $students = CircleReportService::studentsForStage($stage);
            $scopeName = 'مرحلة '.$stage->name;
            $showCircleColumn = true;
        } else {
            $circle = Circle::with('stage')->findOrFail($this->circleId);
            $isStageScope = $this->scope === 'stage';
            $students = $isStageScope
                ? CircleReportService::studentsForStage($circle->stage)
                : CircleReportService::studentsForCircle($circle);
            $scopeName = $isStageScope ? 'مرحلة '.$circle->stage->name : 'حلقة '.$circle->name;
            $showCircleColumn = $isStageScope;
        }

        $selectedStudent = $this->studentId !== ''
            ? $students->firstWhere('id', (int) $this->studentId)
            : null;

        $reportStudents = $selectedStudent
            ? $students->filter(fn ($s) => $s->id === $selectedStudent->id)->values()
            : $students;

        $report = CircleReportService::build($reportStudents, $from, $to);

        return view('livewire.public.circle-report', [
            'scopeName' => $scopeName,
            'showCircleColumn' => $showCircleColumn,
            'selectedStudent' => $selectedStudent,
            'report' => $report,
            'from' => $from,
            'to' => $to,
        ])->layout('layouts.blank');
    }
}
