<?php

use Livewire\Component;
use App\Models\StudentPlanDay;
use App\Models\StudentExam;
use App\Models\Leaderboard;
use App\Models\Student;
use App\Models\Stage;
use App\Models\Circle;
use Carbon\Carbon;

new class extends Component {
    public $dateFrom;
    public $dateTo;
    public $topStudentsCount = 3;
    public $stageId = '';
    public $circleId = '';

    public function mount()
    {
        $this->dateFrom = Carbon::now()->startOfMonth()->toDateString();
        $this->dateTo = Carbon::now()->endOfMonth()->toDateString();
    }

    public function updatedStageId()
    {
        $this->circleId = '';
    }

    public function getStagesProperty()
    {
        return Stage::orderBy('name')->get();
    }

    public function getCirclesProperty()
    {
        $query = Circle::query();
        if ($this->stageId) {
            $query->where('stage_id', $this->stageId);
        }
        return $query->orderBy('name')->get();
    }

    public function getMetricsProperty()
    {
        // 1. Fetch Hifz days
        // whereDate, not whereBetween: plan-day dates carry a time component, so
        // bare Y-m-d bounds string-compare below the last day and silently drop it.
        $hifzQuery = StudentPlanDay::with(['fromAyah', 'toAyah', 'plan.student.circle'])
            ->whereNotNull('hifz_achievement')
            ->whereDate('date', '>=', $this->dateFrom)
            ->whereDate('date', '<=', $this->dateTo);

        if ($this->circleId) {
            $hifzQuery->whereHas('plan.student', fn($q) => $q->where('circle_id', $this->circleId));
        } elseif ($this->stageId) {
            $hifzQuery->whereHas('plan.student.circle', fn($q) => $q->where('stage_id', $this->stageId));
        }

        $hifzDays = $hifzQuery->get();

        $hifzPages = 0;
        $studentsHifz = [];

        foreach ($hifzDays as $day) {
            if ($day->fromAyah && $day->toAyah && $day->plan && $day->plan->student) {
                $pages = max(1, abs($day->toAyah->page_number - $day->fromAyah->page_number) + 1);
                $hifzPages += $pages;

                $student = $day->plan->student;
                if (!isset($studentsHifz[$student->id])) {
                    $studentsHifz[$student->id] = ['student' => $student, 'pages' => 0];
                }
                $studentsHifz[$student->id]['pages'] += $pages;
            }
        }

        usort($studentsHifz, fn($a, $b) => $b['pages'] <=> $a['pages']);
        $topStudents = array_slice($studentsHifz, 0, $this->topStudentsCount);

        // 2. Fetch Review days
        $reviewQuery = StudentPlanDay::with(['reviewFromAyah', 'reviewToAyah'])
            ->whereNotNull('review_achievement')
            ->whereDate('date', '>=', $this->dateFrom)
            ->whereDate('date', '<=', $this->dateTo);

        if ($this->circleId) {
            $reviewQuery->whereHas('plan.student', fn($q) => $q->where('circle_id', $this->circleId));
        } elseif ($this->stageId) {
            $reviewQuery->whereHas('plan.student.circle', fn($q) => $q->where('stage_id', $this->stageId));
        }

        $reviewDays = $reviewQuery->get();

        $reviewPages = 0;
        foreach ($reviewDays as $day) {
            if ($day->reviewFromAyah && $day->reviewToAyah) {
                $reviewPages += max(1, abs($day->reviewToAyah->page_number - $day->reviewFromAyah->page_number) + 1);
            }
        }

        // 3. Exams
        $examsQuery = StudentExam::where('status', 'passed')
            ->whereBetween('date_time', [$this->dateFrom . ' 00:00:00', $this->dateTo . ' 23:59:59']);
        
        if ($this->circleId) {
            $examsQuery->whereHas('student', fn($q) => $q->where('circle_id', $this->circleId));
        } elseif ($this->stageId) {
            $examsQuery->whereHas('student.circle', fn($q) => $q->where('stage_id', $this->stageId));
        }

        $passedExamsCount = (clone $examsQuery)->count();
        $avgExamScore = (clone $examsQuery)->whereNotNull('score_percentage')->avg('score_percentage');

        // 4. Competitions
        $competitionsQuery = Leaderboard::where('start_date', '<=', $this->dateTo)
            ->where('end_date', '>=', $this->dateFrom);
            
        if ($this->circleId) {
            $competitionsQuery->where('circle_id', $this->circleId);
        } elseif ($this->stageId) {
            $competitionsQuery->whereHas('circle', fn($q) => $q->where('stage_id', $this->stageId));
        }

        $competitionsCount = $competitionsQuery->count();

        return [
            'hifzPages' => $hifzPages,
            'reviewPages' => $reviewPages,
            'hifzAjzaa' => round($hifzPages / 20, 2),
            'hifzKhatmat' => round($hifzPages / 604, 2),
            'topStudents' => $topStudents,
            'passedExamsCount' => $passedExamsCount,
            'avgExamScore' => $avgExamScore ? round($avgExamScore, 2) : 0,
            'competitionsCount' => $competitionsCount,
        ];
    }
};
?>

