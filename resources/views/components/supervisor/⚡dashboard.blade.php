<?php

use App\Models\Attendance;
use App\Models\Circle;
use App\Models\Leaderboard;
use App\Models\Stage;
use App\Models\Student;
use App\Models\StudentExam;
use App\Models\Teacher;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new class extends Component
{
    public function with(): array
    {
        $supervisor = Auth::guard('supervisor')->user();
        $stageIds = $supervisor->stages()->pluck('stages.id');
        $circleIds = Circle::whereIn('stage_id', $stageIds)->pluck('id');

        $weekStart = now()->startOfWeek(\Carbon\Carbon::SATURDAY);
        $weekEnd = now()->endOfWeek(\Carbon\Carbon::FRIDAY);

        $weeklyAttendance = Attendance::whereIn('circle_id', $circleIds)
            ->whereBetween('date', [$weekStart, $weekEnd])
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $presentLike = (int) ($weeklyAttendance['present'] ?? 0) + (int) ($weeklyAttendance['late'] ?? 0);
        $recorded = $presentLike + (int) ($weeklyAttendance['absent'] ?? 0);
        $weeklyAttendancePercentage = $recorded > 0 ? (int) round($presentLike / $recorded * 100) : 0;

        $studentIds = Student::whereIn('circle_id', $circleIds)->pluck('id');

        return [
            'stagesCount' => $stageIds->count(),
            'circlesCount' => $circleIds->count(),
            'teachersCount' => Teacher::whereHas('circles', fn ($q) => $q->whereIn('circles.id', $circleIds))->count(),
            'studentsCount' => $studentIds->count(),
            'weeklyAttendancePercentage' => $weeklyAttendancePercentage,
            'upcomingExamsCount' => StudentExam::whereIn('student_id', $studentIds)
                ->where('status', 'pending')
                ->where('date_time', '>=', now())
                ->count(),
            'activeCompetitionsCount' => Leaderboard::whereHas('circles', fn ($q) => $q->whereIn('circles.id', $circleIds))
                ->where('is_active', true)
                ->count(),
            'recentCircles' => Circle::whereIn('id', $circleIds)->withCount('students')->with('stage')->latest()->take(5)->get(),
        ];
    }
};
?>

<div class="space-y-6" dir="rtl">
    <div class="flex items-center gap-3">
        <div class="p-2.5 rounded-xl bg-maroon/10 text-maroon dark:bg-white/10 dark:text-white">
            <flux:icon icon="squares-2x2" />
        </div>
        <div>
            <flux:heading size="xl" class="font-bold text-zinc-900 dark:text-white">{{ __('لوحة تحكم الإشراف') }}</flux:heading>
            <flux:subheading class="text-zinc-400">
                <span>{{ __('الرئيسية') }}</span>
            </flux:subheading>
        </div>
    </div>

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <x-student.partials.stat-card icon="rectangle-stack" :label="__('المراحل التعليمية')" :value="$stagesCount" accent-class="bg-indigo-500/10 text-indigo-600" />
        <x-student.partials.stat-card icon="circle-stack" :label="__('الحلقات')" :value="$circlesCount" accent-class="bg-teal-500/10 text-teal-600" />
        <x-student.partials.stat-card icon="users" :label="__('المعلمون')" :value="$teachersCount" accent-class="bg-blue-500/10 text-blue-600" />
        <x-student.partials.stat-card icon="academic-cap" :label="__('الطلاب')" :value="$studentsCount" accent-class="bg-purple-500/10 text-purple-600" />
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="rounded-2xl border border-zinc-100 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-5">
            <div class="flex items-center justify-between">
                <div>
                    <div class="text-2xl font-black text-emerald-600">{{ $weeklyAttendancePercentage }}%</div>
                    <div class="text-sm text-zinc-500 dark:text-zinc-400 mt-1">{{ __('نسبة الحضور هذا الأسبوع') }}</div>
                </div>
                <flux:icon icon="chart-bar" class="size-8 text-emerald-500" />
            </div>
        </div>
        <div class="rounded-2xl border border-zinc-100 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-5">
            <div class="flex items-center justify-between">
                <div>
                    <div class="text-2xl font-black text-amber-600">{{ $upcomingExamsCount }}</div>
                    <div class="text-sm text-zinc-500 dark:text-zinc-400 mt-1">{{ __('اختبارات قادمة') }}</div>
                </div>
                <flux:icon icon="academic-cap" class="size-8 text-amber-500" />
            </div>
        </div>
        <a href="{{ route('supervisor.competitions') }}" wire:navigate class="block">
            <div class="rounded-2xl border border-zinc-100 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-5 hover:border-rose-300 transition-colors">
                <div class="flex items-center justify-between">
                    <div>
                        <div class="text-2xl font-black text-rose-600">{{ $activeCompetitionsCount }}</div>
                        <div class="text-sm text-zinc-500 dark:text-zinc-400 mt-1">{{ __('مسابقات نشطة') }}</div>
                    </div>
                    <flux:icon icon="trophy" class="size-8 text-rose-500" />
                </div>
            </div>
        </a>
    </div>

    <div class="rounded-2xl border border-zinc-100 dark:border-zinc-800 bg-white dark:bg-zinc-900 overflow-hidden">
        <div class="p-5 border-b border-zinc-100 dark:border-zinc-800 flex items-center justify-between">
            <flux:heading size="sm">{{ __('الحلقات') }}</flux:heading>
            <flux:link :accent="false" :href="route('supervisor.circles')" wire:navigate>{{ __('عرض الكل') }}</flux:link>
        </div>
        @if($recentCircles->isEmpty())
            <p class="text-sm text-zinc-400 text-center py-10">{{ __('لا توجد حلقات ضمن نطاق إشرافك بعد') }}</p>
        @else
            <div class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @foreach($recentCircles as $circle)
                    <div class="flex items-center justify-between p-4">
                        <div>
                            <div class="font-bold text-zinc-800 dark:text-zinc-100">{{ $circle->name }}</div>
                            <div class="text-xs text-zinc-400">{{ $circle->stage?->name }}</div>
                        </div>
                        <flux:badge color="zinc" size="sm">{{ $circle->students_count }} {{ __('طالب') }}</flux:badge>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
