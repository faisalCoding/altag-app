<x-layouts::auth :title="__('استعادة كلمة المرور')">
    <div class="flex flex-col gap-6" dir="rtl">
        <div class="flex flex-col items-center gap-2 text-center">
            <h1 class="text-2xl font-bold text-maroon dark:text-red-secondary">نسيت كلمة المرور؟</h1>
            <p class="text-sm text-neutral-grey dark:text-zinc-400">أدخل بريدك الإلكتروني وسنرسل لك رابطاً لاستعادة كلمة المرور.</p>
        </div>

        <!-- Session Status -->
        <x-auth-session-status class="text-center" :status="session('status')" />

        <form method="POST" action="{{ route('password.email') }}" class="flex flex-col gap-6">
            @csrf

            <!-- Email Address -->
            <flux:input
                name="email"
                :label="__('البريد الإلكتروني')"
                type="email"
                required
                autofocus
                placeholder="example@mail.com"
            />

            <flux:button variant="primary" type="submit" class="w-full h-11 text-lg font-bold bg-maroon hover:bg-burgundy dark:bg-red-secondary dark:hover:bg-maroon transition-colors" data-test="email-password-reset-link-button">
                {{ __('إرسال رابط الاستعادة') }}
            </flux:button>
        </form>

        <div class="text-sm text-center text-zinc-600 dark:text-zinc-400 pt-4 border-t border-zinc-100 dark:border-zinc-800">
            <span>{{ __('أو، عد إلى') }}</span>
            <flux:link :href="route('home')" wire:navigate class="font-bold text-maroon dark:text-red-secondary">
                {{ __('الرئيسية') }}
            </flux:link>
        </div>
    </div>
</x-layouts::auth>
