@php
    $navItems = collect([
        ['name' => 'الرئيسية', 'route' => 'student.dashboard', 'icon' => 'home', 'active' => ['student.dashboard']],
        ['name' => 'خطتي', 'route' => 'student.plan', 'icon' => 'book-open', 'active' => ['student.plan', 'student.plan-creator', 'student.plan.print']],
        ['name' => 'الانضباط', 'route' => 'student.attendance', 'icon' => 'clipboard-document-check', 'active' => ['student.attendance']],
        ['name' => 'التقارير', 'route' => 'student.reports', 'icon' => 'chart-bar-square', 'active' => ['student.reports']],
    ])->filter(fn (array $item) => \App\Support\RolePages::isEnabled('student', $item['route']));
@endphp

{{-- The four pages a student opens daily, one tap away; everything else
     stays in the side menu, which the last button opens. --}}
<nav aria-label="{{ __('التنقل السريع') }}" data-bottom-nav
    class="fixed inset-x-3 z-[100] lg:hidden rounded-3xl bg-maroon dark:bg-accent-dark border border-white/10 shadow-lg shadow-black/20"
    style="bottom: max(0.75rem, env(safe-area-inset-bottom));">
    <div class="flex items-stretch justify-around gap-1 p-1.5 max-w-lg mx-auto">
        @foreach($navItems as $item)
            @php $isActive = request()->routeIs(...$item['active']); @endphp
            <a href="{{ route($item['route']) }}" wire:navigate
                @if($isActive) aria-current="page" @endif
                class="flex flex-1 min-w-0 flex-col items-center justify-center gap-1 min-h-14 rounded-2xl transition-colors duration-200 {{ $isActive ? 'text-white bg-white/15' : 'text-white/65 hover:text-white hover:bg-white/5' }}">
                <flux:icon :icon="$item['icon']" :variant="$isActive ? 'solid' : 'outline'" class="size-6 shrink-0" />
                <span class="text-[11px] font-bold leading-none truncate max-w-full">{{ $item['name'] }}</span>
            </a>
        @endforeach

        <button type="button" x-data x-on:click="$dispatch('flux-sidebar-toggle')"
            class="flex flex-1 min-w-0 flex-col items-center justify-center gap-1 min-h-14 rounded-2xl transition-colors duration-200 text-white/65 hover:text-white hover:bg-white/5">
            <flux:icon icon="squares-2x2" class="size-6 shrink-0" />
            <span class="text-[11px] font-bold leading-none">{{ __('المزيد') }}</span>
        </button>
    </div>
</nav>
