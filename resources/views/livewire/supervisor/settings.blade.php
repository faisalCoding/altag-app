<div class="space-y-6">
    <div class="flex items-center gap-3">
        <div class="p-2 rounded-lg bg-zinc-50 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">
            <flux:icon icon="adjustments-horizontal" />
        </div>
        <div>
            <flux:heading size="xl" class="font-bold text-zinc-900 dark:text-white">إعدادات المراحل</flux:heading>
            <flux:subheading>قواعد تسري على حلقات مراحلك ومعلميها</flux:subheading>
        </div>
    </div>

    <div class="rounded-2xl border border-zinc-100 dark:border-zinc-800 bg-zinc-50/60 dark:bg-zinc-900/40 p-4 text-sm text-zinc-600 dark:text-zinc-300 leading-relaxed">
        حين يعدّل المعلم تحضير يوم غير يوم الجلسة، يُطلب منه ذكر السبب ويُحفظ في سجل التعديلات.
        <strong>إلغاء الإلزام يوقف الطلب فقط</strong> — ويظل كل تعديل مسجّلاً بمن قام به ومتى.
    </div>

    <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 shadow-xs overflow-hidden">
        <div class="divide-y divide-zinc-100 dark:divide-zinc-800">
            @forelse ($stages as $stage)
                <div wire:key="stage-setting-{{ $stage->id }}"
                    class="flex items-center justify-between gap-4 px-4 py-3.5">
                    <div class="min-w-0">
                        <div class="text-sm font-bold text-zinc-800 dark:text-zinc-100 truncate">{{ $stage->name }}</div>
                        <div class="text-xs text-zinc-400">
                            @if ($requireEditReason[$stage->id] ?? true)
                                المعلم مُلزَم بذكر سبب التعديل خارج يوم الجلسة
                            @else
                                المعلم يعدّل خارج يوم الجلسة دون ذكر سبب
                            @endif
                        </div>
                    </div>

                    <flux:switch wire:click="toggleReason({{ $stage->id }})"
                        :checked="$requireEditReason[$stage->id] ?? true" class="shrink-0" />
                </div>
            @empty
                <div class="p-16 text-center">
                    <flux:icon icon="rectangle-stack" class="size-12 mx-auto text-zinc-300 dark:text-zinc-600 mb-4" />
                    <flux:heading size="lg" class="text-zinc-500 dark:text-zinc-400">لا مراحل تحت إشرافك</flux:heading>
                </div>
            @endforelse
        </div>
    </div>

    @if ($stages->isNotEmpty())
        {{-- ─────────── المتون والمنظومات ─────────── --}}
        <div class="flex items-center gap-3 pt-2">
            <div class="p-2 rounded-lg bg-zinc-50 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">
                <flux:icon icon="book-open" />
            </div>
            <div>
                <flux:heading size="lg" class="font-bold text-zinc-900 dark:text-white">المتون والمنظومات</flux:heading>
                <flux:subheading>ما لا تحفظه المرحلة يختفي من تسميع المعلم ومن صفحات الطالب</flux:subheading>
            </div>
        </div>

        <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 shadow-xs overflow-hidden">
            <div class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @foreach ($stages as $stage)
                    <div wire:key="stage-memorisation-{{ $stage->id }}"
                        class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 px-4 py-3.5">
                        <div class="text-sm font-bold text-zinc-800 dark:text-zinc-100 truncate">{{ $stage->name }}</div>

                        <div class="flex items-center gap-6 shrink-0">
                            <label class="flex items-center gap-2 cursor-pointer">
                                <span class="text-sm text-zinc-600 dark:text-zinc-300">المتون</span>
                                <flux:switch wire:click="toggleHadith({{ $stage->id }})"
                                    :checked="$hadithEnabled[$stage->id] ?? true"
                                    aria-label="المتون في {{ $stage->name }}" />
                            </label>
                            <label class="flex items-center gap-2 cursor-pointer">
                                <span class="text-sm text-zinc-600 dark:text-zinc-300">المنظومات</span>
                                <flux:switch wire:click="toggleOdes({{ $stage->id }})"
                                    :checked="$odesEnabled[$stage->id] ?? true"
                                    aria-label="المنظومات في {{ $stage->name }}" />
                            </label>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        <p class="text-xs text-zinc-500 dark:text-zinc-400 leading-relaxed">
            الإخفاء لا يحذف شيئاً: الخطط والتقييمات المسجّلة تبقى، وتعود كما كانت حين تُفعِّلها من جديد.
        </p>

        <div class="flex items-center gap-3 pt-2">
            <div class="p-2 rounded-lg bg-zinc-50 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">
                <flux:icon icon="chat-bubble-left-right" />
            </div>
            <div>
                <flux:heading size="lg" class="font-bold text-zinc-900 dark:text-white">مجموعة الواتساب لرسالة الغياب</flux:heading>
                <flux:subheading>حين ينسخ المعلم رسالة الغياب تُفتح هذه المجموعة ليلصقها فيها</flux:subheading>
            </div>
        </div>

        <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 shadow-xs overflow-hidden">
            <div class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @foreach ($stages as $stage)
                    <form wire:key="stage-whatsapp-{{ $stage->id }}" wire:submit="saveWhatsappGroupUrl({{ $stage->id }})"
                        class="px-4 py-3.5 space-y-2">
                        <div class="text-sm font-bold text-zinc-800 dark:text-zinc-100 truncate">{{ $stage->name }}</div>
                        <div class="flex items-start gap-2">
                            <div class="flex-1 min-w-0">
                                <flux:input wire:model="whatsappGroupUrls.{{ $stage->id }}" dir="ltr"
                                    placeholder="https://chat.whatsapp.com/..." aria-label="رابط مجموعة {{ $stage->name }}" />
                            </div>
                            <flux:button type="submit" variant="primary" class="shrink-0">حفظ</flux:button>
                        </div>
                        <flux:error name="whatsappGroupUrls.{{ $stage->id }}" />
                    </form>
                @endforeach
            </div>
        </div>

        <p class="text-xs text-zinc-500 dark:text-zinc-400 leading-relaxed">
            اترك الحقل فارغاً لتُنسخ الرسالة فقط. ولحلقة بعينها مجموعة خاصة بها تُضاف من صفحة الحلقات، فتُفتح بدل مجموعة المرحلة.
        </p>
    @endif
</div>
