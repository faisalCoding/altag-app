<?php

use Livewire\Component;
use App\Models\Student;
use App\Models\StudentPlanDay;
use App\Services\TeacherCompetitionService;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

new class extends Component {
    public bool $showGradingModal = false;
    public $selectedAssignmentId = null;
    public array $gradingScores = [];
    public string $gradingNotes = '';
    public $calculatedGrade = null;

    public function editGrading($assignmentId): void
    {
        $this->selectedAssignmentId = $assignmentId;
        $assignment = \App\Models\GamificationTeamTaskAssignment::with(['task.criteria', 'scores'])->findOrFail($assignmentId);
        
        $this->gradingScores = [];
        foreach ($assignment->task->criteria as $criterion) {
            $scoreObj = $assignment->scores->firstWhere('criterion_id', $criterion->id);
            $this->gradingScores[$criterion->id] = $scoreObj ? $scoreObj->score : null;
        }
        $this->gradingNotes = $assignment->notes ?? '';
        $this->calculatedGrade = $assignment->grade;
        $this->showGradingModal = true;
    }

    public function updatedGradingScores(): void
    {
        $assignment = \App\Models\GamificationTeamTaskAssignment::with('task.criteria')->find($this->selectedAssignmentId);
        if ($assignment && $assignment->task->criteria->isNotEmpty()) {
            $totalScore = 0;
            $count = 0;
            $graded = false;
            foreach ($assignment->task->criteria as $criterion) {
                $score = $this->gradingScores[$criterion->id] ?? null;
                if ($score !== null && $score !== '') {
                    $totalScore += (int)$score;
                    $count++;
                    $graded = true;
                }
            }
            if ($graded && $count === $assignment->task->criteria->count()) {
                $this->calculatedGrade = (int)round(($totalScore / ($count * 10)) * 100);
            } else {
                $this->calculatedGrade = null;
            }
        }
    }

    public function saveGrading(): void
    {
        $assignment = \App\Models\GamificationTeamTaskAssignment::with('task.criteria')->findOrFail($this->selectedAssignmentId);
        $hasCriteria = $assignment->task->criteria->isNotEmpty();

        if ($hasCriteria) {
            $rules = [];
            foreach ($assignment->task->criteria as $criterion) {
                $rules["gradingScores.{$criterion->id}"] = 'required|integer|between:0,10';
            }
            $this->validate($rules, [
                'gradingScores.*.required' => 'يجب إدخال تقييم لجميع البنود.',
                'gradingScores.*.between' => 'التقييم يجب أن يكون بين 0 و 10.',
            ]);

            $totalScore = 0;
            foreach ($assignment->task->criteria as $criterion) {
                $totalScore += (int)$this->gradingScores[$criterion->id];
            }
            $this->calculatedGrade = (int)round(($totalScore / ($assignment->task->criteria->count() * 10)) * 100);
        } else {
            $this->validate([
                'calculatedGrade' => 'required|integer|between:0,100',
            ]);
        }

        \Illuminate\Support\Facades\DB::transaction(function () use ($assignment, $hasCriteria) {
            $status = 'completed';

            $assignment->update([
                'status' => $status,
                'grade' => $this->calculatedGrade,
                'notes' => $this->gradingNotes ?: null,
            ]);

            $assignment->scores()->delete();
            if ($hasCriteria) {
                foreach ($assignment->task->criteria as $criterion) {
                    $assignment->scores()->create([
                        'criterion_id' => $criterion->id,
                        'score' => (int)$this->gradingScores[$criterion->id],
                    ]);
                }
            }

            // Revert previous transaction if exists
            $tx = \App\Models\GamificationTransaction::where('leaderboard_id', $assignment->task->leaderboard_id)
                ->where('reference_type', \App\Models\GamificationTeamTaskAssignment::class)
                ->where('reference_id', $assignment->id)
                ->first();

            if ($tx) {
                $oldTeam = \App\Models\GamificationTeam::find($tx->team_id);
                if ($oldTeam) {
                    $oldTeam->decrement('coins', $tx->amount);
                }
            }

            $awardedCoins = 0;
            $awardedXp = 0;
            if ($hasCriteria) {
                foreach ($assignment->task->criteria as $criterion) {
                    $score = (int)($this->gradingScores[$criterion->id] ?? 0);
                    $awardedCoins += (int)round(($score / 10) * ($criterion->coins_reward ?? 0));
                }
            } else {
                $awardedCoins = (int)round(($this->calculatedGrade / 100) * $assignment->task->coins_reward);
            }
            $awardedXp = (int)round(($this->calculatedGrade / 100) * $assignment->task->xp_reward);

            \App\Models\GamificationTransaction::updateOrCreate(
                [
                    'leaderboard_id' => $assignment->task->leaderboard_id,
                    'reference_type' => \App\Models\GamificationTeamTaskAssignment::class,
                    'reference_id' => $assignment->id,
                ],
                [
                    'student_id' => null,
                    'team_id' => $assignment->team_id,
                    'type' => 'earn',
                    'amount' => $awardedCoins,
                    'xp_amount' => $awardedXp,
                    'description' => "تقييم مهمة من المعلم: {$assignment->task->name} (درجة: {$this->calculatedGrade}%)",
                ]
            );

            $newTeam = \App\Models\GamificationTeam::findOrFail($assignment->team_id);
            $newTeam->increment('coins', $awardedCoins);
        });

        $this->showGradingModal = false;
        \Flux\Flux::toast('تم حفظ تقييم المهمة وتوزيع الجوائز بنجاح', variant: 'success');
    }

    public function with()
    {
        $teacher = Auth::guard('teacher')->user();
        $circleIds = $teacher->circles()->pluck('id');

        $last30Start = Carbon::now()->subDays(30)->format('Y-m-d');
        $last30End = Carbon::now()->format('Y-m-d');

        // 1. Top Attendance (last 30 days)
        $studentsLast30Days = Student::whereIn('circle_id', $circleIds)
            ->with([
                'attendances' => function ($q) use ($last30Start, $last30End) {
                    $q->whereBetween('date', [$last30Start, $last30End]);
                }
            ])
            ->get()
            ->map(function ($student) {
                return [
                    'name' => $student->name,
                    'present_count' => $student->attendances->where('status', 'present')->count(),
                ];
            });

        $topAttendance = $studentsLast30Days->where('present_count', '>', 0)->sortByDesc('present_count')->take(5)->values();

        // 2. Top Quranic Discipline (last 30 days)
        $studentIds = Student::whereIn('circle_id', $circleIds)->pluck('id');
        $planDays = StudentPlanDay::with('plan.student')
            ->whereHas('plan', function ($q) use ($studentIds) {
                $q->whereIn('student_id', $studentIds);
            })
            ->where('date', '>=', $last30Start)
            ->where(function ($query) use ($last30End) {
                $query->where('date', '<=', $last30End)
                    ->orWhereNotNull('hifz_achievement')
                    ->orWhereNotNull('review_achievement');
            })
            ->get();

        $studentsExcellent = [];
        // Initialize
        foreach ($studentIds as $sId) {
            $studentsExcellent[$sId] = [
                'name' => '', // Will be filled dynamically below
                'excellent_count' => 0
            ];
        }

        foreach ($planDays as $day) {
            $sId = $day->plan->student_id;
            if (!isset($studentsExcellent[$sId]))
                continue;

            $studentsExcellent[$sId]['name'] = $day->plan->student->name;

            // Only count today or past dates (or early recitations)
            $dateStr = \Carbon\Carbon::parse($day->date)->format('Y-m-d');
            $effectiveDateStr = $dateStr > $last30End ? $last30End : $dateStr;

            if ($effectiveDateStr >= $last30Start && $effectiveDateStr <= $last30End) {
                if ($day->plan->plan_type === 'hifz' || $day->plan->plan_type === 'hifz_review') {
                    if ($day->hifz_achievement == 3)
                        $studentsExcellent[$sId]['excellent_count']++;
                }
                if ($day->plan->plan_type === 'review' || $day->plan->plan_type === 'hifz_review') {
                    if ($day->review_achievement == 3)
                        $studentsExcellent[$sId]['excellent_count']++;
                }
            }
        }

        // Filter and sort top excellent
        $topExcellent = collect($studentsExcellent)
            ->filter(fn($s) => $s['excellent_count'] > 0 && !empty($s['name']))
            ->sortByDesc('excellent_count')
            ->take(5)
            ->values();

        // Query assignments assigned to this teacher
        $assignments = \App\Models\GamificationTeamTaskAssignment::with(['task.criteria', 'team', 'scores'])
            ->where('teacher_id', $teacher->id)
            ->orderBy('status')
            ->orderBy('end_date')
            ->get();

        // Independent teacher-competition system (separate from the student
        // leaderboard/gamification above) — when active, the dashboard shows
        // only this instead of the everyday content.
        $competitionService = new TeacherCompetitionService();
        $activeTeacherCompetition = $competitionService->activeCompetitionFor($teacher);
        $teacherCompetitionStandings = $activeTeacherCompetition
            ? $competitionService->getStandings($activeTeacherCompetition)
            : collect();

        return [
            'topAttendance' => $topAttendance,
            'topExcellent' => $topExcellent,
            'studentsCount' => Student::whereIn('circle_id', $circleIds)->count(),
            'assignments' => $assignments,
            'activeTeacherCompetition' => $activeTeacherCompetition,
            'teacherCompetitionStandings' => $teacherCompetitionStandings,
        ];
    }
};
?>

