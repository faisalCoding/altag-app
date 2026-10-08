@php
    $roleLabels = [
        'manager' => 'مدير',
        'supervisor' => 'مشرف',
        'teacher' => 'معلم حلقة',
        'student' => 'طالب',
        'guardian' => 'ولي أمر',
    ];
    $validGuards = ['manager', 'supervisor', 'teacher', 'student', 'guardian'];
    // The active role is derived from the current route's own name prefix
    // first (e.g. "supervisor.dashboard" -> "supervisor"), not by scanning
    // guards in a fixed priority order — switching roles keeps every guard
    // authenticated at once, so a fixed scan would always resolve to
    // whichever guard happens to come first in the list, ignoring which
    // role the user actually just switched into.
    $routeRole = \Illuminate\Support\Str::before(request()->route()?->getName() ?? '', '.');
    $activeGuard = in_array($routeRole, $validGuards, true) && auth()->guard($routeRole)->check()
        ? $routeRole
        : collect($validGuards)->first(fn ($guard) => auth()->guard($guard)->check());
    $currentUser = $activeGuard ? auth()->guard($activeGuard)->user() : null;
    $otherRoles = $currentUser ? $currentUser->roles->pluck('role')->reject(fn ($role) => $role === $activeGuard)->values() : collect();
@endphp
<flux:dropdown position="top" align="end">
    <flux:profile
        :avatar="$currentUser?->avatarUrl()"
        :initials="$currentUser?->initials()"
        icon-trailing="chevron-down"
    />

    <flux:menu>
        <flux:menu.radio.group>
            <div class="p-0 text-sm font-normal text-right">
                <div class="flex items-center gap-2 px-1 py-1.5 text-right text-sm">
                    <flux:avatar
                        :src="$currentUser?->avatarUrl()"
                        :name="$currentUser?->name"
                        :initials="$currentUser?->initials()"
                    />

                    <div class="grid flex-1 text-right text-sm leading-tight">
                        <flux:heading class="truncate">{{ $currentUser?->name }}</flux:heading>
                        <flux:text class="truncate">{{ $currentUser?->email }}</flux:text>
                    </div>
                </div>
            </div>
        </flux:menu.radio.group>

        <flux:menu.separator />

        @if($otherRoles->isNotEmpty())
            <div class="px-1 pb-1.5">
                <div class="text-xs font-bold text-zinc-400 dark:text-zinc-500 mb-1.5">{{ __('التبديل بين أدوارك') }}</div>
                <div class="flex flex-wrap gap-1.5">
                    <flux:badge size="sm" color="lime">{{ $roleLabels[$activeGuard] ?? $activeGuard }}</flux:badge>
                    @foreach($otherRoles as $role)
                        <form method="POST" action="{{ route('switch-role', ['guard' => $role]) }}">
                            @csrf
                            <button type="submit" class="text-xs font-bold px-2 py-1 rounded-md bg-zinc-100 dark:bg-zinc-800 text-zinc-600 dark:text-zinc-300 hover:bg-zinc-200 dark:hover:bg-zinc-700">
                                {{ $roleLabels[$role] ?? $role }}
                            </button>
                        </form>
                    @endforeach
                </div>
            </div>
            <flux:menu.separator />
        @endif

        <flux:menu.radio.group>
            @if($activeGuard === 'student')
                <flux:menu.item :href="route('student.settings')" icon="cog" wire:navigate>
                    {{ __('الإعدادات') }}
                </flux:menu.item>
            @else
                <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
                    {{ __('الإعدادات') }}
                </flux:menu.item>
            @endif
        </flux:menu.radio.group>

        <flux:menu.separator />

        @if($activeGuard === 'student')
            <form method="POST" action="{{ route('student.logout') }}" class="w-full">
        @else
            <form method="POST" action="{{ route('logout') }}" class="w-full">
        @endif
            @csrf
            <flux:menu.item
                as="button"
                type="submit"
                icon="arrow-right-start-on-rectangle"
                class="w-full cursor-pointer"
                data-test="logout-button"
            >
                {{ __('تسجيل الخروج') }}
            </flux:menu.item>
        </form>
    </flux:menu>
</flux:dropdown>
