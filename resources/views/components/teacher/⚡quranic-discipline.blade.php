<?php

use Livewire\Component;
use App\Models\Student;
use App\Models\StudentPlanDay;
use Illuminate\Support\Facades\Auth;

new class extends Component {
    public $startDate;
    public $endDate;
    public $sortField = 'unrecited';
    public $sortOrder = 'desc';

    public function mount()
    {
        $this->startDate = now()->startOfMonth()->format('Y-m-d');
        $this->endDate = now()->format('Y-m-d');
    }

    public function setDateRange($range)
    {
        $this->endDate = now()->format('Y-m-d');
        if ($range === 'week') {
            $this->startDate = now()->subDays(7)->format('Y-m-d');
        } elseif ($range === 'month') {
            $this->startDate = now()->subDays(30)->format('Y-m-d');
        } elseif ($range === 'all') {
            $this->startDate = now()->subYears(1)->format('Y-m-d');
            $this->endDate = now()->addDays(30)->format('Y-m-d');
        }
    }

    public function sortBy($field)
    {
        if ($this->sortField === $field) {
            $this->sortOrder = $this->sortOrder === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortField = $field;
            $this->sortOrder = 'desc';
        }
    }

    public function with()
    {
        $teacher = Auth::guard('teacher')->user();

        // احصل على الطلاب المنسوبين للحلقات التي يدرسها المعلم
        $circleIds = $teacher->circles()->pluck('id');
        $students = Student::whereIn('circle_id', $circleIds)->get();
        $studentIds = $students->pluck('id');

        $studentsStats = [];

        $thirtyDaysAgo = now()->subDays(30)->format('Y-m-d');
        $todayStr = now()->format('Y-m-d');

        $startStr = $this->startDate ?: now()->subYears(1)->format('Y-m-d');
        $endStr = $this->endDate ?: now()->format('Y-m-d');

        $earliest = min($startStr, $thirtyDaysAgo);

        $planDays = StudentPlanDay::with('plan')
            ->whereHas('plan', function ($q) use ($studentIds) {
                $q->whereIn('student_id', $studentIds);
            })
            ->where('date', '>=', $earliest)
            ->where(function ($query) use ($todayStr) {
                // Future days count only once recited early; a future «لم يسمع» changes nothing.
                $query->where('date', '<=', $todayStr)
                    ->orWhere('hifz_achievement', '>=', 1)
                    ->orWhere('review_achievement', '>=', 1);
            })
            ->get();

        $students30Days = [];

        foreach ($students as $student) {
            $sId = $student->id;

            $studentsStats[$sId] = [
                'student' => $student,
                'excellent' => 0,
                'good' => 0,
                'acceptable' => 0,
                'unrecited' => 0,
            ];

            $students30Days[$sId] = [
                'student' => $student,
                'excellent' => 0,
                'acceptable' => 0,
                'unrecited' => 0,
            ];
        }

        foreach ($planDays as $day) {
            $sId = $day->plan->student_id;
            if (!isset($studentsStats[$sId]))
                continue;

            $segments = [];
            if ($day->plan->plan_type === 'hifz' || $day->plan->plan_type === 'hifz_review') {
                $segments[] = $day->hifz_achievement;
            }
            if ($day->plan->plan_type === 'review' || $day->plan->plan_type === 'hifz_review') {
                $segments[] = $day->review_achievement;
            }

            $dateStr = \Carbon\Carbon::parse($day->date)->format('Y-m-d');

            // إذا كان اليوم في المستقبل ولكن تم تسميعه بالفعل (تسميع مبكر)، عامله كأنه سُمّع اليوم
            $effectiveDateStr = $dateStr > $todayStr ? $todayStr : $dateStr;

            $isInSelectedRange = ($effectiveDateStr >= $startStr && $effectiveDateStr <= $endStr);
            $isIn30Days = ($effectiveDateStr >= $thirtyDaysAgo && $effectiveDateStr <= $todayStr);

            foreach ($segments as $val) {
                if ($isInSelectedRange) {
                    if ($val == 3)
                        $studentsStats[$sId]['excellent']++;
                    elseif ($val == 2)
                        $studentsStats[$sId]['good']++;
                    elseif ($val == 1)
                        $studentsStats[$sId]['acceptable']++;
                    elseif ($val === null || $val === "" || $val === 0)
                        $studentsStats[$sId]['unrecited']++;
                }

                if ($isIn30Days) {
                    if ($val == 3)
                        $students30Days[$sId]['excellent']++;
                    elseif ($val == 1)
                        $students30Days[$sId]['acceptable']++;
                    elseif ($val === null || $val === "" || $val === 0)
                        $students30Days[$sId]['unrecited']++;
                }
            }
        }

        $studentsStats = collect($studentsStats)->sortBy(function ($stat) {
            return $stat[$this->sortField];
        }, SORT_REGULAR, $this->sortOrder === 'desc')->values();

        $topUnrecited = collect($students30Days)->filter(fn($s) => $s['unrecited'] > 0)->sortByDesc('unrecited')->take(3)->values();
        $topAcceptable = collect($students30Days)->filter(fn($s) => $s['acceptable'] > 0)->sortByDesc('acceptable')->take(3)->values();
        $topExcellent = collect($students30Days)->filter(fn($s) => $s['excellent'] > 0)->sortByDesc('excellent')->take(3)->values();

        return [
            'studentsStats' => $studentsStats,
            'topUnrecited' => $topUnrecited,
            'topAcceptable' => $topAcceptable,
            'topExcellent' => $topExcellent,
        ];
    }
};
?>

