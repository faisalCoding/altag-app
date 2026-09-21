@props([
    'sidebar' => false,
])

@if($sidebar)
    <flux:sidebar.brand name="مجمع التاج القرآني" class="text-maroon dark:text-white" {{ $attributes->merge(['href' => route('home')]) }}>
        <x-slot name="logo" class="flex items-center justify-center">
            <img src="{{ App\Support\Branding::logoUrl() }}" alt="Logo" class="h-8 object-contain" />
        </x-slot>
    </flux:sidebar.brand>
@else
    <flux:brand name="مجمع التاج القرآني" class="text-maroon dark:text-white" {{ $attributes->merge(['href' => route('home')]) }}>
        <x-slot name="logo" class="flex items-center justify-center">
            <img src="{{ App\Support\Branding::logoUrl() }}" alt="Logo" class="h-8 object-contain" />
        </x-slot>
    </flux:brand>
@endif
