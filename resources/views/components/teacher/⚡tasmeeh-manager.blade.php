<?php

use Livewire\Component;
use App\Models\Student;
use App\Models\StudentPlan;
use App\Models\StudentPlanDay;
use App\Models\StudentOdePlan;
use App\Models\StudentOdeAchievement;
use App\Models\OdePathDay;
use App\Models\StudentHadithPlan;
use App\Models\StudentHadithAchievement;
use App\Models\HadithPathDay;
use App\Support\NextExamBadge;
use Illuminate\Support\Facades\Auth;
use Flux\Flux;
use Livewire\Attributes\On;

new class extends Component {
    public $gradedAtDate;
    public $refreshToggle = false;

    public function mount()
    {
        // The academy's day: on UTC it would still be yesterday for the first
        // three hours of every Riyadh day, and grade yesterday's session.
        $this->gradedAtDate = now('Asia/Riyadh')->format('Y-m-d');
    }

    #[On('attendance-updated')]
    #[On('attendance-cleared')]
    #[On('plan-created')]
    #[On('student-list-updated')]
    public function refreshData()
    {
        // Quietly: these come from the other tabs (a mark on the attendance
        // page, a new plan), where a word about the tasmeeh list means nothing,
        // or from the exam editor, which has already said its own.
        $this->refreshToggle = ! $this->refreshToggle;
    }

    /**
     * A saved exam redraws the boxes in place. Unlike refreshData it leaves
     * the rows' keys alone, so the box that opened the editor stays, and
     * focus returns to it when the editor closes.
     */
    #[On('exam-saved')]
    public function examSaved(): void
    {
        //
    }

    public function with()
    {
        $teacher = Auth::guard('teacher')->user();
        $circleIds = $teacher->circles()->pluck('id');

        $students = Student::whereIn('circle_id', $circleIds)
            ->where('status', 'active')
            ->get();

        $todayStr = \Carbon\Carbon::today()->format('Y-m-d');

        // Fetch active plans (or selected plans) for these students
        $activePlans = [];
        $studentPlansList = StudentPlan::whereIn('student_id', $students->pluck('id'))
            ->where('status', 'active')
            ->latest()
            ->get()
            ->groupBy('student_id');

        foreach ($students as $student) {
            $sPlans = $studentPlansList[$student->id] ?? collect();
            if ($sPlans->isEmpty()) {
                continue;
            }

            $activePlans[$student->id] = $sPlans->first();
        }

        // We need all today's plan days for the active plans to check the colors
        $planIdToStudentId = collect($activePlans)->pluck('student_id', 'id')->toArray();
        $todaysPlanDays = StudentPlanDay::whereIn('student_plan_id', collect($activePlans)->pluck('id'))
            ->whereDate('date', $todayStr)
            ->get()
            ->groupBy(function ($day) use ($planIdToStudentId) {
                return $planIdToStudentId[$day->student_plan_id] ?? null;
            });

        $todayAttendances = \App\Models\Attendance::whereIn('student_id', $students->pluck('id'))
            ->whereDate('date', $todayStr)
            ->get()
            ->keyBy('student_id');

        // The supervisor sets when students book their turn; each circle
        // numbers its own queue, which every teacher of the circle sees.
        $turnWindow = \App\Services\TurnBooking::windowToday($teacher->circles()->with('stage')->get());
        $bookingDay = \App\Services\TurnBooking::today();
        $reservations = \App\Services\TurnBooking::turnsBetween($circleIds->all(), $bookingDay, $bookingDay)->keyBy('student_id');

        $studentsWithPlansPresent = [];
        $studentsWithPlansAbsent = [];
        $studentsWithoutPlans = [];

        foreach ($students as $student) {
            if (isset($activePlans[$student->id])) {
                $color = 'red';

                if (isset($todaysPlanDays[$student->id])) {
                    $days = $todaysPlanDays[$student->id];
                    $hasTasks = false;
                    $totalRequired = 0;
                    $completedCount = 0;
                    $hasAnyAchievement = false;

                    foreach ($days as $day) {
                        if ($day->from_ayah_id) {
                            $hasTasks = true;
                            $totalRequired++;
                            if ($day->isRecited('hifz')) {
                                $completedCount++;
                                $hasAnyAchievement = true;
                            }
                        }
                        if ($day->review_from_ayah_id) {
                            $hasTasks = true;
                            $totalRequired++;
                            if ($day->isRecited('review')) {
                                $completedCount++;
                                $hasAnyAchievement = true;
                            }
                        }
                    }

                    if (! $hasTasks) {
                        $color = 'zinc'; // No actual tasks today
                    } elseif ($totalRequired > 0 && $completedCount === $totalRequired) {
                        $color = 'emerald'; // Full
                    } elseif ($hasAnyAchievement) {
                        $color = 'blue'; // Partial
                    } else {
                        $color = 'rose'; // None
                    }
                } else {
                    $color = 'zinc'; // No plan day for today
                }

                $student->tasmeeh_color = $color;
                $student->turn_number = isset($reservations[$student->id]) ? $reservations[$student->id]->turn_number : 9999;

                $attendanceStatus = isset($todayAttendances[$student->id]) ? $todayAttendances[$student->id]->status : 'present';
                if (in_array($attendanceStatus, ['absent', 'excused'])) {
                    $studentsWithPlansAbsent[] = $student;
                } else {
                    $studentsWithPlansPresent[] = $student;
                }
            } else {
                $studentsWithoutPlans[] = $student;
            }
        }

        $studentsWithPlansPresent = collect($studentsWithPlansPresent)->sortBy('turn_number')->values();

        // The small box beside each name in the list. Every student's next exam
        // comes in one query for the whole list rather than one per row, and
        // whether the boxes open the exam editor is asked once for them all.
        $nextExams = NextExamBadge::forStudents($students->modelKeys());
        $canScheduleExams = NextExamBadge::canSchedule();

        // Student cards render lazily (one request each) and fetch their own
        // plan/ode/hadith days via their built-in fallback queries, so nothing is
        // eager-loaded or cached here. This keeps the initial tasmeeh render light
        // and prevents memory exhaustion for teachers with many students or large
        // plans (each card now carries only its own data in its own request).

        return [
            'studentsWithPlansPresent' => $studentsWithPlansPresent,
            'studentsWithPlansAbsent' => collect($studentsWithPlansAbsent),
            'studentsWithoutPlans' => collect($studentsWithoutPlans),
            'activePlans' => $activePlans,
            'studentPlansList' => $studentPlansList,
            'turnWindow' => $turnWindow,
            'nextExams' => $nextExams,
            'canScheduleExams' => $canScheduleExams,
            // A column for the boxes once any is drawn, empty where a student has none.
            'reserveExamColumn' => $canScheduleExams || $nextExams->isNotEmpty(),
        ];
    }
};
?>

