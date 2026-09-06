<x-layouts.role-shell>
    <x-slot:title>
        {{ __('سلّم الترحيل') }}
    </x-slot:title>

    <x-slot:sidebar>
        @include('manager.sidebar-nav')
    </x-slot:sidebar>

    <livewire:manager.promotion-ladder />
</x-layouts.role-shell>
