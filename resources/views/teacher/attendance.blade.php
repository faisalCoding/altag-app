<x-layouts.role-shell>
    <x-slot:title>
        {{ __('سجل الحضور') }}
    </x-slot:title>

    <x-slot:sidebar>
        @include('teacher.sidebar-nav')
    </x-slot:sidebar>

    <div class="p-1 md:p-8">
        <livewire:teacher.attendance />
    </div>
</x-layouts.role-shell>
