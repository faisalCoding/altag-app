<x-layouts.role-shell>
    <x-slot:title>
        {{ __('سجل الانضباط') }}
    </x-slot:title>

    <x-slot:sidebar>
        @include('student.sidebar-nav')
    </x-slot:sidebar>

    <div class="md:p-8">
        <livewire:shared.discipline-record role="student" />
    </div>
</x-layouts.role-shell>
