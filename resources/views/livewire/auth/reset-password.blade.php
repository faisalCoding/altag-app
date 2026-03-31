<x-layouts::auth :title="__('إعادة تعيين كلمة المرور')">
    <div class="flex flex-col gap-6" dir="rtl">
        <div class="flex flex-col items-center gap-2 text-center">
            <h1 class="text-2xl font-bold text-maroon dark:text-red-secondary">تعيين كلمة مرور جديدة</h1>
            <p class="text-sm text-neutral-grey dark:text-zinc-400">يرجى إدخال كلمة المرور الجديدة أدناه لتحديث حسابك.</p>
        </div>

        <!-- Session Status -->
        <x-auth-session-status class="text-center" :status="session('status')" />

        <form method="POST" action="{{ route('password.update') }}" class="flex flex-col gap-6">
            @csrf
            <!-- Token -->
            <input type="hidden" name="token" value="{{ request()->route('token') }}">

            <!-- Email Address -->
            <flux:input name="email" value="{{ request('email') }}" :label="__('البريد الإلكتروني')" type="email" required
                autocomplete="email" readonly />

            <!-- Password -->
            <flux:input name="password" :label="__('كلمة المرور الجديدة')" type="password" required autocomplete="new-password"
                :placeholder="__('كلمة المرور الجديدة')" viewable />

            <!-- Confirm Password -->
            <flux:input name="password_confirmation" :label="__('تأكيد كلمة المرور')" type="password" required
                autocomplete="new-password" :placeholder="__('تأكيد كلمة المرور')" viewable />

            <flux:button type="submit" variant="primary" class="w-full h-11 text-lg font-bold bg-maroon hover:bg-burgundy dark:bg-red-secondary dark:hover:bg-maroon transition-colors" data-test="reset-password-button">
                {{ __('حفظ كلمة المرور') }}
            </flux:button>
        </form>
    </div>
</x-layouts::auth>
