@php
    $guardianUnreadMessages = \App\Services\MessagingService::unreadCountFor('guardian', auth('guardian')->id());
@endphp

<flux:sidebar.group :heading="__('Platform')" class="grid">
    <flux:sidebar.item class="[&_svg]:bg-[#3b82f6] hover:[&_svg]:bg-[#2563eb]" icon="home" :href="route('guardian.dashboard')" :current="request()->routeIs('guardian.dashboard')" wire:navigate>
        {{ __('الرئيسية') }}
    </flux:sidebar.item>
    @if(\App\Support\RolePages::isEnabled('guardian', 'guardian.messages'))
        <flux:sidebar.item class="[&_svg]:bg-[#e11d48] hover:[&_svg]:bg-[#be123c]" icon="envelope" :href="route('guardian.messages')" :current="request()->routeIs('guardian.messages')"
            :badge="$guardianUnreadMessages > 0 ? $guardianUnreadMessages : null" badge-color="rose" wire:navigate>
            {{ __('الرسائل') }}
        </flux:sidebar.item>
    @endif
    @if(\App\Support\RolePages::isEnabled('guardian', 'guardian.challenges'))
        <flux:sidebar.item class="[&_svg]:bg-[#eab308] hover:[&_svg]:bg-[#ca8a04]" icon="trophy" :href="route('guardian.challenges')" :current="request()->routeIs('guardian.challenges')" wire:navigate>
            {{ __('المكافآت التحفيزية') }}
        </flux:sidebar.item>
    @endif
    @if(\App\Support\RolePages::isEnabled('guardian', 'guardian.schedule'))
        <flux:sidebar.item class="[&_svg]:bg-[#16a6bd] hover:[&_svg]:bg-[#0e8a9e]" icon="calendar-days" :href="route('guardian.schedule')" :current="request()->routeIs('guardian.schedule*')" wire:navigate>
            {{ __('جدول البرنامج') }}
        </flux:sidebar.item>
    @endif
</flux:sidebar.group>

<flux:sidebar.group heading="متابعة الأبناء" class="grid">
    @foreach(auth()->guard('guardian')->user()->students()->with('circle')->get() as $sidebarStudent)
        <flux:sidebar.item class="[&_svg]:bg-[#64748b] hover:[&_svg]:bg-[#475569]"
            icon="academic-cap"
            :href="route('guardian.student', $sidebarStudent->id)"
            :current="request()->routeIs('guardian.student*') && request()->route('id') == $sidebarStudent->id"
            wire:navigate>
            {{ $sidebarStudent->name }}
        </flux:sidebar.item>
    @endforeach
</flux:sidebar.group>
