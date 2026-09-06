<div class="space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div class="flex items-center gap-3">
            <div class="p-2 rounded-lg bg-zinc-50 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">
                <flux:icon icon="bars-arrow-up" />
            </div>
            <div>
                <flux:heading size="xl" class="font-bold text-zinc-900 dark:text-white">سلّم الترحيل</flux:heading>
                <flux:subheading>ترتيب المراحل والحلقات الذي يصعده الطالب سنة بعد سنة</flux:subheading>
            </div>
        </div>

        <flux:button wire:click="save" variant="primary" icon="check" class="bg-white text-maroon border-zinc-200 hover:bg-zinc-50 shadow-xs dark:bg-zinc-800 dark:text-white dark:border-zinc-700">
            حفظ الترتيب
        </flux:button>
    </div>

    <div class="rounded-2xl border border-zinc-100 dark:border-zinc-800 bg-zinc-50/60 dark:bg-zinc-900/40 p-4 text-sm text-zinc-600 dark:text-zinc-300 leading-relaxed">
        الرتبة رقم لا اسم. <strong>حلقتان برتبة واحدة شعبتان لصفٍّ واحد</strong> — لا صفّان متتاليان — فلن يُنقل طالب من شعبة إلى أخرى.
        واترك الخانة فارغة لتُخرج الحلقة من السلّم فلا يُرحَّل طلابها تلقائياً.
    </div>

    @if ($warnings->isNotEmpty())
        <div class="space-y-2">
            @foreach ($warnings as $warning)
                <div class="flex items-start gap-2 rounded-xl border px-4 py-3 text-sm
                    {{ $warning['level'] === 'danger'
                        ? 'border-red-200 bg-red-50 text-red-700 dark:border-red-900/50 dark:bg-red-950/30 dark:text-red-300'
                        : 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-900/50 dark:bg-amber-950/30 dark:text-amber-300' }}">
                    <flux:icon icon="exclamation-triangle" class="size-4 mt-0.5 shrink-0" />
                    <span>{{ $warning['text'] }}</span>
                </div>
            @endforeach
        </div>
    @endif

    {{-- ─────────── ضبط الرتب ─────────── --}}
    <div class="space-y-4">
        @foreach ($stages as $stage)
            <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 shadow-xs overflow-hidden">
                <div class="flex items-center justify-between gap-4 px-4 py-3 border-b border-zinc-100 dark:border-zinc-800 bg-zinc-50/70 dark:bg-zinc-800/40">
                    <div class="flex items-center gap-3 min-w-0">
                        <div class="w-16 shrink-0">
                            <flux:input type="number" min="1" max="99" size="sm"
                                wire:model="stageLevels.{{ $stage->id }}" placeholder="—" />
                        </div>
                        <div class="min-w-0">
                            <div class="font-bold text-zinc-900 dark:text-white truncate">{{ $stage->name }}</div>
                            <div class="text-xs text-zinc-400">{{ $stage->circles->count() }} حلقة</div>
                        </div>
                    </div>

                    @if ($stage->level === null)
                        <flux:badge size="sm" color="zinc">خارج السلّم</flux:badge>
                    @endif
                </div>

                <div class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @forelse ($stage->circles as $circle)
                        @php $next = $ladder->nextCircleFor($circle); @endphp
                        <div class="flex items-center gap-3 px-4 py-2.5">
                            <div class="w-16 shrink-0">
                                <flux:input type="number" min="1" max="99" size="sm"
                                    wire:model="circleLevels.{{ $circle->id }}" placeholder="—" />
                            </div>

                            <div class="min-w-0 flex-1">
                                <div class="text-sm font-medium text-zinc-800 dark:text-zinc-100 truncate">{{ $circle->name }}</div>
                                <div class="text-xs text-zinc-400">{{ $circle->students_count }} طالب</div>
                            </div>

                            <div class="text-xs text-zinc-500 dark:text-zinc-400 text-left shrink-0 max-w-36 truncate">
                                @if ($circle->level === null)
                                    <span class="text-zinc-400">لا يُرحَّل</span>
                                @elseif ($next)
                                    <span class="inline-flex items-center gap-1">
                                        <flux:icon icon="arrow-left" class="size-3" />
                                        {{ $next->name }}
                                    </span>
                                @else
                                    <flux:badge size="sm" color="emerald">تخرّج</flux:badge>
                                @endif
                            </div>
                        </div>
                    @empty
                        <div class="px-4 py-3 text-sm text-zinc-400">لا حلقات في هذه المرحلة</div>
                    @endforelse
                </div>
            </div>
        @endforeach
    </div>

    {{-- ─────────── السلّم كما سيُقرأ ─────────── --}}
    <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 shadow-xs p-5">
        <flux:heading size="lg" class="mb-1">السلّم بالترتيب</flux:heading>
        <flux:subheading class="mb-4">هذه هي الدرجات التي سيصعدها الطلاب، بعد آخر حفظ</flux:subheading>

        @forelse ($rungs as $index => $rung)
            <div class="flex items-start gap-3 py-2 {{ $index > 0 ? 'border-t border-zinc-50 dark:border-zinc-800/60' : '' }}">
                <span class="mt-0.5 size-6 shrink-0 rounded-full bg-maroon/10 text-maroon dark:bg-white/10 dark:text-white flex items-center justify-center text-[11px] font-bold">
                    {{ $index + 1 }}
                </span>
                <div class="min-w-0">
                    <div class="text-sm font-medium text-zinc-800 dark:text-zinc-100">
                        {{ $rung['circles']->pluck('name')->implode('  ·  ') }}
                    </div>
                    <div class="text-xs text-zinc-400">
                        {{ $rung['stage']->name }}
                        @if ($rung['circles']->count() > 1)
                            — {{ $rung['circles']->count() }} شُعَب برتبة واحدة
                        @endif
                        · {{ $rung['circles']->sum('students_count') }} طالب
                    </div>
                </div>
            </div>
        @empty
            <div class="text-sm text-zinc-400">لم تُضبط أي رتبة بعد.</div>
        @endforelse
    </div>
</div>
