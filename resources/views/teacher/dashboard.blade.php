<x-layouts.role-shell>
    <x-slot:title>
        {{ __('لوحة تحكم المعلم') }}
    </x-slot:title>

    <x-slot:sidebar>
        @include('teacher.sidebar-nav')
    </x-slot:sidebar>

    <div class="py-4 md:p-8 space-y-8" dir="rtl">
        <!-- Dashboard Main Volt Component -->
        <livewire:teacher.dashboard />

        <!-- Exceeded Limits (Violations) List — the component carries its own heading -->
        <livewire:shared.exceeded-limits />
    </div>
</x-layouts.role-shell>
