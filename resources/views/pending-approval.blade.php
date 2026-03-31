<x-layouts::auth :title="__('في انتظار الموافقة')">
    <div class="flex flex-col items-center gap-6 text-center" dir="rtl">
        <div class="bg-maroon/10 p-4 rounded-full">
            <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" class="text-maroon dark:text-red-secondary"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        </div>

        <div class="flex flex-col gap-2">
            <h1 class="text-2xl font-bold text-maroon dark:text-red-secondary">في انتظار الموافقة</h1>
            <p class="text-zinc-600 dark:text-zinc-400">
                تم تسجيل حسابك بنجاح في <span class="font-bold">مجمع التاج القرآني</span>. سيتم مراجعة بياناتك والموافقة عليها من قِبل المشرف المختص قريباً لتمكينك من الدخول.
            </p>
        </div>

        <div class="w-full pt-6 border-t border-zinc-100 dark:border-zinc-800">
            <form method="POST" action="{{ route('logout') }}" class="w-full">
                @csrf
                <flux:button type="submit" variant="ghost" class="w-full font-bold">
                    تسجيل الخروج
                </flux:button>
            </form>
        </div>
    </div>
</x-layouts::auth>
