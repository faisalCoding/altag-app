@props([
    'sidebar' => false,
])

@if($sidebar)
    <flux:sidebar.brand href="{{ route('home') }}" name="مجمع التاج القرآني" class="text-maroon dark:text-white" {{ $attributes }}>
        <x-slot name="logo" class="flex items-center justify-center">
            <img src="{{ asset('images/altag_logo.png') }}" alt="Logo" class="h-8 object-contain" />
        </x-slot>
    </flux:sidebar.brand>
@else
    <flux:brand href="{{ route('home') }}" name="مجمع التاج القرآني" class="text-maroon dark:text-white" {{ $attributes }}>
        <x-slot name="logo" class="flex items-center justify-center">
            <img src="{{ asset('images/altag_logo.png') }}" alt="Logo" class="h-8 object-contain" />
        </x-slot>
    </flux:brand>
@endif