<div class="space-y-8" dir="rtl">
    <x-shared.pending-surveys />

    <!-- Header Section -->
    <div>
        <flux:heading size="xl" class="font-bold text-zinc-900 dark:text-white">
            {{ __('لوحة تحكم التعليم') }}
        </flux:heading>
        <flux:subheading class="text-zinc-500 dark:text-zinc-400">
            {{ __('مرحباً بك مجدداً في نظام إدارة مجمع التاج القرآني') }}
        </flux:subheading>
    </div>

    {{-- Independent teacher-competition override: while one is active for this
    teacher, show only it instead of the everyday dashboard content below. --}}
    @if($activeTeacherCompetition)
        <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 shadow-xs p-6 space-y-4" dir="rtl">
            <div class="flex items-center justify-between flex-wrap gap-2">
                <flux:heading size="lg" class="flex items-center gap-2">
                    <div class="p-2 bg-sky-500 rounded-lg shadow-lg shadow-sky-500/20">
                        <flux:icon icon="trophy" variant="solid" class="size-6 text-white" />
                    </div>
                    {{ $activeTeacherCompetition->name }}
                </flux:heading>
                <div class="flex items-center gap-2 text-xs font-bold text-sky-600 dark:text-sky-400 bg-sky-50 dark:bg-sky-900/30 px-3 py-1.5 rounded-full border border-sky-200 dark:border-sky-800/50">
                    <flux:icon icon="clock" class="size-3.5" />
                    @php $daysRemaining = now()->startOfDay()->diffInDays($activeTeacherCompetition->end_date->startOfDay(), false); @endphp
                    @if($daysRemaining > 0)
                        {{ __('متبقي :days يوم', ['days' => $daysRemaining]) }}
                    @else
                        {{ __('اليوم الأخير!') }}
                    @endif
                </div>
            </div>

            @if($teacherCompetitionStandings->isEmpty())
                <div class="text-center py-10 text-sm text-zinc-400 border border-dashed rounded-xl">
                    {{ __('لسه محدش اتقيّم في المسابقة دي.') }}
                </div>
            @else
                <div class="space-y-2">
                    @foreach($teacherCompetitionStandings as $row)
                        @php $isMe = $row['teacher']->id === auth()->guard('teacher')->id(); @endphp
                        <div class="flex items-center justify-between gap-3 p-3 rounded-xl {{ $isMe ? 'bg-indigo-50 dark:bg-indigo-900/20 border border-indigo-200 dark:border-indigo-800/50' : 'bg-zinc-50 dark:bg-zinc-800/50' }}">
                            <div class="flex items-center gap-3">
                                <div class="w-8 h-8 rounded-full flex items-center justify-center font-black text-sm {{ $row['rank'] <= 3 ? 'bg-amber-400 text-white' : 'bg-zinc-200 dark:bg-zinc-700 text-zinc-600 dark:text-zinc-300' }}">
                                    {{ $row['rank'] }}
                                </div>
                                <span class="font-bold text-zinc-800 dark:text-zinc-100">
                                    {{ $row['teacher']->name }}
                                    @if($isMe)
                                        <span class="text-[10px] font-bold ms-1 bg-indigo-100 dark:bg-indigo-900 text-indigo-700 dark:text-indigo-300 px-2 py-0.5 rounded-full">{{ __('أنت') }}</span>
                                    @endif
                                </span>
                            </div>
                            <div class="text-end">
                                <div class="font-bold text-zinc-700 dark:text-zinc-200">{{ $row['score'] }} / {{ $row['max_score'] }}</div>
                                <div class="text-xs text-zinc-400">{{ $row['percentage'] }}%</div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    @endif

    @unless($activeTeacherCompetition)
    <!-- Quick CTA: Attendance -->
    <a href="{{ route('teacher.attendance') }}" class="block w-full transition-transform hover:-translate-y-1">
        <div
            class="bg-indigo-600 hover:bg-indigo-700 dark:bg-indigo-500 dark:hover:bg-indigo-600 rounded-2xl p-6 shadow-md shadow-indigo-600/20 text-white flex flex-col sm:flex-row items-center sm:justify-between gap-4">
            <div class="flex items-center gap-4">
                <div class="bg-white/20 p-3 rounded-full">
                    <flux:icon icon="clipboard-document-check" class="w-8 h-8 text-white" />
                </div>
                <div>
                    <h2 class="text-xl font-bold">{{ __('التحضير اليومي') }}</h2>
                    <p class="text-indigo-100 text-sm mt-1 mb-0">{{ __('سجل حضور وغياب وتأخر طلابك لهذا اليوم') }}</p>
                </div>
            </div>
            <flux:button variant="filled" class="bg-white text-indigo-600 hover:bg-zinc-50">
                {{ __('ابدأ التحضير الآن') }}</flux:button>
        </div>
    </a>

    <!-- Assigned Group Tasks -->
    <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 p-6 space-y-4 shadow-xs">
        <flux:heading size="lg" class="font-bold text-zinc-900 dark:text-white flex items-center gap-2">
            <svg class="size-6 text-indigo-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 002 2h2a2 2 0 002-2" />
            </svg>
            <span>{{ __('مهام المجموعات المكلف بمسؤوليتها') }}</span>
        </flux:heading>
        <p class="text-xs text-zinc-500 mb-4">{{ __('المهام التي كلفك بها المشرف لتقييم المجموعات المشاركة.') }}</p>

        @if($assignments->isEmpty())
            <div class="p-6 text-center text-zinc-500 text-sm border border-dashed rounded-2xl border-zinc-200 dark:border-zinc-800">
                {{ __('لا يوجد مهام مجموعات مكلفة إليك حالياً.') }}
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-right border-collapse">
                    <thead>
                        <tr class="border-b border-zinc-100 dark:border-zinc-800 text-zinc-500 text-xs">
                            <th class="py-3 px-4 font-bold">{{ __('المهمة') }}</th>
                            <th class="py-3 px-4 font-bold">{{ __('المجموعة المكلفة') }}</th>
                            <th class="py-3 px-4 font-bold">{{ __('تاريخ الاستحقاق') }}</th>
                            <th class="py-3 px-4 font-bold text-center">{{ __('الحالة والتقييم') }}</th>
                            <th class="py-3 px-4 font-bold text-center">{{ __('العمليات') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-50 dark:divide-zinc-900/50 text-sm">
                        @foreach($assignments as $assignment)
                            <tr class="hover:bg-zinc-50/50 dark:hover:bg-zinc-900/30 transition-colors">
                                <td class="py-3 px-4 font-semibold text-zinc-900 dark:text-white">
                                    {{ $assignment->task->name }}
                                </td>
                                <td class="py-3 px-4 text-zinc-600 dark:text-zinc-400">
                                    {{ $assignment->team->name }}
                                </td>
                                <td class="py-3 px-4 text-zinc-500">
                                    <x-hijri-date :date="$assignment->end_date" />
                                </td>
                                <td class="py-3 px-4 text-center">
                                    @if($assignment->status === 'completed')
                                        <flux:badge color="green" size="sm" inset="top bottom">
                                            {{ $assignment->grade }}%
                                        </flux:badge>
                                    @else
                                        <flux:badge color="amber" size="sm" inset="top bottom">
                                            {{ __('قيد التنفيذ') }}
                                        </flux:badge>
                                    @endif
                                </td>
                                <td class="py-3 px-4 text-center">
                                    @if($assignment->status === 'completed')
                                        <flux:button wire:click="editGrading({{ $assignment->id }})" size="xs" variant="ghost" class="text-purple-600 hover:text-purple-700">
                                            <svg class="size-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" />
                                            </svg>
                                            {{ __('تعديل التقييم') }}
                                        </flux:button>
                                    @else
                                        <flux:button wire:click="editGrading({{ $assignment->id }})" size="xs" variant="filled" class="bg-purple-600 hover:bg-purple-700 text-white border-none">
                                            <svg class="size-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2" />
                                            </svg>
                                            {{ __('رصد التقييم') }}
                                        </flux:button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <!-- Positive Snapshots Grid -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">

        <!-- Top Attendance Snapshot -->
        <flux:card class="border-t-4 border-t-green-500 overflow-hidden relative">
            <div class="absolute top-0 right-0 p-4 opacity-5">
                <flux:icon icon="check-badge" class="w-24 h-24 text-green-500" />
            </div>
            <div class="relative z-10 space-y-4">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2 text-green-600 dark:text-green-400">
                        <flux:icon icon="check-badge" class="w-5 h-5 flex-shrink-0" />
                        <h3 class="font-bold text-sm">{{ __('التميز الحضوري') }}</h3>
                    </div>
                    <a href="{{ route('teacher.discipline') }}"
                        class="text-xs text-indigo-500 hover:underline">{{ __('التفاصيل') }}</a>
                </div>
                <div class="text-xs text-zinc-500">{{ __('فرسان الحضور خلال آخر 30 يوماً') }}</div>

                <div class="space-y-3 mt-4">
                    @forelse($topAttendance as $stat)
                        <div class="flex items-center justify-between">
                            <span
                                class="text-sm font-medium text-zinc-700 dark:text-zinc-300 truncate pr-2">{{ $stat['name'] }}</span>
                            <flux:badge color="green" size="sm" inset="top bottom">{{ $stat['present_count'] }}
                                {{ __('حضور') }}</flux:badge>
                        </div>
                    @empty
                        <div class="text-xs text-zinc-400 text-center py-4">{{ __('لا يوجد بيانات كافية') }}</div>
                    @endforelse
                </div>
            </div>
        </flux:card>

        <!-- Top Quranic Discipline Snapshot -->
        <flux:card class="border-t-4 border-t-blue-500 overflow-hidden relative">
            <div class="absolute top-0 right-0 p-4 opacity-5">
                <flux:icon icon="star" class="w-24 h-24 text-blue-500" />
            </div>
            <div class="relative z-10 space-y-4">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2 text-blue-600 dark:text-blue-400">
                        <flux:icon icon="star" class="w-5 h-5 flex-shrink-0" />
                        <h3 class="font-bold text-sm">{{ __('التميز القرآني') }}</h3>
                    </div>
                    <a href="{{ route('teacher.quranic-discipline') }}"
                        class="text-xs text-indigo-500 hover:underline">{{ __('التفاصيل') }}</a>
                </div>
                <div class="text-xs text-zinc-500">{{ __('فرسان التسميع بتقدير ممتاز خلال آخر 30 يوماً') }}</div>

                <div class="space-y-3 mt-4">
                    @forelse($topExcellent as $stat)
                        <div class="flex items-center justify-between">
                            <span
                                class="text-sm font-medium text-zinc-700 dark:text-zinc-300 truncate pr-2">{{ $stat['name'] }}</span>
                            <flux:badge color="blue" size="sm" inset="top bottom">{{ $stat['excellent_count'] }}
                                {{ __('امتياز') }}</flux:badge>
                        </div>
                    @empty
                        <div class="text-xs text-zinc-400 text-center py-4">{{ __('لا يوجد بيانات كافية') }}</div>
                    @endforelse
                </div>
            </div>
        </flux:card>

    </div>

    <!-- Secondary Shortcuts Grid -->
    <div>
        <flux:heading size="lg" class="mb-4">{{ __('روابط سريعة') }}</flux:heading>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">

            <a href="{{ route('teacher.tasmeeh') }}"
                class="group bg-white dark:bg-zinc-900 p-6 rounded-2xl border border-zinc-100 dark:border-zinc-800 shadow-xs hover:border-indigo-500/50 hover:shadow-md transition-[transform,shadow,border-color] h-36 flex flex-col justify-center items-center text-center">
                <flux:icon icon="book-open"
                    class="size-8 text-indigo-500 mb-2 group-hover:scale-110 transition-transform" />
                <flux:heading size="md" class="group-hover:text-indigo-600">{{ __('التسميع اليومي') }}
                </flux:heading>
                <flux:subheading class="text-xs mt-1">{{ __('متابعة تسميع المهام اليومية للطلاب') }}</flux:subheading>
            </a>

            <a href="{{ route('teacher.plan-creator') }}"
                class="group bg-white dark:bg-zinc-900 p-6 rounded-2xl border border-zinc-100 dark:border-zinc-800 shadow-xs hover:border-indigo-500/50 hover:shadow-md transition-[transform,shadow,border-color] h-36 flex flex-col justify-center items-center text-center">
                <flux:icon icon="calendar-days"
                    class="size-8 text-emerald-500 mb-2 group-hover:scale-110 transition-transform" />
                <flux:heading size="md" class="group-hover:text-indigo-600">{{ __('منشئ الخطط') }}</flux:heading>
                <flux:subheading class="text-xs mt-1">{{ __('إنشاء وتوزيع مسارات الحفظ والمراجعة') }}</flux:subheading>
            </a>

            <a href="{{ route('teacher.students') }}"
                class="group bg-white dark:bg-zinc-900 p-6 rounded-2xl border border-zinc-100 dark:border-zinc-800 shadow-xs hover:border-indigo-500/50 hover:shadow-md transition-[transform,shadow,border-color] h-36 flex flex-col justify-center items-center text-center">
                <flux:icon icon="users" class="size-8 text-amber-500 mb-2 group-hover:scale-110 transition-transform" />
                <flux:heading size="md" class="group-hover:text-indigo-600">{{ __('طلابي') }}</flux:heading>
                <flux:subheading class="text-xs mt-1">{{ __('إدارة قائمة الطلاب ( ' . $studentsCount . ' طالب )') }}
                </flux:subheading>
            </a>

        </div>
    </div>
    @endunless

    <!-- Grading Modal -->
    <flux:modal wire:model="showGradingModal" class="md:w-[500px]">
        <div class="space-y-6 text-right" dir="rtl">
            <div>
                <flux:heading size="lg" class="font-bold text-zinc-950 dark:text-white">{{ __('تقييم ورصد مهمة المجموعة') }}</flux:heading>
                <flux:subheading class="text-zinc-500">{{ __('قم بتقييم كل بند من بنود المهمة من 10 نقاط.') }}</flux:subheading>
            </div>

            @if($selectedAssignmentId)
                @php
                    $activeAssignment = \App\Models\GamificationTeamTaskAssignment::with('task.criteria')->find($selectedAssignmentId);
                @endphp
                @if($activeAssignment)
                    <div class="space-y-2 text-sm">
                        <div class="flex justify-between border-b pb-2">
                            <span class="font-bold text-zinc-700 dark:text-zinc-300">{{ __('المهمة:') }}</span>
                            <span class="text-zinc-900 dark:text-white">{{ $activeAssignment->task->name }}</span>
                        </div>
                        <div class="flex justify-between border-b pb-2">
                            <span class="font-bold text-zinc-700 dark:text-zinc-300">{{ __('المجموعة:') }}</span>
                            <span class="text-zinc-900 dark:text-white">{{ $activeAssignment->team->name }}</span>
                        </div>
                    </div>

                    @if($activeAssignment->task->criteria->isNotEmpty())
                        <div class="space-y-4">
                            <flux:heading size="sm" class="font-bold text-zinc-800 dark:text-zinc-200">{{ __('بنود التقييم التفصيلية (من 10)') }}</flux:heading>
                            <div class="space-y-3">
                                @foreach($activeAssignment->task->criteria as $criterion)
                                    <div class="flex items-center justify-between p-3 rounded-xl border border-zinc-100 dark:border-zinc-800 bg-zinc-50/50 dark:bg-zinc-900/30">
                                        <div class="flex-1 text-right">
                                            <div class="text-sm font-semibold text-zinc-900 dark:text-white">{{ $criterion->name }}</div>
                                            @if($criterion->description)
                                                <div class="text-xs text-zinc-500">{{ $criterion->description }}</div>
                                            @endif
                                            <div class="text-xs text-purple-600 dark:text-purple-400 font-medium">{{ __('العملات المخصصة للبند:') }} {{ $criterion->coins_reward }}</div>
                                        </div>
                                        <div class="w-24">
                                            <flux:input type="number" wire:model.live="gradingScores.{{ $criterion->id }}" min="0" max="10" placeholder="0 - 10" />
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                            @error('gradingScores.*')
                                <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span>
                            @enderror
                        </div>
                    @else
                        <!-- No criteria fallback -->
                        <flux:field>
                            <flux:input type="number" wire:model="calculatedGrade" label="الدرجة الإجمالية (من 100)" placeholder="مثال: 90" min="0" max="100" />
                        </flux:field>
                    @endif

                    @if($calculatedGrade !== null)
                        <div class="p-3 bg-purple-50 dark:bg-purple-950/20 rounded-xl border border-purple-200 dark:border-purple-800 text-center">
                            <span class="text-sm font-bold text-purple-700 dark:text-purple-300">{{ __('الدرجة الإجمالية المحتسبة:') }} {{ $calculatedGrade }}%</span>
                        </div>
                    @endif

                    <flux:field>
                        <flux:input wire:model="gradingNotes" label="ملاحظات التقييم" placeholder="اكتب ملاحظاتك هنا..." />
                    </flux:field>
                @endif
            @endif

            <div class="flex justify-end gap-2 pt-4 border-t border-zinc-100 dark:border-zinc-800">
                <flux:button wire:click="$set('showGradingModal', false)" variant="ghost">{{ __('إلغاء') }}</flux:button>
                <flux:button wire:click="saveGrading" variant="primary" class="bg-purple-600 hover:bg-purple-700 border-none text-white">{{ __('حفظ التقييم') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</div>