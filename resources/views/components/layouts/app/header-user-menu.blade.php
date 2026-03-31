<flux:dropdown position="top" align="end">
    <flux:profile
        :initials="auth()->user()->initials()"
        icon-trailing="chevron-down"
    />

    <flux:menu>
        <flux:menu.radio.group>
            <div class="p-0 text-sm font-normal text-right">
                <div class="flex items-center gap-2 px-1 py-1.5 text-right text-sm">
                    <flux:avatar
                        :name="auth()->user()->name"
                        :initials="auth()->user()->initials()"
                    />

                    <div class="grid flex-1 text-right text-sm leading-tight">
                        <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
                        <flux:text class="truncate">{{ auth()->user()->email }}</flux:text>
                    </div>
                </div>
            </div>
        </flux:menu.radio.group>

        <flux:menu.separator />

        <flux:menu.radio.group>
            <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
                {{ __('الإعدادات') }}
            </flux:menu.item>
        </flux:menu.radio.group>

        <flux:menu.separator />

        <form method="POST" action="{{ route('logout') }}" class="w-full">
            @csrf
            <flux:menu.item
                as="button"
                type="submit"
                icon="arrow-right-start-on-rectangle"
                class="w-full cursor-pointer"
                data-test="logout-button"
            >
                {{ __('تسجيل الخروج') }}
            </flux:menu.item>
        </form>
    </flux:menu>
</flux:dropdown>
