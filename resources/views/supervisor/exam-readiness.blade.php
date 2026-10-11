<x-layouts.role-shell :title="__('جاهزية الاختبارات')">
    <x-slot:sidebar>
        @include('supervisor.sidebar-nav')
    </x-slot:sidebar>

    <div class="space-y-5">
        <div class="space-y-1">
            <flux:heading size="xl" level="1">{{ __('جاهزية الاختبارات') }}</flux:heading>
            <flux:subheading>{{ __('طلاب مرحلتك المرشحون للاختبار. تحدد الإدارة مواعيدهم في دفعات.') }}</flux:subheading>
        </div>

        <livewire:shared.exam-readiness-list role="supervisor" />
    </div>
</x-layouts.role-shell>
