<flux:sidebar.group :heading="__('Platform')" class="grid">
    <flux:sidebar.item icon="home" :href="route('parent.dashboard')" :current="request()->routeIs('parent.dashboard')" wire:navigate>
        {{ __('Dashboard') }}
    </flux:sidebar.item>
</flux:sidebar.group>

<flux:sidebar.group heading="متابعة الأبناء" class="grid">
    <flux:sidebar.item icon="user-group" href="#" wire:navigate>
        قائمة الأبناء
    </flux:sidebar.item>
    <flux:sidebar.item icon="chart-bar-square" href="#" wire:navigate>
        تقارير الأداء
    </flux:sidebar.item>
</flux:sidebar.group>
