<?php

use Livewire\Component;
use Livewire\WithPagination;
use App\Models\StudentPlan;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;

new class extends Component {
    use WithPagination;

    public $search = '';

    public $selectedPlans = [];
    public $showBulkDeleteModal = false;
    public $bulkDeleteConfirmation = '';

    public function updatedSearch()
    {
        $this->resetPage();
        $this->selectedPlans = [];
    }

    public function updatedPaginators()
    {
        $this->selectedPlans = [];
    }

    public $showStudentModal = false;
    public $modalAction = ''; // 'change' or 'duplicate'
    #[Locked]
    public $selectedPlanId = null;
    public $selectedNewStudentId = null;
    public $studentsList = [];
    public $hasAchievements = false;
    public $keepAchievements = null;

    public function openStudentModal($planId, $action)
    {
        $this->selectedPlanId = $planId;
        $this->modalAction = $action;
        $this->selectedNewStudentId = null;
        $this->hasAchievements = false;
        $this->keepAchievements = null;

        $teacher = Auth::guard('teacher')->user();
        $circleIds = $teacher->circles()->pluck('id');
        $this->studentsList = \App\Models\Student::whereIn('circle_id', $circleIds)->get();

        $plan = StudentPlan::with('days')->whereHas('student', function ($q) use ($circleIds) {
            $q->whereIn('circle_id', $circleIds);
        })->findOrFail($planId);

        if ($action === 'change') {
            $this->hasAchievements = collect($plan->days)->contains(function ($day) {
                return !is_null($day->hifz_achievement) || !is_null($day->review_achievement);
            });
        }

        $this->showStudentModal = true;
    }

    public function executeStudentAction()
    {
        $rules = [
            // One of the teacher's own students: the plan moves or copies to them.
            'selectedNewStudentId' => ['required', \Illuminate\Validation\Rule::in(
                \App\Models\Student::whereIn('circle_id', Auth::guard('teacher')->user()->circles()->pluck('circles.id'))->pluck('id')->all()
            )],
        ];
        $messages = [
            'selectedNewStudentId.required' => 'يرجى اختيار طالب',
        ];

        if ($this->modalAction === 'change' && $this->hasAchievements) {
            $rules['keepAchievements'] = 'required|in:yes,no';
            $messages['keepAchievements.required'] = 'يرجى تحديد خيار التعامل مع الإنجازات السابقة';
        }

        $this->validate($rules, $messages);

        $teacher = Auth::guard('teacher')->user();
        $circleIds = $teacher->circles()->pluck('id');
        $plan = StudentPlan::with('days')->whereHas('student', function ($q) use ($circleIds) {
            $q->whereIn('circle_id', $circleIds);
        })->findOrFail($this->selectedPlanId);

        if ($this->modalAction === 'change') {
            $plan->update(['student_id' => $this->selectedNewStudentId]);

            if ($this->hasAchievements && $this->keepAchievements === 'no') {
                foreach ($plan->days as $day) {
                    $day->update([
                        'hifz_achievement' => null,
                        'review_achievement' => null,
                        'hifz_graded_at' => null,
                        'review_graded_at' => null,
                        'hifz_recited_from_ayah_id' => null,
                        'hifz_recited_to_ayah_id' => null,
                        'hifz_recorded_by' => null,
                        'review_recited_from_ayah_id' => null,
                        'review_recited_to_ayah_id' => null,
                        'review_recorded_by' => null,
                    ]);
                }
                session()->flash('success', 'تم نقل الخطة للطالب ومسح الإنجازات السابقة بنجاح');
            } else {
                session()->flash('success', 'تم نقل الخطة للطالب الجديد بنجاح');
            }
        } elseif ($this->modalAction === 'duplicate') {
            $newPlan = $plan->replicate();
            $newPlan->student_id = $this->selectedNewStudentId;
            $newPlan->teacher_id = $teacher->id;
            $newPlan->save();

            foreach ($plan->days as $day) {
                $newDay = $day->replicate();
                $newDay->student_plan_id = $newPlan->id;
                $newDay->hifz_achievement = null;
                $newDay->review_achievement = null;
                $newDay->hifz_graded_at = null;
                $newDay->review_graded_at = null;
                $newDay->hifz_recited_from_ayah_id = null;
                $newDay->hifz_recited_to_ayah_id = null;
                $newDay->hifz_recorded_by = null;
                $newDay->review_recited_from_ayah_id = null;
                $newDay->review_recited_to_ayah_id = null;
                $newDay->review_recorded_by = null;
                $newDay->save();
            }
            session()->flash('success', 'تم نسخ الخطة للطالب الجديد بنجاح');
        }

        $this->showStudentModal = false;
        $this->selectedPlanId = null;
        $this->selectedNewStudentId = null;
    }

    public function approvePlan($id)
    {
        $teacher = Auth::guard('teacher')->user();
        $circleIds = $teacher->circles()->pluck('id');
        $plan = StudentPlan::whereHas('student', function ($q) use ($circleIds) {
            $q->whereIn('circle_id', $circleIds);
        })->findOrFail($id);
        $plan->update(['is_approved' => true]);

        \App\Services\NotificationService::notify(
            'student',
            $plan->student_id,
            'plan_approved',
            'تم اعتماد خطتك',
            'قام معلمك باعتماد خطتك الدراسية',
            route('student.plan'),
        );

        session()->flash('success', 'تم اعتماد الخطة بنجاح');
    }

    public function deletePlan($id)
    {
        $teacher = Auth::guard('teacher')->user();
        $circleIds = $teacher->circles()->pluck('id');
        $plan = StudentPlan::whereHas('student', function ($q) use ($circleIds) {
            $q->whereIn('circle_id', $circleIds);
        })->findOrFail($id);
        $plan->delete();
        session()->flash('success', 'تم حذف الخطة بنجاح');
    }

    public function togglePlanStatus($id)
    {
        $teacher = Auth::guard('teacher')->user();
        $circleIds = $teacher->circles()->pluck('id');
        $plan = StudentPlan::whereHas('student', function ($q) use ($circleIds) {
            $q->whereIn('circle_id', $circleIds);
        })->findOrFail($id);

        $plan->update(['status' => $plan->status === 'active' ? 'inactive' : 'active']);

        session()->flash('success', $plan->status === 'active' ? 'تم تفعيل الخطة بنجاح' : 'تم إلغاء تفعيل الخطة ولن تظهر في صفحة التسميع');
    }

    protected function selectedAuthorizedPlansQuery()
    {
        $teacher = Auth::guard('teacher')->user();
        $circleIds = $teacher->circles()->pluck('id');

        return StudentPlan::whereHas('student', function ($q) use ($circleIds) {
            $q->whereIn('circle_id', $circleIds);
        })->whereIn('id', $this->selectedPlans);
    }

    public function bulkActivate()
    {
        if (empty($this->selectedPlans)) {
            return;
        }

        $count = $this->selectedAuthorizedPlansQuery()->update(['status' => 'active']);
        $this->selectedPlans = [];
        session()->flash('success', "تم تفعيل {$count} من الخطط بنجاح");
    }

    public function bulkDeactivate()
    {
        if (empty($this->selectedPlans)) {
            return;
        }

        $count = $this->selectedAuthorizedPlansQuery()->update(['status' => 'inactive']);
        $this->selectedPlans = [];
        session()->flash('success', "تم إلغاء تفعيل {$count} من الخطط ولن تظهر في صفحة التسميع");
    }

    public function openBulkDeleteModal()
    {
        if (empty($this->selectedPlans)) {
            return;
        }

        $this->bulkDeleteConfirmation = '';
        $this->resetErrorBag('bulkDeleteConfirmation');
        $this->showBulkDeleteModal = true;
    }

    public function bulkDelete()
    {
        $this->validate(
            ['bulkDeleteConfirmation' => 'required|in:حذف'],
            [
                'bulkDeleteConfirmation.required' => 'اكتب كلمة "حذف" لتأكيد العملية',
                'bulkDeleteConfirmation.in' => 'يجب كتابة كلمة "حذف" بالضبط لتأكيد العملية',
            ]
        );

        $count = $this->selectedAuthorizedPlansQuery()->get()->each->delete()->count();

        $this->selectedPlans = [];
        $this->showBulkDeleteModal = false;
        $this->bulkDeleteConfirmation = '';
        session()->flash('success', "تم حذف {$count} من الخطط نهائياً");
    }

    public function with()
    {
        $teacher = Auth::guard('teacher')->user();
        $circleIds = $teacher->circles()->pluck('id');

        $studentIds = \App\Models\Student::whereIn('circle_id', $circleIds)
            ->when($this->search, function ($query) {
                $query->where('name', 'like', '%' . $this->search . '%');
            })
            ->pluck('id');

        $plans = StudentPlan::with('student')
            ->whereIn('student_id', $studentIds)
            // Move unapproved to top, then active ones
            ->orderBy('is_approved', 'asc')
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
            <flux:heading size="xl" level="1">{{ __('الخطط الدراسية المنشأة') }}</flux:heading>
            <flux:subheading>{{ __('إدارة وعرض خطط الحفظ والمراجعة لطلابك') }}</flux:subheading>
        </div>
        <flux:button variant="primary" icon="plus" href="{{ route('teacher.plan-creator') }}">
            {{ __('إنشاء خطة جديدة') }}
        </flux:button>
    </div>

    <flux:card class="p-0 overflow-hidden">
        <div class="p-4 border-b border-zinc-100 dark:border-zinc-800 flex items-center justify-between gap-4">
            <flux:input wire:model.live="search" icon="magnifying-glass" placeholder="{{ __('بحث باسم الطالب...') }}"
                class="max-w-xs" />
        </div>

        @if(count($selectedPlans) > 0)
            <div class="p-3 px-4 border-b border-indigo-100 dark:border-indigo-900/50 bg-indigo-50/70 dark:bg-indigo-900/20 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                <div class="flex items-center gap-2 text-sm font-medium text-indigo-700 dark:text-indigo-300">
                    <flux:icon icon="check-circle" class="size-5" />
                    {{ __('تم تحديد') }} {{ count($selectedPlans) }} {{ __('من الخطط') }}
                </div>
                <div class="flex items-center gap-2">
                    <flux:button size="sm" icon="play-circle" wire:click="bulkActivate"
                        class="text-emerald-700 dark:text-emerald-400">
                        {{ __('تفعيل') }}
                    </flux:button>
                    <flux:button size="sm" icon="pause-circle" wire:click="bulkDeactivate">
                        {{ __('إلغاء التفعيل') }}
                    </flux:button>
                    <flux:button size="sm" variant="danger" icon="trash" wire:click="openBulkDeleteModal">
                        {{ __('حذف') }}
                    </flux:button>
                </div>
            </div>
        @endif

        <flux:checkbox.group wire:model.live="selectedPlans">
        <flux:table>
            <flux:table.columns>
                <flux:table.column class="w-10">
                    <flux:checkbox.all />
                </flux:table.column>
                <flux:table.column>{{ __('الطالب') }}</flux:table.column>
                <flux:table.column>{{ __('نوع الخطة') }}</flux:table.column>
                <flux:table.column>{{ __('تاريخ البدء') }}</flux:table.column>
                <flux:table.column>{{ __('عدد الأيام') }}</flux:table.column>
                <flux:table.column>{{ __('الحالة') }}</flux:table.column>
                <flux:table.column class="w-10"></flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach($plans as $plan)
                    <flux:table.row wire:key="plan-row-{{ $plan->id }}" class="{{ !$plan->is_approved ? 'bg-amber-50/50 dark:bg-amber-900/10' : '' }}">
                        <flux:table.cell>
                            <flux:checkbox value="{{ $plan->id }}" />
                        </flux:table.cell>
                        <flux:table.cell class="font-medium">{{ $plan->student->name }}</flux:table.cell>
                        <flux:table.cell class="first:ps-3" >
                            @if($plan->plan_type === 'review')
                                <flux:badge color="indigo" size="sm">{{ __('مراجعة') }}</flux:badge>
                            @elseif($plan->plan_type === 'hifz_review')
                                <flux:badge color="teal" size="sm">{{ __('حفظ ومراجعة') }}</flux:badge>
                            @else
                                <flux:badge color="green" size="sm">{{ __('حفظ') }}</flux:badge>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell class="first:ps-3" ><x-hijri-date :date="$plan->start_date" /></flux:table.cell>
                        <flux:table.cell class="first:ps-3" >{{ $plan->days_count }}</flux:table.cell>
                        <flux:table.cell class="first:ps-3" >
                            @if(!$plan->is_approved)
                                <flux:badge color="amber" size="sm" icon="clock">{{ __('قيد الاعتماد') }}</flux:badge>
                            @elseif($plan->status === 'active')
                                <flux:badge color="green" size="sm" icon="check-circle">{{ __('فعالة') }}</flux:badge>
                            @else
                                <flux:badge color="zinc" size="sm" icon="pause-circle">{{ __('غير فعالة') }}</flux:badge>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell class="first:ps-3" >
                            <flux:dropdown>
                                <flux:button variant="ghost" size="xs" icon="ellipsis-horizontal" />
                                <flux:menu>
                                    @if(!$plan->is_approved)
                                        <flux:menu.item wire:click="approvePlan({{ $plan->id }})" icon="check-circle"
                                            class="text-emerald-600 dark:text-emerald-400">{{ __('اعتماد الخطة') }}
                                        </flux:menu.item>
                                    @endif
                                    <flux:menu.item href="{{ route('teacher.plan-creator', ['edit' => $plan->id]) }}"
                                        icon="pencil">{{ __('تعديل') }}</flux:menu.item>
                                    <flux:menu.item href="{{ route('teacher.print-plan', $plan->id) }}" target="_blank"
                                        icon="printer">{{ __('عرض وطباعة') }}</flux:menu.item>
                                    <flux:menu.item href="{{ route('teacher.download-plan-pdf', $plan->id) }}"
                                        icon="document-arrow-down">{{ __('تحميل كـ PDF') }}</flux:menu.item>

                                    <flux:menu.separator />

                                    @if($plan->status === 'active')
                                        <flux:menu.item wire:click="togglePlanStatus({{ $plan->id }})" icon="pause-circle"
                                            wire:confirm="{{ __('هل أنت متأكد من إلغاء تفعيل هذه الخطة؟ لن تظهر للطالب ولا للمعلم في صفحة التسميع.') }}">
                                            {{ __('إلغاء تفعيل الخطة') }}
                                        </flux:menu.item>
                                    @else
                                        <flux:menu.item wire:click="togglePlanStatus({{ $plan->id }})" icon="play-circle"
                                            class="text-emerald-600 dark:text-emerald-400">
                                            {{ __('تفعيل الخطة') }}
                                        </flux:menu.item>
                                    @endif

                                    <flux:menu.item wire:click="openStudentModal({{ $plan->id }}, 'duplicate')"
                                        icon="document-duplicate">
                                        {{ __('نسخ الخطة لطالب آخر') }}
                                    </flux:menu.item>

                                    <flux:menu.separator />

                                    <flux:menu.item wire:click="deletePlan({{ $plan->id }})" variant="danger" icon="trash"
                                        wire:confirm="{{ __('هل أنت متأكد من حذف هذه الخطة بالكامل؟') }}">{{ __('حذف') }}
                                    </flux:menu.item>
                                    <flux:menu.item wire:click="openStudentModal({{ $plan->id }}, 'change')"
                                        icon="user-circle">
                                        {{ __('نقل الخطة لطالب آخر') }}
                                    </flux:menu.item>
                                </flux:menu>
                            </flux:dropdown>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
        </flux:checkbox.group>

        <div class="p-4 border-t border-zinc-100 dark:border-zinc-800">
            {{ $plans->links() }}
        </div>
    </flux:card>

    <flux:modal wire:model="showBulkDeleteModal" class="md:w-[450px]">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg" class="text-red-600 dark:text-red-400 flex items-center gap-2">
                    <flux:icon icon="exclamation-triangle" class="size-5" />
                    {{ __('حذف الخطط المحددة نهائياً') }}
                </flux:heading>
                <flux:subheading>
                    {{ __('أنت على وشك حذف') }} {{ count($selectedPlans) }} {{ __('من الخطط.') }}
                </flux:subheading>
            </div>

            <div class="bg-red-50 dark:bg-red-900/20 border border-red-100 dark:border-red-900/50 rounded-xl p-4 text-sm text-red-700 dark:text-red-400 space-y-1">
                <p class="font-bold">{{ __('تحذير: هذا الإجراء لا يمكن التراجع عنه!') }}</p>
                <p>{{ __('سيتم حذف الخطط المحددة مع جميع أيامها وإنجازات التسميع والتقييمات المسجلة فيها بشكل نهائي.') }}</p>
            </div>

            <flux:input wire:model="bulkDeleteConfirmation" wire:keydown.enter="bulkDelete"
                label="{{ __('للتأكيد، اكتب كلمة: حذف') }}" placeholder="{{ __('حذف') }}" />

            <div class="flex gap-2">
                <flux:spacer />
                <flux:button wire:click="$set('showBulkDeleteModal', false)">{{ __('تراجع') }}</flux:button>
                <flux:button wire:click="bulkDelete" variant="danger" icon="trash">
                    {{ __('حذف نهائي') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal wire:model="showStudentModal" class="md:w-96">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">
                    {{ $modalAction === 'change' ? __('نقل الخطة لطالب آخر') : __('نسخ الخطة لطالب آخر') }}
                </flux:heading>
                <flux:subheading>
                    {{ $modalAction === 'change' ? __('اختر الطالب الذي تود نقل هذه الخطة إليه.') : __('اختر الطالب الذي تود نسخ الخطة له (بدون بيانات الإنجاز).') }}
                </flux:subheading>
            </div>

            <flux:select wire:model="selectedNewStudentId" label="{{ __('اختر الطالب') }}"
                placeholder="{{ __('الرجاء الاختيار...') }}">
                @foreach($studentsList as $student)
                    <flux:select.option value="{{ $student->id }}">{{ $student->name }}</flux:select.option>
                @endforeach
            </flux:select>

            @if($modalAction === 'change' && $hasAchievements)
                <div
                    class="space-y-3 bg-amber-50 dark:bg-amber-900/20 border border-amber-100 dark:border-amber-900/50 rounded-xl p-4">
                    <div class="flex gap-2 text-amber-700 dark:text-amber-500 font-medium">
                        <flux:icon icon="exclamation-triangle" class="w-5 h-5 shrink-0" />
                        <span class="text-sm">{{ __('هذه الخطة تحتوي على أيام منجزة وتسميعات سابقة.') }}</span>
                    </div>

                    <flux:radio.group wire:model.live="keepAchievements"
                        label="{{ __('كيف تود التعامل مع الإنجازات السابقة؟') }}">
                        <flux:radio value="yes" label="{{ __('نقل الإنجازات والتسميعات مع الخطة') }}" />
                        <flux:radio value="no" label="{{ __('تصفير ومسح الإنجازات لتبدأ كخطة جديدة') }}" />
                    </flux:radio.group>
                    @error('keepAchievements')
                        <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                    @enderror

                    @if($keepAchievements === 'no')
                        <p class="text-xs text-red-600 dark:text-red-400 mt-2 font-bold">
                            {{ __('تنبيه: سيتم مسح جميع الإنجازات والتقييمات المسجلة في هذه الخطة بشكل نهائي، ولا يمكن التراجع عن هذا الإجراء.') }}
                        </p>
                    @endif
                </div>
            @endif

            <div class="flex gap-2">
                <flux:spacer />
                <flux:button wire:click="$set('showStudentModal', false)">{{ __('إلغاء') }}</flux:button>
                <flux:button wire:click="executeStudentAction" variant="primary">{{ __('تأكيد') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</div>