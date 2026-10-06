@props(['student'])

@php
    // Each hidden on its own when the student's stage has switched it off, as
    // the student's own pages do — and the whole card when both are.
    $hadithPlansProgress = $student->memorisesHadith()
        ? \App\Services\MutunProgressService::hadithPlansProgress($student)
        : collect();
    $odePlansProgress = $student->memorisesOdes()
        ? \App\Services\MutunProgressService::odePlansProgress($student)
        : collect();
@endphp

@if($hadithPlansProgress->isNotEmpty() || $odePlansProgress->isNotEmpty())
    <div class="rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-800 p-5">
        <h2 class="font-semibold text-neutral-900 dark:text-white mb-4 flex items-center gap-2">
            <flux:icon icon="document-text" class="size-5 text-rose-500" />
            المتون والمنظومات
        </h2>

        <div class="grid md:grid-cols-2 gap-4">

            {{-- Hadith (mutun) plans --}}
            @foreach($hadithPlansProgress as $item)
                @php
                    $plan = $item['plan'];
                    $completedHadithIds = $item['completedHadithIds'];
                    $completedLines = $item['completedLines'];
                    $pct = $item['totalCount'] > 0 ? (int) round($item['completedCount'] / $item['totalCount'] * 100) : 0;
                @endphp
                <div x-data="{ showTextModal: false }"
                    class="rounded-xl border border-neutral-200 dark:border-neutral-700 p-4 flex flex-col gap-3">
                    <div class="flex items-start justify-between gap-2">
                        <div>
                            <p class="font-medium text-neutral-900 dark:text-white">
                                {{ $plan->path->name ?? 'مسار بدون عنوان' }}</p>
                            <p class="text-xs text-neutral-500 mt-0.5">{{ $plan->path?->text?->name ?? '' }}</p>
                        </div>
                        <span
                            class="shrink-0 px-2 py-0.5 rounded-full text-[11px] font-medium bg-rose-100 text-rose-700 dark:bg-rose-500/20 dark:text-rose-300">
                            متن حديث
                        </span>
                    </div>

                    <div>
                        <div class="flex justify-between text-xs text-neutral-400 mb-1">
                            <span>الأحاديث المحفوظة</span>
                            <span>{{ $item['completedCount'] }} / {{ $item['totalCount'] }}</span>
                        </div>
                        <div class="w-full bg-neutral-200 dark:bg-neutral-700 rounded-full h-2 overflow-hidden">
                            <div class="h-2 rounded-full bg-gradient-to-r from-rose-400 to-rose-600"
                                style="width: {{ $pct }}%"></div>
                        </div>
                    </div>

                    <button type="button" @click="showTextModal = true"
                        class="mt-auto w-full inline-flex items-center justify-center gap-2 px-3 py-2 rounded-lg border border-neutral-200 dark:border-neutral-700 text-sm text-neutral-600 dark:text-neutral-300 hover:bg-neutral-50 dark:hover:bg-neutral-700">
                        <flux:icon icon="book-open" class="size-4" />
                        إظهار متن الحديث
                    </button>

                    {{-- Hadith text modal with completed hadiths highlighted --}}
                    <template x-teleport="body">
                        <div x-show="showTextModal" x-transition:enter="transition ease-out duration-300"
                            x-transition:enter-start="opacity-0 translate-y-4"
                            x-transition:enter-end="opacity-100 translate-y-0"
                            x-transition:leave="transition ease-in duration-200"
                            x-transition:leave-start="opacity-100 translate-y-0"
                            x-transition:leave-end="opacity-0 translate-y-4"
                            class="fixed inset-0 z-50 bg-white dark:bg-zinc-900 flex flex-col w-full h-full text-zinc-900 dark:text-white"
                            dir="rtl" x-cloak>

                            <div
                                class="flex items-center justify-between p-5 border-b border-zinc-100 dark:border-zinc-800 bg-zinc-50/50 dark:bg-zinc-900/50 shrink-0">
                                <div>
                                    <h3 class="font-bold text-lg leading-tight">
                                        {{ $plan->path?->text?->name ?? 'متن الحديث' }}</h3>
                                    <p class="text-xs text-rose-600 dark:text-rose-400 mt-1 font-semibold">
                                        {{ $plan->path->name ?? '' }} — حُفظ {{ $item['completedCount'] }} من
                                        {{ $item['totalCount'] }} حديثاً
                                    </p>
                                </div>
                                <button type="button" @click="showTextModal = false"
                                    class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200 p-1.5 rounded-lg hover:bg-zinc-100 dark:hover:bg-zinc-800">
                                    <flux:icon icon="x-mark" class="size-5" />
                                </button>
                            </div>

                            <div class="flex-1 overflow-y-auto p-4 md:p-6 space-y-8 bg-zinc-50/30 dark:bg-zinc-950/30 text-right">
                                <div class="flex items-center gap-4 text-[11px] text-zinc-500">
                                    <span class="flex items-center gap-1.5"><span
                                            class="size-3 rounded bg-emerald-500"></span> أنجزه الطالب</span>
                                    <span class="flex items-center gap-1.5"><span
                                            class="size-3 rounded bg-zinc-200 dark:bg-zinc-700"></span> لم يُحفظ بعد</span>
                                </div>

                                @foreach($item['hadiths'] as $hadith)
                                    @php
                                        $isHadithCompleted = in_array($hadith->id, $completedHadithIds);
                                        $hadithCompletedLines = $completedLines[$hadith->id] ?? [];
                                    @endphp
                                    <div class="space-y-4">
                                        <div
                                            class="flex items-center gap-2 text-lg font-bold pb-2 border-b font-serif {{ $isHadithCompleted ? 'text-emerald-600 dark:text-emerald-400 border-emerald-100 dark:border-emerald-900/50' : 'text-zinc-700 dark:text-zinc-300 border-zinc-200 dark:border-zinc-800' }}">
                                            {{ $hadith->name }}
                                            @if($isHadithCompleted)
                                                <span
                                                    class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-sans font-medium bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300">
                                                    <flux:icon icon="check-circle" class="size-3.5" variant="solid" />
                                                    تم حفظه
                                                </span>
                                            @endif
                                        </div>

                                        @if ($hadith->sanad)
                                            <div
                                                class="p-4 bg-zinc-100 dark:bg-zinc-800 rounded-xl text-sm font-semibold text-zinc-600 dark:text-zinc-400 pr-4 border-r-4 border-zinc-400 font-serif">
                                                <strong>السند: </strong>{{ $hadith->sanad }}
                                            </div>
                                        @endif

                                        @foreach($hadith->lines as $line)
                                            @php
                                                $isLineCompleted = $isHadithCompleted || in_array($line->line_number, $hadithCompletedLines);
                                            @endphp
                                            <div
                                                class="flex items-start gap-4 p-4 md:p-5 bg-white dark:bg-zinc-900 rounded-2xl border shadow-sm {{ $isLineCompleted ? 'border-emerald-200 dark:border-emerald-900/60' : 'border-zinc-100 dark:border-zinc-800/60' }}">
                                                <span
                                                    class="shrink-0 flex items-center justify-center size-8 rounded-xl font-extrabold text-sm shadow-sm {{ $isLineCompleted ? 'bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-300' : 'bg-zinc-100 dark:bg-zinc-800 text-zinc-400 dark:text-zinc-500' }}">
                                                    {{ $line->line_number }}
                                                </span>
                                                <div
                                                    class="flex-1 text-base md:text-xl font-semibold leading-relaxed text-right pr-4 border-r-4 font-serif {{ $isLineCompleted ? 'text-zinc-800 dark:text-zinc-100 border-emerald-500 dark:border-emerald-400' : 'text-zinc-500 dark:text-zinc-400 border-zinc-300 dark:border-zinc-600' }}">
                                                    {{ $line->text }}
                                                </div>
                                            </div>
                                        @endforeach

                                        @if ($hadith->ruling)
                                            <div
                                                class="p-4 bg-zinc-50 dark:bg-zinc-900 rounded-xl text-sm font-bold text-zinc-500 dark:text-zinc-400 pr-4 border-r-4 border-zinc-300">
                                                <strong>حكم الحديث: </strong>{{ $hadith->ruling }}
                                            </div>
                                        @endif
                                    </div>
                                @endforeach
                            </div>

                            <div
                                class="p-4 border-t border-zinc-100 dark:border-zinc-800 bg-zinc-50/50 dark:bg-zinc-900/50 flex justify-end shrink-0">
                                <flux:button type="button" @click="showTextModal = false" variant="ghost"
                                    class="text-zinc-700 dark:text-zinc-300">
                                    إغلاق
                                </flux:button>
                            </div>
                        </div>
                    </template>
                </div>
            @endforeach

            {{-- Ode plans --}}
            @foreach($odePlansProgress as $item)
                @php
                    $plan = $item['plan'];
                    $completedVerseNumbers = $item['completedVerseNumbers'];
                    $pct = $item['totalCount'] > 0 ? (int) round($item['completedCount'] / $item['totalCount'] * 100) : 0;
                @endphp
                <div x-data="{ showOdeModal: false }"
                    class="rounded-xl border border-neutral-200 dark:border-neutral-700 p-4 flex flex-col gap-3">
                    <div class="flex items-start justify-between gap-2">
                        <div>
                            <p class="font-medium text-neutral-900 dark:text-white">
                                {{ $plan->path->name ?? 'مسار بدون عنوان' }}</p>
                            <p class="text-xs text-neutral-500 mt-0.5">{{ $plan->path?->ode?->name ?? '' }}</p>
                        </div>
                        <span
                            class="shrink-0 px-2 py-0.5 rounded-full text-[11px] font-medium bg-indigo-100 text-indigo-700 dark:bg-indigo-500/20 dark:text-indigo-300">
                            منظومة
                        </span>
                    </div>

                    <div>
                        <div class="flex justify-between text-xs text-neutral-400 mb-1">
                            <span>الأبيات المحفوظة</span>
                            <span>{{ $item['completedCount'] }} / {{ $item['totalCount'] }}</span>
                        </div>
                        <div class="w-full bg-neutral-200 dark:bg-neutral-700 rounded-full h-2 overflow-hidden">
                            <div class="h-2 rounded-full bg-gradient-to-r from-indigo-400 to-indigo-600"
                                style="width: {{ $pct }}%"></div>
                        </div>
                    </div>

                    <button type="button" @click="showOdeModal = true"
                        class="mt-auto w-full inline-flex items-center justify-center gap-2 px-3 py-2 rounded-lg border border-neutral-200 dark:border-neutral-700 text-sm text-neutral-600 dark:text-neutral-300 hover:bg-neutral-50 dark:hover:bg-neutral-700">
                        <flux:icon icon="book-open" class="size-4" />
                        إظهار المنظومة
                    </button>

                    {{-- Ode verses modal with completed verses highlighted --}}
                    <template x-teleport="body">
                        <div x-show="showOdeModal" x-transition:enter="transition ease-out duration-300"
                            x-transition:enter-start="opacity-0 translate-y-4"
                            x-transition:enter-end="opacity-100 translate-y-0"
                            x-transition:leave="transition ease-in duration-200"
                            x-transition:leave-start="opacity-100 translate-y-0"
                            x-transition:leave-end="opacity-0 translate-y-4"
                            class="fixed inset-0 z-50 bg-white dark:bg-zinc-900 flex flex-col w-full h-full text-zinc-900 dark:text-white"
                            dir="rtl" x-cloak>

                            <div
                                class="flex items-center justify-between p-5 border-b border-zinc-100 dark:border-zinc-800 bg-zinc-50/50 dark:bg-zinc-900/50 shrink-0">
                                <div>
                                    <h3 class="font-bold text-lg leading-tight">{{ $plan->path?->ode?->name ?? 'المنظومة' }}
                                    </h3>
                                    <p class="text-xs text-indigo-600 dark:text-indigo-400 mt-1 font-semibold">
                                        {{ $plan->path->name ?? '' }} — حُفظ {{ $item['completedCount'] }} من
                                        {{ $item['totalCount'] }} بيتاً
                                    </p>
                                </div>
                                <button type="button" @click="showOdeModal = false"
                                    class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200 p-1.5 rounded-lg hover:bg-zinc-100 dark:hover:bg-zinc-800">
                                    <flux:icon icon="x-mark" class="size-5" />
                                </button>
                            </div>

                            <div class="flex-1 overflow-y-auto p-4 md:p-6 space-y-4 bg-zinc-50/30 dark:bg-zinc-950/30 text-right">
                                <div class="flex items-center gap-4 text-[11px] text-zinc-500">
                                    <span class="flex items-center gap-1.5"><span
                                            class="size-3 rounded bg-emerald-500"></span> أنجزه الطالب</span>
                                    <span class="flex items-center gap-1.5"><span
                                            class="size-3 rounded bg-zinc-200 dark:bg-zinc-700"></span> لم يُحفظ بعد</span>
                                </div>

                                @foreach($item['verses'] as $verse)
                                    @php
                                        $isVerseCompleted = in_array($verse->verse_number, $completedVerseNumbers);
                                    @endphp
                                    <div
                                        class="flex items-start gap-4 p-4 md:p-5 bg-white dark:bg-zinc-900 rounded-2xl border shadow-sm {{ $isVerseCompleted ? 'border-emerald-200 dark:border-emerald-900/60' : 'border-zinc-100 dark:border-zinc-800/60' }}">
                                        <span
                                            class="shrink-0 flex items-center justify-center size-8 rounded-xl font-extrabold text-sm shadow-sm {{ $isVerseCompleted ? 'bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-300' : 'bg-zinc-100 dark:bg-zinc-800 text-zinc-400 dark:text-zinc-500' }}">
                                            {{ $verse->verse_number }}
                                        </span>
                                        <div
                                            class="flex-1 flex flex-col md:flex-row md:items-center md:justify-between gap-2 text-base md:text-lg font-semibold leading-relaxed pr-4 border-r-4 font-serif {{ $isVerseCompleted ? 'text-zinc-800 dark:text-zinc-100 border-emerald-500 dark:border-emerald-400' : 'text-zinc-500 dark:text-zinc-400 border-zinc-300 dark:border-zinc-600' }}">
                                            <span>{{ $verse->sadr }}</span>
                                            <span>{{ $verse->ajuz }}</span>
                                        </div>
                                        @if($isVerseCompleted)
                                            <flux:icon icon="check-circle" variant="solid"
                                                class="size-5 text-emerald-500 shrink-0 mt-1" />
                                        @endif
                                    </div>
                                @endforeach
                            </div>

                            <div
                                class="p-4 border-t border-zinc-100 dark:border-zinc-800 bg-zinc-50/50 dark:bg-zinc-900/50 flex justify-end shrink-0">
                                <flux:button type="button" @click="showOdeModal = false" variant="ghost"
                                    class="text-zinc-700 dark:text-zinc-300">
                                    إغلاق
                                </flux:button>
                            </div>
                        </div>
                    </template>
                </div>
            @endforeach
        </div>
    </div>
@endif
