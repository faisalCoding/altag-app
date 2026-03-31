@props([
    'sidebar' => false,
])

@if($sidebar)
    <flux:sidebar.brand name="مجمع التاج القرآني" class="text-maroon dark:text-white" {{ $attributes }}>
        <x-slot name="logo" class="flex aspect-square size-8 items-center justify-center rounded-lg bg-white border border-zinc-100 shadow-sm text-maroon dark:bg-zinc-800 dark:border-zinc-700 dark:text-red-secondary">
            <x-app-logo-icon class="size-5 fill-current" />
        </x-slot>
    </flux:sidebar.brand>
@else
    <flux:brand name="مجمع التاج القرآني" class="text-maroon dark:text-white" {{ $attributes }}>
        <x-slot name="logo" class="flex aspect-square size-8 items-center justify-center rounded-lg bg-white border border-zinc-100 shadow-sm text-maroon dark:bg-zinc-800 dark:border-zinc-700 dark:text-red-secondary">
            <x-app-logo-icon class="size-5 fill-current" />
        </x-slot>
    </flux:brand>
@endif
