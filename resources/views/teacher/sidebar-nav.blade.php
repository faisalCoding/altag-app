<flux:sidebar.group :heading="__('Platform')" class="grid">
    <flux:sidebar.item icon="home" :href="route('teacher.dashboard')" :current="request()->routeIs('teacher.dashboard')" wire:navigate>
        {{ __('Dashboard') }}
    </flux:sidebar.item>
</flux:sidebar.group>

<flux:sidebar.group heading="التعليم" class="grid">
    <flux:sidebar.item icon="users" href="#" wire:navigate>
        طلابي
    </flux:sidebar.item>
    <flux:sidebar.item icon="book-open" href="#" wire:navigate>
        المناهج
    </flux:sidebar.item>
    <flux:sidebar.item icon="calendar" :href="route('teacher.attendance')" :current="request()->routeIs('teacher.attendance')" wire:navigate>
        سجل الحضور
    </flux:sidebar.item>
</flux:sidebar.group>
