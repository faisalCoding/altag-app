<div class="flex flex-col gap-6" dir="rtl">
    <x-auth-header :title="__('إنشاء حساب جديد')" :description="__('املأ البيانات التالية لإنشاء حساب جديد')" />

    <x-auth-session-status class="text-center" :status="session('status')" />

    <form wire:submit="register" class="flex flex-col gap-6">
        <!-- Name -->
        <flux:input name="name" :label="__('الاسم الكامل')" wire:model="name" type="text" required autofocus
            autocomplete="name" placeholder="أدخل اسمك الكامل" />

        <!-- Email Address -->  
        <flux:input name="email" :label="__('البريد الإلكتروني')" wire:model="email" type="email" required
            autocomplete="email" placeholder="email@example.com" />

        <flux:field>
            <flux:label>{{ __('رقم الهاتف') }}</flux:label>
            <div class="flex items-center gap-2 mt-1" dir="ltr">
                <div class="bg-zinc-50 dark:bg-zinc-800 text-zinc-500 dark:text-zinc-400 rounded-lg px-3 flex items-center justify-center border border-zinc-200 dark:border-zinc-700 font-mono shadow-sm h-10 shrink-0">966</div>
                <flux:input name="phone" wire:model="phone" type="tel" required
                    class="w-full mt-0!" dir="ltr" placeholder="5XXXXXXXX" />
            </div>
            <flux:error name="phone" />
        </flux:field>

        <!-- Password -->
        <flux:input name="password" :label="__('كلمة المرور')" wire:model="password" type="password" required
            autocomplete="new-password" :placeholder="__('كلمة المرور')" viewable />

        <!-- Confirm Password -->
        <flux:input name="password_confirmation" :label="__('تأكيد كلمة المرور')" wire:model="password_confirmation"
            type="password" required autocomplete="new-password" :placeholder="__('تأكيد كلمة المرور')" viewable />

        <flux:field variant="inline">
            <flux:checkbox name="terms" wire:model="terms" required />
            <flux:label>{{ __('أوافق على الشروط والأحكام والسياسة الخاصة بالخصوصية') }}</flux:label>
            <flux:error name="terms" />
        </flux:field>

        <div class="flex items-center justify-end">
            <flux:button variant="primary" type="submit" class="w-full">
                {{ __('إنشاء حساب') }}
            </flux:button>
        </div>
    </form>

    <div class="space-x-1 text-sm text-center rtl:space-x-reverse text-zinc-600 dark:text-zinc-400">
        <span>{{ __('لديك حساب بالفعل؟') }}</span>
        <flux:link :accent="false" :href="route('login')" wire:navigate>{{ __('تسجيل الدخول') }}</flux:link>
    </div>
</div>
