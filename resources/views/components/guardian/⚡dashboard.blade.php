<?php

use App\Models\Attendance;
use App\Models\GuardianNotification;
use App\Models\StudentPlanDay;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    /**
     * The guardian's most recent in-app notifications (absences, weekly digests).
     *
     * @return \Illuminate\Support\Collection<int, GuardianNotification>
     */
    #[Computed]
    public function notifications()
    {
        return GuardianNotification::where('guardian_id', Auth::guard('guardian')->id())
            ->latest()
            ->limit(20)
            ->get();
    }

    public function markAllNotificationsRead(): void
    {
        GuardianNotification::where('guardian_id', Auth::guard('guardian')->id())
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        unset($this->notifications);
    }

    /**
     * The guardian's children with all their dashboard stats pre-computed in a
     * handful of batched queries (instead of a query-per-child inside the view).
     *
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function children(): array
    {
        $guardian = Auth::guard('guardian')->user();
        $students = $guardian->students()->with('circle')->get();
        $studentIds = $students->pluck('id')->all();

        if (empty($studentIds)) {
            return [];
        }

        // Batched: today's hifz plan day for every child in one query.
        $todayPlanDays = StudentPlanDay::whereDate('date', today())
            ->whereHas('plan', fn ($q) => $q->whereIn('student_id', $studentIds)->whereIn('plan_type', ['hifz', 'hifz_review']))
            ->with(['plan:id,student_id', 'fromAyah.surah', 'toAyah.surah'])
            ->get()
            ->keyBy(fn ($day) => $day->plan->student_id);

        // Batched: latest scored day per child. Ordered newest-first then reduced
        // to the first row per student, so each child keeps only their last score.
        $lastScored = StudentPlanDay::query()
            ->select('student_plan_days.*', 'student_plans.student_id as guardian_sid')
            ->join('student_plans', 'student_plan_days.student_plan_id', '=', 'student_plans.id')
            ->whereIn('student_plans.student_id', $studentIds)
            ->whereNotNull('student_plan_days.hifz_achievement')
            ->orderByDesc('student_plan_days.date')
            ->orderByDesc('student_plan_days.id')
            ->get()
            ->unique('guardian_sid')
            ->keyBy('guardian_sid');

        // Batched: this week's attendance for every child in one query.
        $weekStart = now()->startOfWeek(\Carbon\Carbon::SATURDAY);
        $attendance = Attendance::whereIn('student_id', $studentIds)
            ->whereBetween('date', [$weekStart, now()])
            ->get()
            ->groupBy('student_id');

        return $students->map(function ($student) use ($todayPlanDays, $lastScored, $attendance) {
            // Compute the memorized pages once and derive the percentage from it,
            // instead of calling memorizationPercentage() which recomputes the range.
            $memorizedPages = $student->memorizedPagesCount();
            $weekAttend = $attendance->get($student->id) ?? collect();

            return [
                'model' => $student,
                'today' => $todayPlanDays->get($student->id),
                'lastScored' => $lastScored->get($student->id),
                'presentCount' => $weekAttend->whereIn('status', ['present', 'late'])->count(),
                'totalCount' => $weekAttend->count(),
                'memorizedPages' => $memorizedPages,
                'percentage' => round($memorizedPages / 604 * 100, 1),
            ];
        })->all();
    }
}; ?>

<div class="space-y-6" dir="rtl">
    <x-shared.pending-surveys />

    @php
        $guardian = auth()->guard('guardian')->user();
    @endphp

    <div class="flex items-center gap-3">
        <div class="p-2.5 rounded-xl bg-maroon/10 text-maroon dark:bg-white/10 dark:text-white">
            <flux:icon icon="home" />
        </div>
        <div>
            <flux:heading size="xl" class="font-bold text-zinc-900 dark:text-white">لوحة تحكم ولي الأمر</flux:heading>
            <flux:subheading class="text-zinc-400">
                <span>الرئيسية</span>
            </flux:subheading>
        </div>
    </div>

    <div class="grid auto-rows-min gap-4 md:grid-cols-3">
        <div class="relative overflow-hidden rounded-2xl border border-zinc-100 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-6">
            <div class="flex items-center gap-4">
                <div class="p-3 bg-blue-100 text-blue-600 rounded-lg dark:bg-blue-900/30 dark:text-blue-400">
                    <flux:icon icon="users" class="size-6" />
                </div>
                <div>
                    <p class="text-sm font-medium text-zinc-500 dark:text-zinc-400">عدد الأبناء</p>
                    <h3 class="text-2xl font-bold text-zinc-900 dark:text-white">{{ count($this->children) }}</h3>
                </div>
            </div>
        </div>

        <div class="relative overflow-hidden rounded-2xl border border-zinc-100 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-6">
            <div class="flex items-center gap-4">
                <div class="p-3 bg-green-100 text-green-600 rounded-lg dark:bg-green-900/30 dark:text-green-400">
                    <flux:icon icon="check-circle" class="size-6" />
                </div>
                <div>
                    <p class="text-sm font-medium text-zinc-500 dark:text-zinc-400">حالة الاعتماد</p>
                    <h3 class="text-xl font-bold text-zinc-900 dark:text-white">
                        {{ $guardian->is_approved ? 'معتمد' : 'قيد الانتظار' }}
                    </h3>
                </div>
            </div>
        </div>
    </div>

    @php
        $unreadCount = $this->notifications->whereNull('read_at')->count();
    @endphp
    <div class="relative rounded-2xl border border-zinc-100 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-6">
        <div class="flex items-center justify-between mb-4">
            <h2 class="text-lg font-bold flex items-center gap-2">
                <flux:icon icon="bell" class="size-5" />
                آخر التنبيهات
                @if($unreadCount > 0)
                    <span class="flex items-center justify-center min-w-5 h-5 px-1.5 rounded-full bg-rose-500 text-white text-[11px] font-black">{{ $unreadCount > 9 ? '9+' : $unreadCount }}</span>
                @endif
            </h2>
            @if($unreadCount > 0)
                <flux:button wire:click="markAllNotificationsRead" size="xs" variant="ghost" icon="check">
                    تعليم الكل كمقروء
                </flux:button>
            @endif
        </div>

        <div class="space-y-2">
            @forelse($this->notifications as $notification)
                @php
                    $isUnread = $notification->read_at === null;
                    $tone = match ($notification->type) {
                        'absence' => 'bg-rose-50 dark:bg-rose-500/10 border-rose-100 dark:border-rose-500/20',
                        'late' => 'bg-amber-50 dark:bg-amber-500/10 border-amber-100 dark:border-amber-500/20',
                        default => 'bg-blue-50 dark:bg-blue-500/10 border-blue-100 dark:border-blue-500/20',
                    };
                    $icon = match ($notification->type) {
                        'absence' => 'x-circle',
                        'late' => 'clock',
                        default => 'sparkles',
                    };
                @endphp
                <div class="flex items-start gap-3 rounded-xl border p-3 {{ $isUnread ? $tone : 'bg-zinc-50 dark:bg-zinc-900 border-zinc-100 dark:border-zinc-800' }}">
                    <flux:icon icon="{{ $icon }}" class="size-4 mt-0.5 shrink-0 {{ $isUnread ? '' : 'text-zinc-400' }}" />
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2">
                            <p class="text-sm font-bold {{ $isUnread ? 'text-zinc-900 dark:text-zinc-100' : 'text-zinc-500' }}">{{ $notification->title }}</p>
                            @if($isUnread)
                                <span class="size-2 rounded-full bg-rose-500 shrink-0"></span>
                            @endif
                        </div>
                        <p class="text-xs whitespace-pre-line {{ $isUnread ? 'text-zinc-600 dark:text-zinc-300' : 'text-zinc-400' }} leading-relaxed mt-0.5">{{ $notification->body }}</p>
                        <p class="text-[10px] text-zinc-400 mt-1">{{ $notification->created_at->diffForHumans() }}</p>
                    </div>
                </div>
            @empty
                <div class="text-center py-6 text-sm text-zinc-400">لا توجد تنبيهات حالياً</div>
            @endforelse
        </div>
    </div>

    <div class="relative h-full flex-1 rounded-2xl border border-zinc-100 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-6">
        <h2 class="text-lg font-bold mb-4">بيانات الأبناء</h2>

        <div class="space-y-4">
            @forelse($this->children as $child)
                @php
                    $student = $child['model'];
                    $todayPlanDay = $child['today'];
                    $lastScored = $child['lastScored'];
                    $presentCount = $child['presentCount'];
                    $totalCount = $child['totalCount'];
                    $memorizedPages = $child['memorizedPages'];
                    $percentage = $child['percentage'];
                @endphp

                <div class="p-4 rounded-xl bg-zinc-50 dark:bg-zinc-900 border border-zinc-100 dark:border-zinc-800">

                    {{-- Header row --}}
                    <div class="flex items-center justify-between mb-4">
                        <div class="flex items-center gap-3">
                            <div class="p-2 bg-zinc-200 dark:bg-zinc-700 rounded-lg">
                                <flux:icon icon="academic-cap" class="size-5 text-zinc-600 dark:text-zinc-300" />
                            </div>
                            <div>
                                <h4 class="font-semibold text-zinc-900 dark:text-zinc-100">{{ $student->name }}</h4>
                                <p class="text-xs text-zinc-500">
                                    {{ $student->circle?->name ?? 'لم تُحدَّد حلقة بعد' }}
                                </p>
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            <a href="{{ route('guardian.student.challenge.create', $student->id) }}"
                                class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg bg-indigo-50 dark:bg-indigo-500/10 text-indigo-700 dark:text-indigo-300 hover:bg-indigo-100 dark:hover:bg-indigo-500/20 transition-colors">
                                <flux:icon icon="trophy" class="size-3.5" />
                                مكافأة جديدة
                            </a>
                            <a href="{{ route('guardian.student', $student->id) }}"
                                class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg bg-blue-50 dark:bg-blue-500/10 text-blue-700 dark:text-blue-300 hover:bg-blue-100 dark:hover:bg-blue-500/20 transition-colors">
                                التفاصيل
                                <flux:icon icon="arrow-left" class="size-3.5" />
                            </a>
                        </div>
                    </div>

                    {{-- Stats row --}}
                    <div class="grid grid-cols-3 gap-3 mb-4">

                        {{-- Today's task --}}
                        <div class="rounded-lg bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 p-3">
                            <p class="text-xs text-zinc-500 mb-1 flex items-center gap-1">
                                <flux:icon icon="calendar-days" class="size-3.5" />
                                مهمة اليوم
                            </p>
                            @if($todayPlanDay && $todayPlanDay->fromAyah)
                                <p class="text-xs font-medium text-zinc-800 dark:text-zinc-200 leading-relaxed">
                                    {{ $todayPlanDay->fromAyah->surah->name_arabic }}
                                    {{ $todayPlanDay->fromAyah->verse_number }}-{{ $todayPlanDay->toAyah->verse_number }}
                                </p>
                            @else
                                <p class="text-xs text-zinc-400">لا توجد مهمة</p>
                            @endif
                        </div>

                        {{-- Last score --}}
                        <div class="rounded-lg bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 p-3">
                            <p class="text-xs text-zinc-500 mb-1 flex items-center gap-1">
                                <flux:icon icon="star" class="size-3.5" />
                                آخر تقييم
                            </p>
                            @if($lastScored)
                                @php
                                    $scoreColor = match ($lastScored->hifz_achievement) {
                                        3 => 'text-emerald-600 dark:text-emerald-400',
                                        2 => 'text-amber-600 dark:text-amber-400',
                                        default => 'text-red-600 dark:text-red-400',
                                    };
                                    $scoreLabel = match ($lastScored->hifz_achievement) {
                                        3 => 'ممتاز',
                                        2 => 'جيد',
                                        default => 'ضعيف',
                                    };
                                @endphp
                                <div class="flex items-center gap-1.5">
                                    <span class="text-xs font-bold {{ $scoreColor }}">{{ $scoreLabel }}</span>
                                    <span class="text-xs text-zinc-400">({{ $lastScored->date->diffForHumans() }})</span>
                                </div>
                            @else
                                <p class="text-xs text-zinc-400">لا يوجد بعد</p>
                            @endif
                        </div>

                        {{-- Weekly attendance --}}
                        <div class="rounded-lg bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 p-3">
                            <p class="text-xs text-zinc-500 mb-1 flex items-center gap-1">
                                <flux:icon icon="clock" class="size-3.5" />
                                هذا الأسبوع
                            </p>
                            @if($totalCount > 0)
                                <p class="text-xs font-medium text-zinc-800 dark:text-zinc-200">
                                    {{ $presentCount }}/{{ $totalCount }} أيام
                                </p>
                            @else
                                <p class="text-xs text-zinc-400">لا توجد بيانات</p>
                            @endif
                        </div>
                    </div>

                    {{-- Memorization progress --}}
                    <div>
                        <div class="flex items-center justify-between mb-1.5">
                            <span class="text-xs text-zinc-500 flex items-center gap-1">
                                <flux:icon icon="book-open" class="size-3.5" />
                                نسبة المحفوظ من القرآن الكريم
                            </span>
                            <div class="flex items-center gap-2">
                                <span class="text-xs text-zinc-500">{{ number_format($memorizedPages) }} صفحة</span>
                                <span class="text-sm font-bold text-emerald-600 dark:text-emerald-400">{{ $percentage }}%</span>
                            </div>
                        </div>
                        <div class="w-full bg-zinc-200 dark:bg-zinc-700 rounded-full h-2 overflow-hidden">
                            <div class="h-2 rounded-full bg-gradient-to-r from-emerald-400 to-emerald-600 transition-all duration-500"
                                style="width: {{ min($percentage, 100) }}%"></div>
                        </div>
                        @if($memorizedPages > 0)
                            <p class="text-xs text-zinc-400 mt-1">
                                ≈ {{ floor($memorizedPages / 20) }} جزء من 30
                            </p>
                        @endif
                    </div>
                </div>
            @empty
                <div class="text-center py-8 text-zinc-500">
                    لا يوجد أبناء مسجلين حالياً
                </div>
            @endforelse
        </div>
    </div>
</div>
