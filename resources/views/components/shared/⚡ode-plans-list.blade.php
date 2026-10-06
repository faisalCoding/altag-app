<?php

use Livewire\Component;
use Livewire\WithPagination;
use App\Models\StudentOdePlan;
use App\Models\Circle;
use Illuminate\Support\Facades\Auth;
use Flux\Flux;

new class extends Component {
    use WithPagination;

    public $role = 'teacher'; // 'teacher' or 'supervisor'
    public $search = '';

    public function mount($role = 'teacher')
    {
        $this->role = $role;
    }

    public function updatedSearch()
    {
        $this->resetPage();
    }

    public function deletePlan($id)
    {
        $circleIds = $this->getCircleIds();

        $plan = StudentOdePlan::whereHas('student', function ($q) use ($circleIds) {
            $q->whereIn('circle_id', $circleIds);
        })->findOrFail($id);

        $plan->delete();

        Flux::toast('تم حذف خطة المنظومة بنجاح', variant: 'success');
    }

    private function getCircleIds()
    {
        if ($this->role === 'supervisor') {
            $supervisor = Auth::guard('supervisor')->user();
            if (!$supervisor) return [];
            return Circle::whereIn('stage_id', $supervisor->stages()->pluck('stages.id'))->pluck('id')->toArray();
        } else {
            $teacher = Auth::guard('teacher')->user();
            if (!$teacher) return [];
            // Only the circles whose stage still memorises the odes.
            return $teacher->odeCircles()->pluck('circles.id')->toArray();
        }
    }

    public function with()
    {
        $circleIds = $this->getCircleIds();

        $studentIds = \App\Models\Student::whereIn('circle_id', $circleIds)
            ->when($this->search, function ($query) {
                $query->where('name', 'like', '%' . $this->search . '%');
            })
            ->pluck('id');

        $plans = StudentOdePlan::with(['student.circle', 'path.ode', 'path.days'])
            ->whereIn('student_id', $studentIds)
            ->latest()
            ->paginate(20);

        return [
            'plans' => $plans,
        ];
    }
};
?>

<div class="space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <flux:heading size="xl" level="1">{{ __('خطط المنظومات المنشأة') }}</flux:heading>
            <flux:subheading>{{ __('عرض وإدارة خطط حفظ ومراجعة المنظومات العلمية والشعرية للطلاب') }}</flux:subheading>
        </div>
        @if($role === 'supervisor')
            <flux:button variant="primary" icon="plus" href="{{ route('supervisor.odes.paths') }}">
                {{ __('إدارة المسارات والتسكين') }}
            </flux:button>
        @endif
    </div>

    <flux:card class="p-0 overflow-hidden">
        <div class="p-4 border-b border-zinc-100 dark:border-zinc-800 flex items-center justify-between gap-4">
            <flux:input wire:model.live="search" icon="magnifying-glass" placeholder="{{ __('بحث باسم الطالب...') }}"
                class="max-w-xs" />
        </div>

        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('الطالب') }}</flux:table.column>
                <flux:table.column>{{ __('المنظومة') }}</flux:table.column>
                <flux:table.column>{{ __('تاريخ البدء') }}</flux:table.column>
                <flux:table.column>{{ __('عدد الأيام') }}</flux:table.column>
                <flux:table.column>{{ __('الحالة') }}</flux:table.column>
                <flux:table.column>{{ __('أنشئت بواسطة') }}</flux:table.column>
                <flux:table.column class="w-10"></flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach($plans as $plan)
                    <flux:table.row>
                        <flux:table.cell class="font-medium">
                            <div class="flex flex-col">
                                <span class="text-zinc-900 dark:text-white font-semibold">{{ $plan->student->name }}</span>
                                <span class="text-xs text-zinc-500">{{ $plan->student->circle->name ?? 'بلا حلقة' }}</span>
                            </div>
                        </flux:table.cell>
                        <flux:table.cell class="first:ps-3" >{{ $plan->path->ode->name ?? '—' }}</flux:table.cell>
                        <flux:table.cell class="first:ps-3" ><x-hijri-date :date="$plan->start_date" /></flux:table.cell>
                        <flux:table.cell class="first:ps-3" >{{ $plan->path->days->count() ?? 0 }}</flux:table.cell>
                        <flux:table.cell class="first:ps-3" >
                            @if($plan->status === 'active')
                                <flux:badge color="green" size="sm">{{ __('نشطة') }}</flux:badge>
                            @else
                                <flux:badge color="zinc" size="sm">{{ __('مكتملة') }}</flux:badge>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell class="first:ps-3" >
                            @if($plan->created_by_role === 'supervisor')
                                <flux:badge color="blue" size="sm">{{ __('مشرف') }}</flux:badge>
                            @else
                                <flux:badge color="indigo" size="sm">{{ __('معلم') }}</flux:badge>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell class="first:ps-3" >
                            <flux:dropdown>
                                <flux:button variant="ghost" size="xs" icon="ellipsis-horizontal" />
                                <flux:menu>
                                    <flux:menu.item wire:click="deletePlan({{ $plan->id }})" variant="danger" icon="trash"
                                        wire:confirm="{{ __('هل أنت متأكد من حذف هذه الخطة بالكامل؟ سيتم مسح جدول التوزيع والتقييمات الخاصة بها.') }}">
                                        {{ __('حذف') }}
                                    </flux:menu.item>
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
