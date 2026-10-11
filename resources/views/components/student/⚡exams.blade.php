<?php

use App\Models\StudentExam;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public function with(): array
    {
        $student = Auth::guard('student')->user();

        // A nomination stays between the teacher and the manager until a batch gives it a day.
        $exams = StudentExam::with('examLevel')
            ->where('student_id', $student->id)
            ->visibleToStudent()
            ->orderByDesc('date_time')
            ->paginate(15);

        $upcomingCount = StudentExam::where('student_id', $student->id)
            ->where('status', 'pending')
            ->where('date_time', '>=', now())
            ->count();

        $passedCount = StudentExam::where('student_id', $student->id)->where('status', 'passed')->count();

        return [
            'exams' => $exams,
            'upcomingCount' => $upcomingCount,
            'passedCount' => $passedCount,
        ];
    }
};
?>

<div class="space-y-6" dir="rtl">
    <div>
        <flux:heading size="xl" class="font-bold text-zinc-900 dark:text-white">
            {{ __('الاختبارات') }}
        </flux:heading>
        <flux:subheading class="text-zinc-500 dark:text-zinc-400 mt-1">
            {{ __('اختباراتك القادمة والسابقة ونتائجها.') }}
        </flux:subheading>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <flux:card>
            <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('اختبارات قادمة') }}</div>
            <div class="text-2xl font-extrabold text-zinc-900 dark:text-white mt-1">{{ $upcomingCount }}</div>
        </flux:card>
        <flux:card>
            <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('اختبارات ناجحة') }}</div>
            <div class="text-2xl font-extrabold text-emerald-600 mt-1">{{ $passedCount }}</div>
        </flux:card>
    </div>

    <flux:card class="p-0 overflow-hidden">
        <div class="overflow-x-auto">
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('التاريخ') }}</flux:table.column>
                    <flux:table.column>{{ __('المستوى') }}</flux:table.column>
                    <flux:table.column>{{ __('المكان') }}</flux:table.column>
                    <flux:table.column>{{ __('الحالة') }}</flux:table.column>
                    <flux:table.column>{{ __('النتيجة') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @forelse($exams as $exam)
                        <flux:table.row>
                            <flux:table.cell class="font-medium whitespace-nowrap">
                                <x-hijri-date :date="$exam->date_time" style="withTime" />
                            </flux:table.cell>
                            <flux:table.cell>{{ $exam->examLevel?->name ?? '-' }}</flux:table.cell>
                            <flux:table.cell>{{ $exam->location ?? '-' }}</flux:table.cell>
                            <flux:table.cell>
                                @php
                                    $statusDetails = match($exam->status) {
                                        'pending' => ['color' => 'amber', 'label' => 'قادم'],
                                        'passed' => ['color' => 'emerald', 'label' => 'ناجح'],
                                        'failed' => ['color' => 'red', 'label' => 'راسب'],
                                        'absent' => ['color' => 'zinc', 'label' => 'غائب'],
                                        'cancelled' => ['color' => 'zinc', 'label' => 'ملغى'],
                                        default => ['color' => 'zinc', 'label' => $exam->status],
                                    };
                                @endphp
                                <flux:badge :color="$statusDetails['color']" size="sm">{{ __($statusDetails['label']) }}</flux:badge>
                            </flux:table.cell>
                            <flux:table.cell>
                                @if($exam->score_percentage !== null)
                                    <span class="font-bold">{{ $exam->score_percentage }}%</span>
                                @else
                                    <span class="text-zinc-300">-</span>
                                @endif
                            </flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="5" class="text-center text-zinc-500 py-6">
                                {{ __('لا توجد اختبارات مسجّلة بعد.') }}
                            </flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </div>

        @if($exams->hasPages())
            <div class="p-4 border-t border-zinc-100 dark:border-zinc-800">
                {{ $exams->links() }}
            </div>
        @endif
    </flux:card>
</div>