<div class="space-y-6" dir="rtl">
    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
        <div>
            <flux:heading size="xl">{{ __('تقرير الإنجاز القرآني') }}</flux:heading>
            <flux:subheading>{{ __('إحصائيات الحفظ والمراجعة والاختبارات للفترة المحددة.') }}</flux:subheading>
        </div>
    </div>

    {{-- Filter Toolbar --}}
    <div class="flex flex-wrap items-end gap-3 bg-zinc-50 dark:bg-zinc-800/50 p-4 rounded-xl border border-zinc-200 dark:border-zinc-800">
        <div class="flex flex-col gap-1 w-full sm:w-48">
            <label class="text-xs font-medium text-zinc-500">{{ __('من تاريخ') }}</label>
            <livewire:manager.hijri-datepicker wire:model.live="dateFrom" label="من تاريخ" />
        </div>

        <div class="flex flex-col gap-1 w-full sm:w-48">
            <label class="text-xs font-medium text-zinc-500">{{ __('إلى تاريخ') }}</label>
            <livewire:manager.hijri-datepicker wire:model.live="dateTo" label="إلى تاريخ" />
        </div>

        <div class="flex flex-col gap-1 w-full sm:w-48">
            <flux:select wire:model.live="stageId" label="{{ __('المرحلة') }}" placeholder="{{ __('الكل') }}">
                @foreach($this->stages as $stage)
                    <flux:select.option value="{{ $stage->id }}">{{ $stage->name }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>

        <div class="flex flex-col gap-1 w-full sm:w-48">
            <flux:select wire:model.live="circleId" label="{{ __('الحلقة') }}" placeholder="{{ __('الكل') }}">
                @foreach($this->circles as $circle)
                    <flux:select.option value="{{ $circle->id }}">{{ $circle->name }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>
    </div>

    {{-- Cards Grid --}}
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
        <flux:card>
            <div class="flex items-center gap-3">
                <div class="p-3 bg-emerald-100 text-emerald-600 rounded-lg dark:bg-emerald-900/30 dark:text-emerald-400">
                    <flux:icon icon="book-open" class="size-6" />
                </div>
                <div>
                    <div class="text-sm text-zinc-500">{{ __('صفحات الحفظ') }}</div>
                    <div class="text-2xl font-bold">{{ $this->metrics['hifzPages'] }}</div>
                </div>
            </div>
        </flux:card>

        <flux:card>
            <div class="flex items-center gap-3">
                <div class="p-3 bg-blue-100 text-blue-600 rounded-lg dark:bg-blue-900/30 dark:text-blue-400">
                    <flux:icon icon="arrow-path" class="size-6" />
                </div>
                <div>
                    <div class="text-sm text-zinc-500">{{ __('صفحات المراجعة') }}</div>
                    <div class="text-2xl font-bold">{{ $this->metrics['reviewPages'] }}</div>
                </div>
            </div>
        </flux:card>

        <flux:card>
            <div class="flex items-center gap-3">
                <div class="p-3 bg-amber-100 text-amber-600 rounded-lg dark:bg-amber-900/30 dark:text-amber-400">
                    <flux:icon icon="academic-cap" class="size-6" />
                </div>
                <div>
                    <div class="text-sm text-zinc-500">{{ __('الأجزاء المحفوظة') }}</div>
                    <div class="text-2xl font-bold">{{ $this->metrics['hifzAjzaa'] }}</div>
                </div>
            </div>
        </flux:card>

        <flux:card>
            <div class="flex items-center gap-3">
                <div class="p-3 bg-purple-100 text-purple-600 rounded-lg dark:bg-purple-900/30 dark:text-purple-400">
                    <flux:icon icon="star" class="size-6" />
                </div>
                <div>
                    <div class="text-sm text-zinc-500">{{ __('الختمات') }}</div>
                    <div class="text-2xl font-bold">{{ $this->metrics['hifzKhatmat'] }}</div>
                </div>
            </div>
        </flux:card>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {{-- Top Students --}}
        <flux:card>
            <div class="flex justify-between items-center mb-6">
                <flux:heading size="lg">{{ __('أفضل الطلاب إنجازاً') }}</flux:heading>
                <div class="w-32">
                    <flux:select wire:model.live="topStudentsCount" size="sm">
                        <flux:select.option value="3">أفضل 3</flux:select.option>
                        <flux:select.option value="5">أفضل 5</flux:select.option>
                        <flux:select.option value="10">أفضل 10</flux:select.option>
                    </flux:select>
                </div>
            </div>

            @if(count($this->metrics['topStudents']) > 0)
                <div class="space-y-4">
                    @foreach($this->metrics['topStudents'] as $index => $item)
                        <div class="flex items-center justify-between p-3 bg-zinc-50 dark:bg-zinc-800/50 rounded-xl">
                            <div class="flex items-center gap-3">
                                <div class="flex items-center justify-center size-8 rounded-full bg-emerald-100 text-emerald-700 font-bold dark:bg-emerald-900/30 dark:text-emerald-400">
                                    {{ $index + 1 }}
                                </div>
                                <div>
                                    <div class="font-medium">{{ $item['student']->name }}</div>
                                    <div class="text-xs text-zinc-500">{{ $item['student']->circle->name ?? 'بدون حلقة' }}</div>
                                </div>
                            </div>
                            <div class="text-sm font-bold text-emerald-600 dark:text-emerald-400">
                                {{ $item['pages'] }} صفحة
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <div class="text-center text-zinc-500 py-8">
                    {{ __('لا يوجد بيانات كافية للفترة المحددة') }}
                </div>
            @endif
        </flux:card>

        {{-- Exams & Competitions --}}
        <div class="space-y-6">
            <flux:card>
                <flux:heading size="lg" class="mb-4">{{ __('الاختبارات والمسابقات') }}</flux:heading>
                <div class="space-y-4">
                    <div class="flex justify-between items-center p-3 border-b border-zinc-100 dark:border-zinc-800">
                        <div class="flex items-center gap-2 text-zinc-600 dark:text-zinc-300">
                            <flux:icon icon="check-badge" class="size-5" />
                            <span>{{ __('الاختبارات المجتازة (تم التجاوز)') }}</span>
                        </div>
                        <span class="font-bold">{{ $this->metrics['passedExamsCount'] }}</span>
                    </div>
                    <div class="flex justify-between items-center p-3 border-b border-zinc-100 dark:border-zinc-800">
                        <div class="flex items-center gap-2 text-zinc-600 dark:text-zinc-300">
                            <flux:icon icon="chart-pie" class="size-5" />
                            <span>{{ __('متوسط نسب الاختبارات المجتازة') }}</span>
                        </div>
                        <span class="font-bold">{{ $this->metrics['avgExamScore'] }}%</span>
                    </div>
                    <div class="flex justify-between items-center p-3 border-b border-zinc-100 dark:border-zinc-800">
                        <div class="flex items-center gap-2 text-zinc-600 dark:text-zinc-300">
                            <flux:icon icon="trophy" class="size-5" />
                            <span>{{ __('المسابقات المقامة') }}</span>
                        </div>
                        <span class="font-bold">{{ $this->metrics['competitionsCount'] }}</span>
                    </div>
                </div>
            </flux:card>
        </div>
    </div>
</div>