<?php

use Livewire\Component;
use Illuminate\Support\Facades\Auth;
use App\Models\StudentPlan;
use App\Models\StudentPlanDay;
use Illuminate\Support\Facades\DB;

new class extends Component {
    public function with()
    {
        $studentId = Auth::guard('student')->id();

        $plans = StudentPlan::where('student_id', $studentId)
            ->withCount('days')
            ->orderBy('created_at', 'desc')
            ->get();

        $totalDays = $plans->sum('days_count');
        $gradedDays = StudentPlanDay::whereIn('student_plan_id', $plans->pluck('id'))
            ->where(function ($q) {
                $q->where('hifz_achievement', '>=', 1)->orWhere('review_achievement', '>=', 1);
            })
            ->count();
        $overallPercentage = $totalDays > 0 ? round(min($gradedDays, $totalDays) / $totalDays * 100, 1) : 0;

        return [
            'plans' => $plans,
            'overallPercentage' => $overallPercentage,
        ];
    }
    
    public function deletePlan($planId)
    {
        $plan = StudentPlan::findOrFail($planId);
        if (!$plan->is_approved && $plan->created_by_role === 'student' && $plan->student_id === Auth::guard('student')->id()) {
            DB::transaction(function () use ($plan) {
                $plan->days()->delete();
                $plan->delete();
            });
            $this->redirect(route('student.plan'), navigate: true);
        }
    }
};
?>

