<div class="flex flex-col gap-6" dir="rtl">
    <x-auth-header :title="'إنشاء حساب مشرف'" :description="__('أدخل بياناتك لإنشاء حساب مشرف جديد')" />

    <x-auth-session-status class="text-center" :status="session('status')" />

    <form wire:submit="register" class="flex flex-col gap-6">
        <flux:input name="name" :label="__('الاسم الكامل')" wire:model="name" type="text" required autofocus
            autocomplete="name" :placeholder="__('الاسم الكامل')" />

        <flux:input name="email" :label="__('البريد الإلكتروني')" wire:model="email" type="email" required
            autocomplete="email" placeholder="email@example.com" />

        <flux:input name="password" :label="__('كلمة المرور')" wire:model="password" type="password" required
            autocomplete="new-password" :placeholder="__('كلمة المرور')" viewable />

        <flux:input name="password_confirmation" :label="__('تأكيد كلمة المرور')" wire:model="password_confirmation"
            type="password" required autocomplete="new-password" :placeholder="__('تأكيد كلمة المرور')" viewable />

        <div class="flex items-center justify-end">
            <flux:button type="submit" variant="primary" class="w-full" data-test="register-user-button">
                {{ __('إنشاء الحساب') }}
            </flux:button>
        </div>
    </form>

    <div class="space-x-1 rtl:space-x-reverse text-center text-sm text-zinc-600 dark:text-zinc-400">
        <span>{{ __('لديك حساب بالفعل؟') }}</span>
        <flux:link :href="route('supervisor.login')" wire:navigate>{{ __('تسجيل الدخول') }}</flux:link>
    </div>
</div>
