<?php

use App\Models\Role;
use App\Models\Staff;
use Flux\Flux;
use Illuminate\Support\Facades\Hash;
use Livewire\Component;
use Livewire\Attributes\Locked;

new class extends Component
{
    public string $search = '';

    public string $name = '';

    public string $email = '';

    public string $password = '';

    public ?int $selectedRoleId = null;

    #[Locked]
    public ?int $editingStaffId = null;

    public function mount(): void
    {
        $this->selectedRoleId = Role::where('guard_name', 'staff')->value('id');
    }

    public function updatedSearch(): void
    {
        //
    }

    public function create(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'selectedRoleId' => ['required', 'exists:roles,id'],
        ]);

        Staff::create([
            'name' => $this->name,
            'email' => $this->email,
            'password' => Hash::make($this->password),
            'staff_role_id' => $this->selectedRoleId,
            'is_approved' => true,
            'approved_by' => auth('manager')->id(),
        ]);

        $this->reset(['name', 'email', 'password']);
        Flux::modal('staff-modal')->close();
        Flux::toast(__('تم إنشاء حساب الموظف بنجاح'), variant: 'success');
    }

    public function edit(int $id): void
    {
        $staff = Staff::find($id);

        if (! $staff) {
            Flux::toast(__('الموظف غير موجود'), variant: 'danger');

            return;
        }

        $this->editingStaffId = $staff->id;
        $this->name = $staff->name;
        $this->email = $staff->email;
        $this->selectedRoleId = $staff->staff_role_id;
        $this->password = '';

        Flux::modal('staff-edit-modal')->show();
    }

    public function update(): void
    {
        $staff = Staff::find($this->editingStaffId);

        if (! $staff) {
            return;
        }

        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email,'.$staff->id],
            'selectedRoleId' => ['required', 'exists:roles,id'],
            'password' => ['nullable', 'string', 'min:8'],
        ]);

        $staff->update([
            'name' => $this->name,
            'email' => $this->email,
            'staff_role_id' => $this->selectedRoleId,
            ...($this->password !== '' ? ['password' => Hash::make($this->password)] : []),
        ]);

        $this->reset(['name', 'email', 'password', 'editingStaffId']);
        Flux::modal('staff-edit-modal')->close();
        Flux::toast(__('تم تحديث بيانات الموظف بنجاح'), variant: 'success');
    }

    public function delete(int $id): void
    {
        Staff::find($id)?->delete();

        Flux::toast(__('تم حذف الموظف بنجاح'), variant: 'success');
    }

    public function with(): array
    {
        $roles = Role::where('guard_name', 'staff')->orderBy('label')->get();

        $staffMembers = Staff::with('staffRole')
            ->when($this->search, fn ($q) => $q->where(fn ($q) => $q
                ->where('name', 'like', "%{$this->search}%")
                ->orWhere('email', 'like', "%{$this->search}%")))
            ->latest()
            ->get();

        return [
            'roles' => $roles,
            'staffMembers' => $staffMembers,
        ];
    }
};
?>

<div class="space-y-6" dir="rtl">
    <div class="flex items-center justify-between gap-3">
        <div class="flex items-center gap-3">
            <div class="p-2 rounded-lg bg-zinc-50 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">
                <flux:icon icon="identification" />
            </div>
            <div>
                <flux:heading size="xl" class="font-bold text-zinc-900 dark:text-white">{{ __('إدارة الموظفين') }}</flux:heading>
                <flux:subheading>{{ __('أنشئ حسابات للأدوار المخصّصة اللي عملتها من صفحة صلاحيات الصفحات') }}</flux:subheading>
            </div>
        </div>
        <flux:modal.trigger name="staff-modal">
            <flux:button variant="primary" icon="user-plus" class="!bg-maroon hover:!bg-burgundy">{{ __('إضافة موظف') }}</flux:button>
        </flux:modal.trigger>
    </div>

    @if($roles->isEmpty())
        <flux:callout icon="information-circle" color="amber">
            {{ __('لسه معملتش أي دور مخصّص. روح صفحة "صلاحيات الصفحات" وأضف دور جديد الأول.') }}
        </flux:callout>
    @endif

    <flux:input icon="magnifying-glass" wire:model.live.debounce.300ms="search" placeholder="{{ __('بحث عن موظف...') }}" />

    <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 shadow-xs overflow-hidden">
        <flux:table class="w-full">
            <flux:table.columns>
                <flux:table.column>{{ __('الموظف') }}</flux:table.column>
                <flux:table.column>{{ __('الدور') }}</flux:table.column>
                <flux:table.column class="w-10"></flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach($staffMembers as $staff)
                    <flux:table.row :key="$staff->id">
                        <flux:table.cell class="first:ps-3">
                            <div class="flex flex-col">
                                <span class="font-bold text-zinc-900 dark:text-white">{{ $staff->name }}</span>
                                <span class="text-xs text-zinc-500">{{ $staff->email }}</span>
                            </div>
                        </flux:table.cell>
                        <flux:table.cell>
                            @if($staff->staffRole)
                                <flux:badge size="sm" variant="neutral">{{ $staff->staffRole->label }}</flux:badge>
                            @else
                                <span class="text-xs text-zinc-400">{{ __('بدون دور') }}</span>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>
                            <div class="flex items-center gap-2">
                                <flux:button size="sm" icon="pencil" wire:click="edit({{ $staff->id }})" />
                                <flux:button size="sm" icon="trash" variant="danger" wire:click="delete({{ $staff->id }})" wire:confirm="{{ __('متأكد إنك عايز تحذف الموظف ده؟') }}" />
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </div>

    <flux:modal name="staff-modal" class="md:w-96">
        <form wire:submit="create" class="space-y-4">
            <flux:heading size="lg">{{ __('إضافة موظف جديد') }}</flux:heading>

            <flux:input wire:model="name" label="{{ __('الاسم') }}" required />
            <flux:input wire:model="email" type="email" label="{{ __('البريد الإلكتروني') }}" required />
            <flux:input wire:model="password" type="password" label="{{ __('كلمة المرور') }}" viewable required />

            <flux:select wire:model="selectedRoleId" label="{{ __('الدور') }}">
                @foreach($roles as $role)
                    <flux:select.option :value="$role->id">{{ $role->label }}</flux:select.option>
                @endforeach
            </flux:select>

            <div class="flex justify-end gap-2">
                <flux:button type="submit" variant="primary" class="!bg-maroon hover:!bg-burgundy">{{ __('إنشاء') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="staff-edit-modal" class="md:w-96">
        <form wire:submit="update" class="space-y-4">
            <flux:heading size="lg">{{ __('تعديل بيانات الموظف') }}</flux:heading>

            <flux:input wire:model="name" label="{{ __('الاسم') }}" required />
            <flux:input wire:model="email" type="email" label="{{ __('البريد الإلكتروني') }}" required />
            <flux:input wire:model="password" type="password" label="{{ __('كلمة مرور جديدة (اختياري)') }}" viewable />

            <flux:select wire:model="selectedRoleId" label="{{ __('الدور') }}">
                @foreach($roles as $role)
                    <flux:select.option :value="$role->id">{{ $role->label }}</flux:select.option>
                @endforeach
            </flux:select>

            <div class="flex justify-end gap-2">
                <flux:button type="submit" variant="primary" class="!bg-maroon hover:!bg-burgundy">{{ __('حفظ') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