<div class="space-y-6" dir="rtl">
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
            <flux:heading size="xl" class="font-bold text-zinc-900 dark:text-white">
                {{ __('خططي القرآنية') }}
            </flux:heading>
            <flux:subheading class="text-zinc-500 dark:text-zinc-400 mt-1">
                {{ __('إدارة كافة خطط الحفظ والمراجعة الخاصة بك.') }}
            </flux:subheading>
        </div>
        
        <flux:button variant="primary" icon="plus" href="{{ route('student.plan-creator') }}">
            {{ __('إنشاء مسار جديد') }}
        </flux:button>
    </div>

    @if($plans->isEmpty())
        <flux:card class="border-t-4 border-t-zinc-400 border-zinc-200 dark:border-zinc-800 bg-zinc-50 dark:bg-zinc-900/50">
            <div class="flex flex-col items-center justify-center py-12 text-center space-y-4">
                <div class="bg-zinc-200 dark:bg-zinc-800 p-4 rounded-full text-zinc-500">
                    <flux:icon icon="puzzle-piece" class="size-8" />
                </div>
                <div>
                    <h3 class="text-lg font-bold text-zinc-700 dark:text-zinc-300">{{ __('لا توجد خطط مسجلة لك بعد') }}</h3>
                    <p class="text-zinc-500 text-sm mt-1 max-w-sm">{{ __('بادر بإنشاء مسارك القرآني لتنظيم حفظك ومراجعتك.') }}</p>
                </div>
                <flux:button variant="primary" href="{{ route('student.plan-creator') }}">
                    {{ __('إنشاء مسار قرآني الآن') }}
                </flux:button>
            </div>
        </flux:card>
    @else
        <flux:card class="flex items-center gap-6">
            <x-student.partials.progress-ring :percentage="$overallPercentage" :size="96" :stroke-width="8">
                <span class="text-lg font-bold text-zinc-700 dark:text-zinc-200">{{ $overallPercentage }}%</span>
            </x-student.partials.progress-ring>
            <div>
                <h3 class="text-lg font-bold text-zinc-800 dark:text-zinc-100">{{ __('إنجازك الكلي في كل خططك') }}</h3>
                <p class="text-sm text-zinc-500 dark:text-zinc-400 mt-1">{{ __('نسبة الأيام اللي سجّلت فيها تقييم من إجمالي أيام كل خططك.') }}</p>
            </div>
        </flux:card>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            @foreach($plans as $plan)
                <flux:card class="flex flex-col justify-between hover:shadow-md transition-shadow relative overflow-hidden group">
                    <!-- Status Ribbon -->
                    @if($plan->is_approved && $plan->status !== 'active')
                        <div class="absolute top-0 right-0 bg-zinc-400 text-white text-xs font-bold px-4 py-1 rounded-bl-xl shadow-sm z-10 flex items-center gap-1">
                            <flux:icon icon="pause-circle" class="size-3" />
                            {{ __('غير فعالة') }}
                        </div>
                    @elseif($plan->is_approved)
                        <div class="absolute top-0 right-0 bg-emerald-500 text-white text-xs font-bold px-4 py-1 rounded-bl-xl shadow-sm z-10 flex items-center gap-1">
                            <flux:icon icon="check-circle" class="size-3" />
                            {{ __('معتمدة') }}
                        </div>
                        <div class="absolute -right-10 -bottom-10 opacity-[0.03] text-emerald-900 z-0 pointer-events-none group-hover:scale-110 transition-transform duration-500">
                            <flux:icon icon="check-badge" class="w-48 h-48" />
                        </div>
                    @else
                        <div class="absolute top-0 right-0 bg-amber-500 text-white text-xs font-bold px-4 py-1 rounded-bl-xl shadow-sm z-10 flex items-center gap-1">
                            <flux:icon icon="clock" class="size-3" />
                            {{ __('قيد الاعتماد') }}
                        </div>
                    @endif

                    <div class="relative z-10">
                        <div class="flex items-start gap-4 mb-4 mt-2">
                            <div class="flex-shrink-0 bg-zinc-100 dark:bg-zinc-800 rounded-xl p-3 text-zinc-500 dark:text-zinc-400">
                                @if($plan->plan_type === 'hifz')
                                    <flux:icon icon="book-open" class="size-6" />
                                @elseif($plan->plan_type === 'review')
                                    <flux:icon icon="arrow-path" class="size-6" />
                                @else
                                    <flux:icon icon="document-duplicate" class="size-6" />
                                @endif
                            </div>
                            <div>
                                <h3 class="text-xl font-bold text-zinc-800 dark:text-zinc-100 mb-1">
                                    {{ $plan->description ?? __('خطة بدون عنوان') }}
                                </h3>
                                <div class="text-sm text-zinc-500 dark:text-zinc-400 flex items-center gap-4">
                                    <span class="flex items-center gap-1"><flux:icon icon="calendar-days" class="size-4"/> {{ __('منذ:') }} {{ $plan->created_at->diffForHumans() }}</span>
                                    <span class="flex items-center gap-1"><flux:icon icon="list-bullet" class="size-4"/> {{ $plan->days_count }} {{ __('أيام') }}</span>
                                </div>
                            </div>
                        </div>

                        @php
                            $distribution = $plan->achievementDistribution();
                        @endphp
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 items-center bg-zinc-50 dark:bg-zinc-800/50 rounded-xl p-4">
                            <x-student.partials.plan-progress-bar :percentage="$plan->completionPercentage()" />
                            <x-student.partials.achievement-distribution-ring
                                :excellent="$distribution['excellent']"
                                :good="$distribution['good']"
                                :weak="$distribution['weak']"
                                :not-heard="$distribution['not_heard']"
                                :size="72" />
                        </div>

                        <div class="flex items-center gap-2 mt-6">
                            <flux:button class="flex-1" variant="filled" icon="eye" href="{{ route('student.plan.print', ['kind' => 'quran', 'id' => $plan->id]) }}">
                                {{ __('عرض الجدول اليومي') }}
                            </flux:button>

                            @if(!$plan->is_approved && $plan->created_by_role === 'student')
                                <flux:button variant="ghost" class="text-zinc-500 hover:text-zinc-900" icon="pencil" href="{{ route('student.plan-creator', ['edit' => $plan->id]) }}" />
                                <flux:button variant="ghost" class="text-red-500 hover:text-red-700 hover:bg-red-50" icon="trash" wire:click="deletePlan({{ $plan->id }})" wire:confirm="{{ __('هل أنت متأكد من حذف الخطة المرفوعة وإلغائها بشكل نهائي؟') }}" />
                            @endif
                        </div>
                    </div>
                </flux:card>
            @endforeach
        </div>
    @endif
</div>
