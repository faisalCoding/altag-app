<div class="flex flex-col gap-6" dir="rtl">
    <div class="flex flex-col items-center gap-2 text-center">
        <h1 class="text-2xl font-bold text-maroon dark:text-red-secondary">تسجيل الدخول</h1>
        <p class="text-sm text-neutral-grey dark:text-zinc-400">بصفتك {{ $roleName }} في مجمع التاج</p>
    </div>

    <!-- Session Status -->
    <x-auth-session-status class="text-center" :status="session('status')" />

    <form wire:submit="login" class="flex flex-col gap-6">
        <!-- Email Address -->
        <flux:input name="email" :label="__('البريد الإلكتروني')" wire:model="email" type="email" required
            autofocus autocomplete="email" placeholder="example@mail.com" />

        <!-- Password -->
        <div class="space-y-2">
            <div class="flex justify-between items-center px-1">
                <flux:label>{{ __('كلمة المرور') }}</flux:label>
                @if (Route::has('password.request'))
                    <flux:link class="text-xs" :href="route('password.request')" wire:navigate>
                        {{ __('نسيت كلمة المرور؟') }}
                    </flux:link>
                @endif
            </div>
            <flux:input name="password" wire:model="password" type="password" required
                autocomplete="current-password" :placeholder="__('كلمة المرور')" viewable />
        </div>

        <!-- Remember Me -->
        <flux:checkbox name="remember" wire:model="remember" :label="__('تذكرني')" />

        <flux:button variant="primary" type="submit" class="w-full h-11 text-lg font-bold bg-maroon hover:bg-burgundy dark:bg-red-secondary dark:hover:bg-maroon transition-colors" data-test="login-button">
            {{ __('دخول') }}
        </flux:button>
    </form>

    <div class="text-sm text-center text-zinc-600 dark:text-zinc-400 pt-4 border-t border-zinc-100 dark:border-zinc-800">
        <span>{{ __('ليس لديك حساب؟') }}</span>
        <flux:link :href="route($role . '.register')" wire:navigate class="font-bold text-maroon dark:text-red-secondary">
            {{ __('تسجيل حساب جديد') }}
        </flux:link>
    </div>
</div>
