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
    $showSearch = in_array($activeGuard, ['manager', 'supervisor', 'teacher', 'student'], true);
    $otherRoles = $currentUser ? $currentUser->roles->pluck('role')->reject(fn ($role) => $role === $activeGuard)->values() : collect();
@endphp

<div class="flex w-full items-center gap-4 bg-white dark:bg-zinc-900 border-b border-zinc-100 dark:border-zinc-800 px-6 py-3">
    <x-app-logo href="{{ route('dashboard') }}" wire:navigate />

    <div class="flex-1 flex justify-center px-6 min-w-0">
        @if($activeGuard === 'student')
            <div class="w-full max-w-xl">
                <livewire:student.header-search />
            </div>
        @elseif($showSearch)
            <livewire:shared.header-search />
        @endif
    </div>

    <div class="flex items-center gap-3">
        @if($activeGuard && \Illuminate\Support\Facades\Route::has("{$activeGuard}.guide"))
            <a href="{{ route("{$activeGuard}.guide") }}" wire:navigate title="دليل الاستخدام"
                class="p-2 rounded-full hover:bg-zinc-100 dark:hover:bg-zinc-800">
                <flux:icon icon="question-mark-circle" class="size-5 text-zinc-500 dark:text-zinc-300" />
            </a>
        @endif

        @if($activeGuard)
            <livewire:shared.notification-bell />
        @endif

        @if($currentUser)
            <flux:dropdown position="bottom" align="end">
                <button type="button" class="flex items-center gap-2 rounded-full py-1 pl-1 pr-3 hover:bg-zinc-100 dark:hover:bg-zinc-800">
                    <div class="text-right hidden xl:block">
                        <div class="text-sm font-bold text-zinc-800 dark:text-zinc-100">{{ $currentUser->name }}</div>
                        <div class="text-xs text-zinc-400">{{ $roleLabels[$activeGuard] ?? '' }}</div>
                    </div>
                    <flux:avatar :src="$currentUser->avatarUrl()" :name="$currentUser->name" :initials="$currentUser->initials()" size="sm" />
                    <flux:icon icon="chevron-down" class="size-4 text-zinc-400" />
                </button>

                <flux:menu>
                    <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                        <flux:avatar :src="$currentUser->avatarUrl()" :name="$currentUser->name" :initials="$currentUser->initials()" />
                        <div class="grid flex-1 text-start text-sm leading-tight">
                            <flux:heading class="truncate">{{ $currentUser->name }}</flux:heading>
                            <flux:text class="truncate">{{ $currentUser->email }}</flux:text>
                        </div>
                    </div>
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

                    @if($activeGuard === 'student')
                        <flux:menu.item :href="route('student.settings')" icon="cog" wire:navigate>
                            {{ __('إعدادات الحساب') }}
                        </flux:menu.item>
                        <form method="POST" action="{{ route('student.logout') }}" class="w-full">
                    @else
                        <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
                            {{ __('إعدادات الحساب') }}
                        </flux:menu.item>
                        <form method="POST" action="{{ route('logout') }}" class="w-full">
                    @endif
                        @csrf
                        <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle" class="w-full cursor-pointer">
                            {{ __('تسجيل الخروج') }}
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        @endif
    </div>
</div>
