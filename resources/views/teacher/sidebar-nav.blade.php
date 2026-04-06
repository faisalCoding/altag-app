<flux:sidebar.group heading="التعليم" class="grid">
    <flux:sidebar.item icon="home" :href="route('teacher.dashboard')" :current="request()->routeIs('teacher.dashboard')" wire:navigate>
        {{ __('الرئيسية') }}
    </flux:sidebar.item>
    <flux:sidebar.group  heading="{{ __('الخطط القرآنية') }}" class="mt-4">
        <flux:sidebar.item href="{{ route('teacher.plan-creator') }}" icon="pencil-square">{{ __('إنشاء خطة طالب') }}</flux:sidebar.item>
        <flux:sidebar.item href="{{ route('teacher.student-plans') }}" icon="clipboard-document-list">{{ __('عرض الخطط المنشأة') }}</flux:sidebar.item>
        
    </flux:sidebar.group>

    <flux:sidebar.group  heading="{{ __('التحضير') }}" class="mt-4">
        <flux:sidebar.item icon="calendar" :href="route('teacher.attendance')" :current="request()->routeIs('teacher.attendance')" wire:navigate>
            سجل الحضور
        </flux:sidebar.item>
    </flux:sidebar.group>
</flux:sidebar.group>
