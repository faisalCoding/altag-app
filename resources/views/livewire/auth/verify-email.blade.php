<x-layouts::auth :title="__('تأكيد البريد الإلكتروني')">
    <div class="mt-4 flex flex-col gap-6" dir="rtl">
        <div class="flex flex-col items-center gap-2 text-center">
            <h1 class="text-2xl font-bold text-maroon dark:text-red-secondary">تأكيد البريد</h1>
            <p class="text-sm text-neutral-grey dark:text-zinc-400">
                يرجى تأكيد بريدك الإلكتروني من خلال النقر على الرابط الذي أرسلناه إليك للتو.
            </p>
        </div>

        @if (session('status') == 'verification-link-sent')
            <flux:text class="text-center font-medium text-emerald-600 dark:text-emerald-400">
                {{ __('تم إرسال رابط تأكيد جديد إلى البريد الإلكتروني الذي قدمته.') }}
            </flux:text>
        @endif

        <div class="flex flex-col items-center space-y-4 pt-4 border-t border-zinc-100 dark:border-zinc-800">
            <form method="POST" action="{{ route('verification.send') }}" class="w-full">
                @csrf
                <flux:button type="submit" variant="primary" class="w-full h-11 text-lg font-bold bg-maroon hover:bg-burgundy dark:bg-red-secondary dark:hover:bg-maroon transition-colors">
                    {{ __('إعادة إرسال الرابط') }}
                </flux:button>
            </form>

            <form method="POST" action="{{ route('logout') }}" class="w-full">
                @csrf
                <flux:button variant="ghost" type="submit" class="w-full font-bold cursor-pointer" data-test="logout-button">
                    {{ __('تسجيل الخروج') }}
                </flux:button>
            </form>
        </div>
    </div>
</x-layouts::auth>
