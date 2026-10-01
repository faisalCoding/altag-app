<x-layouts.role-shell>
    <x-slot:title>
        {{ __('جدول البرنامج') }}
    </x-slot:title>

    <x-slot:sidebar>
        @include('student.sidebar-nav')
    </x-slot:sidebar>

    <div class="md:p-8">
        <livewire:shared.program-schedule role="student" />
    </div>
</x-layouts.role-shell>
