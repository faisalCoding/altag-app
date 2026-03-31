<div class="flex flex-col gap-6" dir="rtl">
    <div class="flex flex-col items-center gap-2 text-center">
        <h1 class="text-2xl font-bold text-maroon dark:text-red-secondary">إنشاء حساب جديد</h1>
        <p class="text-sm text-neutral-grey dark:text-zinc-400">سجل ك{{ $roleName }} في مجمع التاج</p>
    </div>

    <!-- Session Status -->
    <x-auth-session-status class="text-center" :status="session('status')" />

    <form wire:submit="register" class="flex flex-col gap-6">
        <!-- Name -->
        <flux:input name="name" :label="__('الاسم الكامل')" wire:model="name" type="text" required autofocus
            autocomplete="name" :placeholder="__('الاسم الكامل')" />

        <!-- Email Address -->
        <flux:input name="email" :label="__('البريد الإلكتروني')" wire:model="email" type="email" required
            autocomplete="email" placeholder="example@mail.com" />

        <!-- Password -->
        <flux:input name="password" :label="__('كلمة المرور')" wire:model="password" type="password" required
            autocomplete="new-password" :placeholder="__('كلمة المرور')" viewable />

        <!-- Confirm Password -->
        <flux:input name="password_confirmation" :label="__('تأكيد كلمة المرور')" wire:model="password_confirmation"
            type="password" required autocomplete="new-password" :placeholder="__('تأكيد كلمة المرور')" viewable />

        <flux:button type="submit" variant="primary" class="w-full h-11 text-lg font-bold bg-maroon hover:bg-burgundy dark:bg-red-secondary dark:hover:bg-maroon transition-colors" data-test="register-user-button">
            {{ __('إنشاء الحساب') }}
        </flux:button>
    </form>

    <div class="text-sm text-center text-zinc-600 dark:text-zinc-400 pt-4 border-t border-zinc-100 dark:border-zinc-800">
        <span>{{ __('لديك حساب بالفعل؟') }}</span>
        <flux:link :href="route($role . '.login')" wire:navigate class="font-bold text-maroon dark:text-red-secondary">
            {{ __('تسجيل الدخول') }}
        </flux:link>
    </div>
</div>