<div class="space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <flux:heading size="xl" level="1">{{ __('الانضباط القرآني') }}</flux:heading>
            <flux:subheading>{{ __('متابعة إنجازات الطلاب في الحفظ والمراجعة') }}</flux:subheading>
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">

        <!-- 1. لم يسمع -->
        <flux:card class="border-t-4 border-t-red-500 overflow-hidden relative">
            <div class="absolute top-0 right-0 p-4 opacity-10">
                <flux:icon icon="x-circle" class="w-16 h-16 text-red-500" />
            </div>
            <div class="relative z-10 space-y-4">
                <div class="flex items-center gap-2 text-red-600 dark:text-red-400">
                    <flux:icon icon="x-circle" class="w-5 h-5 flex-shrink-0" />
                    <h3 class="font-bold text-sm">{{ __('الأكثر انقطاعاً (لم يسمّع)') }}</h3>
                </div>
                <div class="text-xs text-zinc-500">{{ __('خلال آخر 30 يوماً') }}</div>

                <div class="space-y-3 mt-4">
                    @forelse($topUnrecited as $stat)
                        <div class="flex items-center justify-between">
                            <span
                                class="text-sm font-medium text-zinc-700 dark:text-zinc-300 truncate pr-2">{{ $stat['student']->name }}</span>
                            <flux:badge color="red" size="sm" inset="top bottom">{{ $stat['unrecited'] }} {{ __('مرة') }}
                            </flux:badge>
                        </div>
                    @empty
                        <div class="text-xs text-zinc-400 text-center py-2">{{ __('لا يوجد بيانات') }}</div>
                    @endforelse
                </div>
            </div>
        </flux:card>

        <!-- 2. مقبول -->
        <flux:card class="border-t-4 border-t-amber-500 overflow-hidden relative">
            <div class="absolute top-0 right-0 p-4 opacity-10">
                <flux:icon icon="exclamation-circle" class="w-16 h-16 text-amber-500" />
            </div>
            <div class="relative z-10 space-y-4">
                <div class="flex items-center gap-2 text-amber-600 dark:text-amber-400">
                    <flux:icon icon="exclamation-triangle" class="w-5 h-5 flex-shrink-0" />
                    <h3 class="font-bold text-sm">{{ __('يحتاجون لدعم (تقييم مقبول)') }}</h3>
                </div>
                <div class="text-xs text-zinc-500">{{ __('خلال آخر 30 يوماً') }}</div>

                <div class="space-y-3 mt-4">
                    @forelse($topAcceptable as $stat)
                        <div class="flex items-center justify-between">
                            <span
                                class="text-sm font-medium text-zinc-700 dark:text-zinc-300 truncate pr-2">{{ $stat['student']->name }}</span>
                            <flux:badge color="amber" size="sm" inset="top bottom">{{ $stat['acceptable'] }} {{ __('مرة') }}
                            </flux:badge>
                        </div>
                    @empty
                        <div class="text-xs text-zinc-400 text-center py-2">{{ __('لا يوجد بيانات') }}</div>
                    @endforelse
                </div>
            </div>
        </flux:card>

        <!-- 3. ممتاز -->
        <flux:card class="border-t-4 border-t-green-500 overflow-hidden relative">
            <div class="absolute top-0 right-0 p-4 opacity-10">
                <flux:icon icon="star" class="w-16 h-16 text-green-500" />
            </div>
            <div class="relative z-10 space-y-4">
                <div class="flex items-center gap-2 text-green-600 dark:text-green-400">
                    <flux:icon icon="star" class="w-5 h-5 flex-shrink-0" />
                    <h3 class="font-bold text-sm">{{ __('الأكثر تميزاً (تقييم ممتاز)') }}</h3>
                </div>
                <div class="text-xs text-zinc-500">{{ __('خلال آخر 30 يوماً') }}</div>

                <div class="space-y-3 mt-4">
                    @forelse($topExcellent as $stat)
                        <div class="flex items-center justify-between">
                            <span
                                class="text-sm font-medium text-zinc-700 dark:text-zinc-300 truncate pr-2">{{ $stat['student']->name }}</span>
                            <flux:badge color="green" size="sm" inset="top bottom">{{ $stat['excellent'] }} {{ __('مرة') }}
                            </flux:badge>
                        </div>
                    @empty
                        <div class="text-xs text-zinc-400 text-center py-2">{{ __('لا يوجد بيانات') }}</div>
                    @endforelse
                </div>
            </div>
        </flux:card>
    </div>

    <!-- Main Table -->
    <flux:card class="p-0 overflow-hidden">
        <div
            class="p-4 border-b border-zinc-100 dark:border-zinc-800 flex flex-col md:flex-row items-center justify-between gap-4">
            <div class="flex items-center gap-2">
                <div class="w-36">
                    <livewire:shared.hijri-datepicker wire:model.live="startDate" label=""
                        placeholder="{{ __('من') }}" />
                </div>
                <span class="text-zinc-400">-</span>
                <div class="w-36">
                    <livewire:shared.hijri-datepicker wire:model.live="endDate" label=""
                        placeholder="{{ __('إلى') }}" />
                </div>
            </div>

            <div class="flex items-center gap-2">
                <flux:button size="sm" wire:click="setDateRange('week')" variant="ghost">{{ __('آخر أسبوع') }}
                </flux:button>
                <flux:button size="sm" wire:click="setDateRange('month')" variant="ghost">{{ __('آخر شهر') }}
                </flux:button>
                <flux:button size="sm" wire:click="setDateRange('all')" variant="ghost">{{ __('الكل') }}</flux:button>
            </div>
        </div>

        <div class="overflow-x-auto">
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('اسم الطالب') }}</flux:table.column>
                    <flux:table.column class="cursor-pointer hover:bg-zinc-50 dark:hover:bg-zinc-800 "
                        wire:click="sortBy('unrecited')">
                        <div class="flex items-center gap-1">
                            <flux:icon icon="x-circle" class="w-4 h-4 text-red-500" />
                            <span>{{ __('لم يسمع') }}</span>
                            @if($sortField === 'unrecited')
                                <flux:icon icon="{{ $sortOrder === 'asc' ? 'chevron-up' : 'chevron-down' }}"
                                    class="w-3 h-3 text-red-500" />
                            @endif
                        </div>
                    </flux:table.column>
                    <flux:table.column class="cursor-pointer hover:bg-zinc-50 dark:hover:bg-zinc-800 "
                        wire:click="sortBy('acceptable')">
                        <div class="flex items-center gap-1">
                            <flux:icon icon="exclamation-circle" class="w-4 h-4 text-amber-500" />
                            <span>{{ __('مقبول') }}</span>
                            @if($sortField === 'acceptable')
                                <flux:icon icon="{{ $sortOrder === 'asc' ? 'chevron-up' : 'chevron-down' }}"
                                    class="w-3 h-3 text-amber-500" />
                            @endif
                        </div>
                    </flux:table.column>
                    <flux:table.column class="cursor-pointer hover:bg-zinc-50 dark:hover:bg-zinc-800 "
                        wire:click="sortBy('good')">
                        <div class="flex items-center gap-1">
                            <flux:icon icon="check-circle" class="w-4 h-4 text-blue-500" />
                            <span>{{ __('جيد') }}</span>
                            @if($sortField === 'good')
                                <flux:icon icon="{{ $sortOrder === 'asc' ? 'chevron-up' : 'chevron-down' }}"
                                    class="w-3 h-3 text-blue-500" />
                            @endif
                        </div>
                    </flux:table.column>
                    <flux:table.column class="cursor-pointer hover:bg-zinc-50 dark:hover:bg-zinc-800 "
                        wire:click="sortBy('excellent')">
                        <div class="flex items-center gap-1">
                            <flux:icon icon="star" class="w-4 h-4 text-green-500" />
                            <span>{{ __('ممتاز') }}</span>
                            @if($sortField === 'excellent')
                                <flux:icon icon="{{ $sortOrder === 'asc' ? 'chevron-up' : 'chevron-down' }}"
                                    class="w-3 h-3 text-green-500" />
                            @endif
                        </div>
                    </flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach($studentsStats as $stat)
                        <flux:table.row>
                            <flux:table.cell class="font-bold">{{ $stat['student']->name }}</flux:table.cell>

                            <flux:table.cell class="first:ps-3" >
                                <span
                                    class="font-semibold {{ $stat['unrecited'] > 0 ? 'text-red-600 dark:text-red-400' : 'text-zinc-400' }}">
                                    {{ $stat['unrecited'] }}
                                </span>
                            </flux:table.cell>

                            <flux:table.cell class="first:ps-3" >
                                <span
                                    class="font-semibold {{ $stat['acceptable'] > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-zinc-400' }}">
                                    {{ $stat['acceptable'] }}
                                </span>
                            </flux:table.cell>

                            <flux:table.cell class="first:ps-3" >
                                <span
                                    class="font-semibold {{ $stat['good'] > 0 ? 'text-blue-600 dark:text-blue-400' : 'text-zinc-400' }}">
                                    {{ $stat['good'] }}
                                </span>
                            </flux:table.cell>

                            <flux:table.cell class="first:ps-3" >
                                <span
                                    class="font-semibold {{ $stat['excellent'] > 0 ? 'text-green-600 dark:text-green-400' : 'text-zinc-400' }}">
                                    {{ $stat['excellent'] }}
                                </span>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </div>
    </flux:card>
</div>