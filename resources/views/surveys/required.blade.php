<x-layouts.auth.split title="استبانة مطلوبة">
    <div class="flex items-center justify-center" dir="rtl">
        <div class="w-full max-w-lg bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-200 dark:border-zinc-800 shadow-sm p-8 text-center space-y-6">

            <div class="inline-flex p-4 rounded-full bg-maroon/10 dark:bg-white/10">
                <flux:icon icon="clipboard-document-list" class="size-10 text-maroon dark:text-white" />
            </div>

            <div class="space-y-2">
                <flux:heading size="xl">قبل المتابعة</flux:heading>
                <flux:subheading>
                    مطلوب منك تعبئة {{ $assignments->count() > 1 ? 'الاستبانات التالية' : 'الاستبانة التالية' }}
                    للمتابعة إلى صفحاتك.
                </flux:subheading>
            </div>

            <div class="space-y-3 text-right">
                @foreach($assignments as $assignment)
                    <a href="{{ route('forms.submit', $assignment->form->slug) }}"
                        class="flex items-center justify-between gap-3 p-4 rounded-xl border border-zinc-200 dark:border-zinc-700 hover:border-maroon dark:hover:border-white hover:bg-zinc-50 dark:hover:bg-zinc-800/60 transition-colors group">
                        <div class="min-w-0">
                            <div class="font-bold text-zinc-900 dark:text-white truncate">{{ $assignment->form->title }}</div>
                            @if($assignment->form->description)
                                <div class="text-xs text-zinc-500 dark:text-zinc-400 truncate mt-0.5">{{ $assignment->form->description }}</div>
                            @endif
                            @if($assignment->due_date)
                                <div class="text-xs mt-1 {{ $assignment->isOverdue() ? 'text-rose-600 dark:text-rose-400' : 'text-zinc-400' }}">
                                    الموعد: <x-hijri-date :date="$assignment->due_date" />
                                </div>
                            @endif
                        </div>
                        <flux:icon icon="chevron-left" class="size-5 text-zinc-300 group-hover:text-maroon dark:group-hover:text-white shrink-0" />
                    </a>
                @endforeach
            </div>

            <p class="text-xs text-zinc-400 dark:text-zinc-500">
                تُفتح صفحاتك مباشرة بمجرد إتمامها.
            </p>

            {{-- The one way out from behind the gate, so nobody is ever truly stuck. --}}
            <form method="POST" action="{{ route('logout') }}" class="pt-2 border-t border-zinc-100 dark:border-zinc-800">
                @csrf
                <button type="submit" class="text-xs text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300">
                    تسجيل الخروج
                </button>
            </form>
        </div>
    </div>
</x-layouts.auth.split>
