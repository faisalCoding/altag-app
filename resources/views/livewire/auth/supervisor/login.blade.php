<div class="flex flex-col gap-6" dir="rtl">
    <x-auth-header :title="'تسجيل الدخول كـ مشرف'" :description="__('أدخل بريدك الإلكتروني وكلمة المرور للمتابعة')" />

    <x-auth-session-status class="text-center" :status="session('status')" />

    <form wire:submit="login" class="flex flex-col gap-6">
        <flux:input name="email" :label="__('البريد الإلكتروني')" wire:model="email" type="email" required autofocus
            autocomplete="email" placeholder="email@example.com" />

        <div class="relative">
            <flux:input name="password" :label="__('كلمة المرور')" wire:model="password" type="password" required
                autocomplete="current-password" :placeholder="__('كلمة المرور')" viewable />

            @if (Route::has('password.request'))
                <flux:link class="absolute top-0 text-sm inset-e-0" :href="route('password.request')" wire:navigate>
                    {{ __('نسيت كلمة المرور؟') }}
                </flux:link>
            @endif
        </div>

        <flux:checkbox name="remember" wire:model="remember" :label="__('تذكرني')" />

        <div class="flex items-center justify-end">
            <flux:button variant="primary" type="submit" class="w-full" data-test="login-button">
                {{ __('تسجيل الدخول') }}
            </flux:button>
        </div>
    </form>

    <div class="space-x-1 text-sm text-center rtl:space-x-reverse text-zinc-600 dark:text-zinc-400">
        <span>{{ __('ليس لديك حساب؟') }}</span>
        <flux:link :href="route('supervisor.register')" wire:navigate>{{ __('تسجيل حساب جديد') }}</flux:link>
    </div>
</div>
