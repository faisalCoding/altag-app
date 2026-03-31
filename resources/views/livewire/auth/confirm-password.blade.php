<x-layouts::auth :title="__('تأكيد كلمة المرور')">
    <div class="flex flex-col gap-6" dir="rtl">
        <div class="flex flex-col items-center gap-2 text-center">
            <h1 class="text-2xl font-bold text-maroon dark:text-red-secondary">تأكيد الهوية</h1>
            <p class="text-sm text-neutral-grey dark:text-zinc-400">هذه منطقة آمنة. يرجى تأكيد كلمة المرور الخاصة بك للمتابعة.</p>
        </div>

        <x-auth-session-status class="text-center" :status="session('status')" />

        <form method="POST" action="{{ route('password.confirm.store') }}" class="flex flex-col gap-6">
            @csrf

            <flux:input
                name="password"
                :label="__('كلمة المرور')"
                type="password"
                required
                autocomplete="current-password"
                :placeholder="__('أدخل كلمة المرور')"
                viewable
            />

            <flux:button variant="primary" type="submit" class="w-full h-11 text-lg font-bold bg-maroon hover:bg-burgundy dark:bg-red-secondary dark:hover:bg-maroon transition-colors" data-test="confirm-password-button">
                {{ __('تأكيد') }}
            </flux:button>
        </form>
    </div>
</x-layouts::auth>
