<?php

use App\Models\Role;
use App\Models\RoleScreenPermission;
use App\Models\Screen;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\Attributes\Locked;

new class extends Component
{
    #[Locked]
    public ?int $activeRoleId = null;

    public string $newRoleLabel = '';

    public function mount(): void
    {
        $this->activeRoleId = Role::query()->orderByDesc('is_system')->orderBy('id')->value('id');
    }

    public function setActiveRole(int $roleId): void
    {
        if (Role::whereKey($roleId)->exists()) {
            $this->activeRoleId = $roleId;
        }
    }

    public function createRole(): void
    {
        $this->validate([
            'newRoleLabel' => ['required', 'string', 'max:255'],
        ], [], ['newRoleLabel' => __('اسم الدور')]);

        $key = Str::slug($this->newRoleLabel, '_');

        if (Role::where('key', $key)->exists()) {
            $this->addError('newRoleLabel', __('يوجد دور بنفس الاسم بالفعل.'));

            return;
        }

        $role = Role::create([
            'key' => $key,
            'label' => $this->newRoleLabel,
            'guard_name' => 'staff',
            'is_system' => false,
            'is_active' => true,
            'created_by' => Auth::guard('manager')->id(),
        ]);

        $this->newRoleLabel = '';
        $this->activeRoleId = $role->id;

        Flux::toast(__('تم إنشاء الدور، كل الصفحات معطّلة افتراضياً — فعّل اللي محتاجه.'), variant: 'success');
    }

    public function toggle(int $roleId, int $screenId): void
    {
        $role = Role::find($roleId);
        $screen = Screen::find($screenId);

        if (! $role || ! $screen || $screen->is_protected) {
            return;
        }

        // A system role is locked to its own screens: it lives behind its own
        // guard and renders a fixed sidebar, so granting it another role's page
        // is inert. Reject it at the source instead of creating a dead row.
        // Custom (staff) roles may still be composed from any screen.
        if ($role->is_system && $screen->owner_role_id !== $role->id) {
            return;
        }

        $existing = RoleScreenPermission::where('role_id', $roleId)->where('screen_id', $screenId)->first();

        if ($existing) {
            $existing->delete();
            Flux::toast(__('تم تعطيل الصفحة'), variant: 'success');
        } else {
            RoleScreenPermission::create([
                'role_id' => $roleId,
                'screen_id' => $screenId,
                'enabled_by' => Auth::guard('manager')->id(),
            ]);
            Flux::toast(__('تم تفعيل الصفحة'), variant: 'success');
        }
    }

    public function with(): array
    {
        $roles = Role::query()->orderByDesc('is_system')->orderBy('id')->get();
        $activeRole = $roles->firstWhere('id', $this->activeRoleId) ?? $roles->first();

        $screensQuery = Screen::query()->with('ownerRole')->orderBy('sort_order');

        // System roles only manage their own screens (see toggle()); custom
        // roles may be composed from every screen.
        if ($activeRole && $activeRole->is_system) {
            $screensQuery->where('owner_role_id', $activeRole->id);
        }

        $screensByOwner = $screensQuery->get()
            ->groupBy(fn (Screen $screen) => $screen->ownerRole->label);

        $enabledForActiveRole = $activeRole
            ? RoleScreenPermission::where('role_id', $activeRole->id)->pluck('screen_id')->all()
            : [];

        $roleCounts = $roles->mapWithKeys(function (Role $role) {
            return [$role->id => RoleScreenPermission::where('role_id', $role->id)->count()];
        });

        return [
            'roles' => $roles,
            'activeRole' => $activeRole,
            'screensByOwner' => $screensByOwner,
            'enabledForActiveRole' => $enabledForActiveRole,
            'roleCounts' => $roleCounts,
        ];
    }
};
?>

<div class="space-y-6" dir="rtl">
    <div>
        <flux:heading size="xl" class="font-bold text-zinc-900 dark:text-white">
            {{ __('صلاحيات الصفحات') }}
        </flux:heading>
        <flux:subheading class="text-zinc-500 dark:text-zinc-400 mt-1">
            {{ __('أنشئ أدوارًا جديدة، وحدّد لكل دور أي صفحات من كل صفحات النظام تظهر له. الصفحة المفعّلة بس هي اللي تظهر في القائمة الجانبية ويمكن الوصول لها.') }}
        </flux:subheading>
    </div>

    <flux:card class="flex items-end gap-3">
        <flux:field class="flex-1">
            <flux:label>{{ __('إنشاء دور جديد') }}</flux:label>
            <flux:input wire:model="newRoleLabel" wire:keydown.enter="createRole" placeholder="{{ __('مثال: مشرف مساعد') }}" />
            <flux:error name="newRoleLabel" />
        </flux:field>
        <flux:button variant="primary" wire:click="createRole" class="!bg-maroon hover:!bg-burgundy">
            {{ __('إضافة دور') }}
        </flux:button>
    </flux:card>

    <div class="flex items-center gap-2 border-b border-zinc-100 dark:border-zinc-800 overflow-x-auto">
        @foreach($roles as $role)
            <button
                wire:click="setActiveRole({{ $role->id }})"
                wire:key="role-tab-{{ $role->id }}"
                class="px-4 py-2.5 text-sm font-bold whitespace-nowrap border-b-2 transition-colors flex items-center gap-2
                    {{ $activeRole?->id === $role->id ? 'border-maroon text-maroon dark:text-red-secondary' : 'border-transparent text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200' }}">
                {{ $role->label }}
                @if(! $role->is_system)
                    <flux:badge color="zinc" size="sm">{{ __('مخصّص') }}</flux:badge>
                @endif
                @if($roleCounts[$role->id] > 0)
                    <flux:badge color="lime" size="sm">{{ $roleCounts[$role->id] }}</flux:badge>
                @endif
            </button>
        @endforeach
    </div>

    @if($activeRole)
        <div class="space-y-8">
            @foreach($screensByOwner as $ownerLabel => $screens)
                <div class="space-y-3">
                    <flux:heading size="lg">{{ $ownerLabel }}</flux:heading>
                    <div class="space-y-4">
                        @foreach($screens->groupBy('group_label') as $groupLabel => $groupScreens)
                            <flux:card>
                                <flux:heading size="sm" class="mb-4">{{ $groupLabel }}</flux:heading>
                                <div class="divide-y divide-zinc-50 dark:divide-zinc-800/60">
                                    @foreach($groupScreens as $screen)
                                        @php
                                            $isEnabled = in_array($screen->id, $enabledForActiveRole, true);
                                        @endphp
                                        <div class="flex items-center justify-between py-3" wire:key="screen-{{ $activeRole->id }}-{{ $screen->id }}">
                                            <div>
                                                <div class="text-sm font-bold text-zinc-800 dark:text-zinc-100">{{ $screen->label }}</div>
                                                <div class="text-xs text-zinc-400">{{ $screen->route_name }}</div>
                                            </div>

                                            @if($screen->is_protected)
                                                <flux:badge color="zinc" size="sm">{{ __('دائماً مفعّلة') }}</flux:badge>
                                            @else
                                                <flux:switch
                                                    :checked="$isEnabled"
                                                    wire:click="toggle({{ $activeRole->id }}, {{ $screen->id }})" />
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            </flux:card>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
