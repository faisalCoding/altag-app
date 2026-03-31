<flux:sidebar.group :heading="__('Platform')" class="grid">
    <flux:sidebar.item icon="home" :href="route('student.dashboard')" :current="request()->routeIs('student.dashboard')" wire:navigate>
        {{ __('Dashboard') }}
    </flux:sidebar.item>
</flux:sidebar.group>

<flux:sidebar.group heading="التعلم" class="grid">
    <flux:sidebar.item icon="book-open" href="#" wire:navigate>
        دروسي القرآنية
    </flux:sidebar.item>
    <flux:sidebar.item icon="chart-bar-square" href="#" wire:navigate>
        مستوى التقدم
    </flux:sidebar.item>
</flux:sidebar.group>
