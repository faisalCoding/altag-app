<nav aria-label="{{ __('التنقل السريع') }}" data-bottom-nav
    class="fixed inset-x-3 z-[100] lg:hidden rounded-3xl bg-maroon dark:bg-accent-dark border border-white/10 shadow-lg shadow-black/20"
    style="bottom: max(0.75rem, env(safe-area-inset-bottom));">
    <div class="flex items-stretch justify-around gap-1 p-1.5 max-w-lg mx-auto">

        @php
            $navItems = [
                ['name' => 'التحضير', 'route' => 'teacher.attendance', 'icon' => 'calendar', 'tab' => 'attendance'],
                ['name' => 'التسميع', 'route' => 'teacher.tasmeeh', 'icon' => 'book-open', 'tab' => 'tasmeeh'],
                ['name' => 'البنود', 'route' => 'teacher.grade-items', 'icon' => 'star', 'tab' => 'grade-items'],
                ['name' => 'الخطط', 'route' => 'teacher.plan-creator', 'icon' => 'pencil-square', 'tab' => 'plan-creator'],
                ['name' => 'الطلاب', 'route' => 'teacher.students', 'icon' => 'users', 'tab' => 'students'],
            ];
        @endphp

        {{-- Every tab keeps its name on show: an icon alone leaves a teacher guessing which one is the plans and which the items. --}}
        @foreach($navItems as $item)
            <a href="{{ route($item['route']) }}"
                x-data="{ isActive: '{{ $initialTab ?? '' }}' === '{{ $item['tab'] }}' || {{ request()->routeIs($item['route'] . '*') ? 'true' : 'false' }} }"
                x-on:click.prevent="if(document.getElementById('teacher-app-shell')) { $dispatch('switch-tab', { tab: '{{ $item['tab'] }}', url: '{{ route($item['route']) }}' }); } else { Livewire.navigate('{{ route($item['route']) }}'); }"
                x-on:switch-tab.window="isActive = ($event.detail.tab === '{{ $item['tab'] }}')"
                :class="isActive ? 'text-white bg-white/15' : 'text-white/65 hover:text-white hover:bg-white/5'"
                :aria-current="isActive ? 'page' : null"
                class="flex flex-1 min-w-0 flex-col items-center justify-center gap-1 min-h-14 rounded-2xl transition-colors duration-200">
                <flux:icon icon="{{ $item['icon'] }}" class="size-6 shrink-0"
                    x-bind:variant="isActive ? 'solid' : 'outline'" />
                <span class="text-[11px] font-bold leading-none truncate max-w-full">{{ $item['name'] }}</span>
            </a>
        @endforeach
    </div>
</nav>