{{--
Alpine state:
activeStudentId — tracks which student is being viewed entirely on the client
hifz/review — local state per day card for instant visual feedback
--}}
<div class="space-y-6" x-data="{
        activeStudentId: null,
        openSection: 1,
        selectStudent(id) {
            this.activeStudentId = id;
            setTimeout(() => {
                const el = document.getElementById('grading-area');
                if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }, 100);
        }
     }">

    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('التسميع والمتابعة') }}</flux:heading>
            <flux:subheading>{{ __('اختر الطالب ثم الخطة لعرض المهام المطلوبة وتقييم الإنجاز يومياً.') }}
            </flux:subheading>
        </div>
        <div class="flex flex-col md:flex-row md:items-center gap-2">
            @if($turnWindow)
                <flux:badge color="emerald" variant="pill" icon="clock">
                    {{ __('حجز الأدوار اليوم') }}
                    ({{ $turnWindow->hours() }})
                </flux:badge>
            @endif
        </div>
    </div>

    {{-- Selects & Student List Layout --}}
    {{-- Between lg and xl the list takes a third: with a box on every row a quarter left no room for names. --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 xl:grid-cols-4 gap-6">

        <!-- Students List Sidebar -->
        <div class="lg:col-span-1 flex flex-col gap-4 bg-zinc-50 dark:bg-zinc-900/50 p-4 rounded-2xl border border-zinc-200 dark:border-zinc-800 h-fit max-h-[calc(100vh-140px)] overflow-y-auto lg:sticky lg:top-24 scrollbar-thin">

            <!-- Section 1a: With Plans (Present / Late) -->
            <div
                class="bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-xl overflow-scroll shadow-sm">
                <button @click="openSection = openSection === 1 ? 0 : 1"
                    class="w-full flex items-center justify-between p-3 bg-zinc-50/50 dark:bg-zinc-800/30 hover:bg-zinc-100 dark:hover:bg-zinc-800">
                    <div class="flex items-center gap-2">
                        <flux:icon icon="check-circle" variant="micro" class="text-emerald-500" />
                        <span class="font-bold text-sm text-zinc-700 dark:text-zinc-300">{{ __('حاضر / متأخر') }}</span>
                        <span
                            class="text-xs bg-zinc-200 dark:bg-zinc-700 px-1.5 py-0.5 rounded-md text-zinc-600 dark:text-zinc-400">{{ count($studentsWithPlansPresent) }}</span>
                    </div>
                    <flux:icon icon="chevron-down" class="size-4 text-zinc-400 transition-transform"
                        x-bind:class="openSection === 1 ? 'rotate-180' : ''" />
                </button>
                <div x-show="openSection === 1"
                    class="p-2 space-y-1.5 border-t border-zinc-100 dark:border-zinc-800 max-h-[50vh] overflow-y-auto scrollbar-thin">
                    {{--
                        Each row is two controls side by side: the name opens the
                        student's card, the box at its end their exam. One inside
                        the other would be a button inside a button.
                    --}}
                    @forelse($studentsWithPlansPresent as $student)
                        <div wire:key="present-{{ $student->id }}-{{ $refreshToggle ? '1' : '0' }}" class="flex items-center gap-2">
                            <button type="button" @click="selectStudent({{ $student->id }})"
                                class="flex-1 min-w-0 min-h-10 flex items-center justify-between gap-2 px-2.5 py-2 rounded-xl border text-right transition-colors"
                                :class="activeStudentId == {{ $student->id }} ? 'bg-indigo-50 border-indigo-200 dark:bg-indigo-900/40 dark:border-indigo-800' : 'bg-white dark:bg-zinc-800 border-transparent hover:border-zinc-200 dark:hover:border-zinc-700'">
                                <div class="flex items-center gap-3 min-w-0">
                                    <div
                                        class="size-2.5 rounded-full bg-{{ $student->tasmeeh_color }}-500 shadow-sm shadow-{{ $student->tasmeeh_color }}-500/30 shrink-0">
                                    </div>
                                    <span
                                        class="font-medium text-sm truncate"
                                        :class="activeStudentId == {{ $student->id }} ? 'text-indigo-700 dark:text-indigo-400' : 'text-zinc-700 dark:text-zinc-300'">{{ $student->name }}</span>
                                </div>
                                @if($student->turn_number !== 9999)
                                    <span
                                        class="shrink-0 flex items-center justify-center min-w-[20px] h-5 px-1.5 text-[10px] font-bold rounded-md"
                                        :class="activeStudentId == {{ $student->id }} ? 'bg-indigo-200 text-indigo-800 dark:bg-indigo-800 dark:text-indigo-200' : 'bg-zinc-200 text-zinc-600 dark:bg-zinc-700 dark:text-zinc-300'">
                                        {{ $student->turn_number }}
                                    </span>
                                @endif
                            </button>
                            <x-tasmeeh-exam-box :exam="$nextExams->get($student->id)" :student-id="$student->id" :student-name="$student->name" :can-schedule="$canScheduleExams" :reserve="$reserveExamColumn" />
                        </div>
                    @empty
                        <div class="text-xs text-center text-zinc-400 py-3">{{ __('لا يوجد طلاب حالياً.') }}</div>
                    @endforelse
                </div>
            </div>

            <!-- Section 1b: With Plans (Absent / Excused) -->
            @if($studentsWithPlansAbsent->isNotEmpty())
                <div
                    class="bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-xl overflow-hidden shadow-sm shrink-0">
                    <button @click="openSection = openSection === 2 ? 0 : 2"
                        class="w-full flex items-center justify-between p-3 bg-zinc-50/50 dark:bg-zinc-800/30 hover:bg-zinc-100 dark:hover:bg-zinc-800">
                        <div class="flex items-center gap-2">
                            <flux:icon icon="x-circle" variant="micro" class="text-rose-500" />
                            <span class="font-bold text-sm text-zinc-700 dark:text-zinc-300">{{ __('غائب / معتذر') }}</span>
                            <span
                                class="text-xs bg-zinc-200 dark:bg-zinc-700 px-1.5 py-0.5 rounded-md text-zinc-600 dark:text-zinc-400">{{ count($studentsWithPlansAbsent) }}</span>
                        </div>
                        <flux:icon icon="chevron-down" class="size-4 text-zinc-400 transition-transform"
                            x-bind:class="openSection === 2 ? 'rotate-180' : ''" />
                    </button>
                    <div x-show="openSection === 2"
                        class="p-2 space-y-1.5 border-t border-zinc-100 dark:border-zinc-800 max-h-[50vh] overflow-y-auto scrollbar-thin">
                        @forelse($studentsWithPlansAbsent as $student)
                            <div wire:key="absent-{{ $student->id }}-{{ $refreshToggle ? '1' : '0' }}" class="flex items-center gap-2">
                                <button type="button" @click="selectStudent({{ $student->id }})"
                                    class="flex-1 min-w-0 min-h-10 flex items-center gap-3 px-2.5 py-2 rounded-xl border text-right transition-colors"
                                    :class="activeStudentId == {{ $student->id }} ? 'bg-indigo-50 border-indigo-200 dark:bg-indigo-900/40 dark:border-indigo-800' : 'bg-rose-50 dark:bg-rose-900/10 border-transparent hover:border-rose-200 dark:hover:border-rose-800/50 opacity-75 hover:opacity-100'">
                                    <div
                                        class="size-2.5 rounded-full bg-{{ $student->tasmeeh_color }}-500 shadow-sm shadow-{{ $student->tasmeeh_color }}-500/30 shrink-0">
                                    </div>
                                    <span
                                        class="font-medium text-sm truncate"
                                        :class="activeStudentId == {{ $student->id }} ? 'text-indigo-700 dark:text-indigo-400' : 'text-rose-700 dark:text-rose-400'">{{ $student->name }}</span>
                                </button>
                                <x-tasmeeh-exam-box :exam="$nextExams->get($student->id)" :student-id="$student->id" :student-name="$student->name" :can-schedule="$canScheduleExams" :reserve="$reserveExamColumn" />
                            </div>
                        @empty
                        @endforelse
                    </div>
                </div>
            @endif

            <!-- Section 1c: Without Plans -->
            @if($studentsWithoutPlans->isNotEmpty())
                <div
                    class="bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-xl overflow-hidden shadow-sm shrink-0">
                    <button @click="openSection = openSection === 3 ? 0 : 3"
                        class="w-full flex items-center justify-between p-3 bg-zinc-50/50 dark:bg-zinc-800/30 hover:bg-zinc-100 dark:hover:bg-zinc-800">
                        <div class="flex items-center gap-2">
                            <flux:icon icon="document-minus" variant="micro" class="text-zinc-400" />
                            <span
                                class="font-bold text-sm text-zinc-700 dark:text-zinc-300">{{ __('غير المجدولين') }}</span>
                            <span
                                class="text-xs bg-zinc-200 dark:bg-zinc-700 px-1.5 py-0.5 rounded-md text-zinc-600 dark:text-zinc-400">{{ count($studentsWithoutPlans) }}</span>
                        </div>
                        <flux:icon icon="chevron-down" class="size-4 text-zinc-400 transition-transform"
                            x-bind:class="openSection === 3 ? 'rotate-180' : ''" />
                    </button>
                    <div x-show="openSection === 3"
                        class="p-2 space-y-1.5 border-t border-zinc-100 dark:border-zinc-800 max-h-[50vh] overflow-y-auto scrollbar-thin">
                        @forelse($studentsWithoutPlans as $student)
                            <div wire:key="noplan-{{ $student->id }}-{{ $refreshToggle ? '1' : '0' }}" class="flex items-center gap-2">
                                <button type="button" @click="selectStudent({{ $student->id }})"
                                    class="flex-1 min-w-0 min-h-10 flex items-center px-2.5 py-2 rounded-xl border text-right transition-colors"
                                    :class="activeStudentId == {{ $student->id }} ? 'bg-indigo-50 border-indigo-200 dark:bg-indigo-900/40 dark:border-indigo-800' : 'bg-zinc-100/50 dark:bg-zinc-800/30 border-transparent hover:border-zinc-200 dark:hover:border-zinc-700'">
                                    <span
                                        class="font-medium text-sm truncate"
                                        :class="activeStudentId == {{ $student->id }} ? 'text-indigo-700 dark:text-indigo-400' : 'text-zinc-500 dark:text-zinc-400'">{{ $student->name }}</span>
                                </button>
                                <x-tasmeeh-exam-box :exam="$nextExams->get($student->id)" :student-id="$student->id" :student-name="$student->name" :can-schedule="$canScheduleExams" :reserve="$reserveExamColumn" />
                                <a href="{{ route('teacher.plan-creator', ['studentId' => $student->id]) }}"
                                    class="shrink-0 p-2.5 text-emerald-600 hover:text-white bg-emerald-50 hover:bg-emerald-500 dark:text-emerald-400 dark:bg-emerald-900/20 dark:hover:bg-emerald-600 rounded-xl   s"
                                    title="{{ __('إنشاء خطة') }}">
                                    <flux:icon icon="plus" class="size-4" variant="mini" />
                                </a>
                            </div>
                        @empty
                        @endforelse
                    </div>
                </div>
            @endif

        </div>

        <!-- Main Content Area -->
        <div id="grading-area" class="lg:col-span-2 xl:col-span-3 space-y-6 scroll-mt-6">
            {{-- Desktop only: on a phone the roll sits right above, and a 400px card
                 pointing at "the side list" only pushed it out of reach. --}}
            <div x-show="!activeStudentId" class="hidden lg:flex flex-col items-center justify-center p-12 bg-zinc-50/50 dark:bg-zinc-900/50 border border-dashed border-zinc-200 dark:border-zinc-800 rounded-2xl text-center h-full min-h-[400px]">
                <flux:icon icon="user-group" class="size-16 text-zinc-300 dark:text-zinc-600 mb-4" />
                <flux:heading size="lg" class="text-zinc-500 dark:text-zinc-400 mb-2">{{ __('اختر طالباً للبدء') }}</flux:heading>
                <p class="text-zinc-400 dark:text-zinc-500 text-sm max-w-sm">
                    {{ __('قم باختيار أحد الطلاب من القائمة الجانبية لعرض خطته القرآنية والبدء بتقييم التسميع والمراجعة.') }}
                </p>
            </div>

            <!-- Global Grading Date Setting -->
            <div x-show="activeStudentId" x-cloak class="bg-white dark:bg-zinc-900 p-4 rounded-2xl border border-zinc-200 dark:border-zinc-800 shadow-sm mb-4">
                <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
                    <div>
                        <flux:label>{{ __('تاريخ التقييم (الإنجاز الفعلي)') }}</flux:label>
                        <flux:description class="text-[11px] mt-0.5">{{ __('سيُستخدم هذا التاريخ لتسجيل إنجاز الطالب في المسابقات والتقارير.') }}</flux:description>
                    </div>
                    <div class="w-full md:w-64">
                        <livewire:teacher.hijri-datepicker wire:model.live="gradedAtDate" />
                    </div>
                </div>
            </div>

            @foreach($studentsWithPlansPresent->merge($studentsWithPlansAbsent)->merge($studentsWithoutPlans) as $student)
                <div x-show="activeStudentId == {{ $student->id }}" x-cloak>
                    <livewire:teacher.student-tasmeeh-card
                        lazy
                        :wire:key="'student-card-'.$student->id"
                        :student="$student"
                        :s-plans="$studentPlansList[$student->id] ?? collect()"
                        :active-plan-id="$activePlans[$student->id]->id ?? null"
                        :graded-at-date="$gradedAtDate"
                    />
                </div>
            @endforeach
        </div>
    </div>

    {{-- The one exam editor every box on the page opens, in the list and in the cards. --}}
    <livewire:teacher.tasmeeh-exam-editor wire:key="tasmeeh-exam-editor" />
</div>