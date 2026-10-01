@php
    $role = null;
    $guards = [
        'manager' => 'manager',
        'supervisor' => 'supervisor',
        'teacher' => 'teacher',
        'student' => 'student',
        'guardian' => 'guardian',
    ];
    foreach($guards as $roleKey => $guardName) {
        if (auth()->guard($guardName)->check()) {
            $role = $roleKey;
            break;
        }
    }
    $authUser = $role ? auth()->guard($role)->user() : null;
@endphp
<!DOCTYPE html>
<html lang="ar" dir="rtl">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-zinc-50 dark:bg-zinc-950 text-zinc-900 dark:text-zinc-100 antialiased">
        <flux:sidebar sticky collapsible="mobile" class="self-start border-e border-zinc-100 bg-white dark:border-zinc-800 dark:bg-zinc-900">
            <flux:sidebar.header>
                <x-app-logo :sidebar="true" href="{{ route('dashboard') }}" wire:navigate />
                <flux:sidebar.collapse class="lg:hidden" />
            </flux:sidebar.header>

            <flux:sidebar.nav>


                @if($role && view()->exists("{$role}.sidebar-nav"))
                    @include("{$role}.sidebar-nav")
                @else
                    <flux:sidebar.group :heading="__('Platform')" class="grid">
                        <flux:sidebar.item icon="home" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate>
                            {{ __('Dashboard') }}
                        </flux:sidebar.item>
                    </flux:sidebar.group>
                @endif
            </flux:sidebar.nav>

            <flux:spacer />


            
            <x-desktop-user-menu class="hidden lg:block" :name="$authUser?->name ?? ''" />
        </flux:sidebar>

        <!-- Mobile User Menu -->
        <flux:header class="lg:hidden">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

            <flux:spacer />

            <flux:dropdown position="bottom" align="start">
                <flux:profile
                    :initials="$authUser?->initials() ?? ''"
                    icon-trailing="chevron-down"
                />

                <flux:menu>
                    <flux:menu.radio.group>
                        <div class="p-0 text-sm font-normal">
                            <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                                <flux:avatar
                                    :name="$authUser?->name ?? ''"
                                    :initials="$authUser?->initials() ?? ''"
                                />

                                <div class="grid flex-1 text-start text-sm leading-tight">
                                    <flux:heading class="truncate">{{ $authUser?->name ?? '' }}</flux:heading>
                                    <flux:text class="truncate">{{ $authUser?->email ?? '' }}</flux:text>
                                </div>
                            </div>
                        </div>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <flux:menu.radio.group>
                        @if(auth('student')->check())
                            <flux:menu.item :href="route('student.settings')" icon="cog" wire:navigate>
                                {{ __('Settings') }}
                            </flux:menu.item>
                        @else
                            <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
                                {{ __('Settings') }}
                            </flux:menu.item>
                        @endif
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    @if(auth('student')->check())
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
                            {{ __('Log out') }}
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:header>

        {{ $slot }}

        @persist('toast')
            <flux:toast />
        @endpersist

        @fluxScripts
    </body>
</html>
