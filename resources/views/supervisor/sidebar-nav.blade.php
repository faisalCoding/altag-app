<flux:sidebar.group :heading="__('Platform')" class="grid">
    <flux:sidebar.item icon="home" :href="route('supervisor.dashboard')" :current="request()->routeIs('supervisor.dashboard')" wire:navigate>
        {{ __('Dashboard') }}
    </flux:sidebar.item>
</flux:sidebar.group>

<flux:sidebar.group heading="الإشراف" class="grid">
    <flux:sidebar.item icon="circle-stack" href="#" wire:navigate>
        الحلقات المسؤولة
    </flux:sidebar.item>
    <flux:sidebar.item icon="users" href="#" wire:navigate>
        المعلمون
    </flux:sidebar.item>
</flux:sidebar.group>
