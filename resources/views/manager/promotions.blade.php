<x-layouts.role-shell>
    <x-slot:title>
        {{ __('ترحيل الطلاب') }}
    </x-slot:title>

    <x-slot:sidebar>
        @include('manager.sidebar-nav')
    </x-slot:sidebar>

    <livewire:manager.promotions />
</x-layouts.role-shell>
