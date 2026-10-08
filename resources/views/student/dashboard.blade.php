<x-layouts.role-shell>
    <x-slot:title>
        {{ __('الرئيسية') }}
    </x-slot:title>

    <x-slot:sidebar>
        @include('student.sidebar-nav')
    </x-slot:sidebar>

    <x-slot:bottomNav>
        <x-student-gamification-nav />
    </x-slot:bottomNav>

    <div class="md:p-8">
        <livewire:student.guardian-notice />
        {{-- Above both the plain and the competition dashboard: what is due, and what was done. --}}
        <livewire:student.wird-card />
        <livewire:student.dashboard />
    </div>
</x-layouts.role-shell>