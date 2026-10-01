<x-layouts.role-shell :title="__('إعدادات الحساب')">
    <x-slot:sidebar>
        @include('student.sidebar-nav')
    </x-slot:sidebar>
    <div class="p-6">
        <livewire:student.settings />
    </div>
</x-layouts.role-shell>