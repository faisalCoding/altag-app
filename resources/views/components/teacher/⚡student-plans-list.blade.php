<?php

use Livewire\Component;
use App\Models\StudentPlan;
use Illuminate\Support\Facades\Auth;

new class extends Component
{
    public $search = '';

    public function deletePlan($id)
    {
        $plan = StudentPlan::where('teacher_id', Auth::guard('teacher')->id())->findOrFail($id);
        $plan->delete();
        session()->flash('success', 'تم حذف الخطة بنجاح');
    }

    public function with()
    {
        $plans = StudentPlan::with('student')
            ->where('teacher_id', Auth::guard('teacher')->id())
            ->when($this->search, function($query) {
                $query->whereHas('student', function($q) {
                    $q->where('name', 'like', '%' . $this->search . '%');
                });
            })
            ->latest()
            ->paginate(10);

        return [
            'plans' => $plans,
        ];
    }
};
?>

<div class="space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <flux:heading size="xl" level="1">{{ __('الخطط الدراسية المنشأة') }}</flux:heading>
            <flux:subheading>{{ __('إدارة وعرض خطط الحفظ والمراجعة لطلابك') }}</flux:subheading>
        </div>
        <flux:button variant="primary" icon="plus" href="{{ route('teacher.plan-creator') }}">
            {{ __('إنشاء خطة جديدة') }}
        </flux:button>
    </div>

    <flux:card class="p-0 overflow-hidden">
        <div class="p-4 border-b border-zinc-100 dark:border-zinc-800 flex items-center justify-between gap-4">
            <flux:input wire:model.live="search" icon="magnifying-glass" placeholder="{{ __('بحث باسم الطالب...') }}" class="max-w-xs" />
        </div>

        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('الطالب') }}</flux:table.column>
                <flux:table.column>{{ __('نوع الخطة') }}</flux:table.column>
                <flux:table.column>{{ __('تاريخ البدء') }}</flux:table.column>
                <flux:table.column>{{ __('عدد الأيام') }}</flux:table.column>
                <flux:table.column>{{ __('الحالة') }}</flux:table.column>
                <flux:table.column class="w-10"></flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach($plans as $plan)
                    <flux:table.row>
                        <flux:table.cell class="font-medium">{{ $plan->student->name }}</flux:table.cell>
                        <flux:table.cell>
                            @if($plan->plan_type === 'review')
                                <flux:badge color="indigo" size="sm">{{ __('مراجعة') }}</flux:badge>
                            @elseif($plan->plan_type === 'hifz_review')
                                <flux:badge color="teal" size="sm">{{ __('حفظ ومراجعة') }}</flux:badge>
                            @else
                                <flux:badge color="green" size="sm">{{ __('حفظ') }}</flux:badge>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>{{ $plan->start_date->format('Y-m-d') }}</flux:table.cell>
                        <flux:table.cell>{{ $plan->days_count }}</flux:table.cell>
                        <flux:table.cell>
                            <flux:badge size="sm">{{ $plan->status }}</flux:badge>
                        </flux:table.cell>
                        <flux:table.cell>
                            <flux:dropdown>
                                <flux:button variant="ghost" size="xs" icon="ellipsis-horizontal" />
                                <flux:menu>
                                    <flux:menu.item href="{{ route('teacher.print-plan', $plan->id) }}" target="_blank" icon="printer">{{ __('عرض وطباعة') }}</flux:menu.item>
                                    <flux:menu.item wire:click="deletePlan({{ $plan->id }})" variant="danger" icon="trash">{{ __('حذف') }}</flux:menu.item>
                                </flux:menu>
                            </flux:dropdown>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>

        <div class="p-4 border-t border-zinc-100 dark:border-zinc-800">
            {{ $plans->links() }}
        </div>
    </flux:card>
</div>
