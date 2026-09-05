<div class="space-y-6" x-data="{ activeTab: @entangle('activeTab') }">
    <!-- Header -->
    <div class="flex items-center justify-between">
        <div>
            <flux:breadcrumbs>
                <flux:breadcrumbs.item :href="route('supervisor.forms')">النماذج والاستمارات</flux:breadcrumbs.item>
                <flux:breadcrumbs.item>الردود والإحصائيات</flux:breadcrumbs.item>
            </flux:breadcrumbs>
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-white mt-2">{{ $form->title }}</h1>
            <p class="text-sm text-zinc-500 dark:text-zinc-400 mt-1">عرض وتصفية الردود الواردة، وبناء التقارير البيانية وربط البيانات بالطلاب.</p>
        </div>
        <div class="flex gap-2">
            <flux:button as="a" :href="route('supervisor.forms')" variant="ghost" icon="chevron-right">العودة للنماذج</flux:button>
            @if($form->is_public_report)
                <flux:button size="sm" variant="ghost" icon="share" class="text-xs text-indigo-500 hover:text-indigo-600 hover:bg-indigo-50 dark:hover:bg-indigo-950/20 border border-zinc-200 dark:border-zinc-800" 
                    x-data="{ copied: false }"
                    x-on:click="
                        navigator.clipboard.writeText('{{ route('forms.report', [$form->slug, $form->public_report_token]) }}');
                        copied = true;
                        setTimeout(() => copied = false, 2000);
                        $dispatch('toast', { message: 'تم نسخ رابط التقرير العام بنجاح', variant: 'success' })
                    "
                    ::title="copied ? 'تم النسخ!' : 'نسخ رابط التقرير العام'"
                >
                    رابط التقرير العام
                </flux:button>
            @endif
            <flux:button wire:click="exportExcel" variant="ghost" icon="arrow-down-tray" class="text-emerald-600 hover:text-emerald-700 hover:bg-emerald-50 dark:hover:bg-emerald-950/20 border border-zinc-200 dark:border-zinc-800">
                تحميل Excel
            </flux:button>
            <flux:button wire:click="openBulkModal(false)" variant="primary" icon="users" class="bg-accent hover:bg-accent/90 text-white border-0">
                إضافة كافة الردود كطلاب
            </flux:button>
        </div>
    </div>

    <!-- Tabs Navigation -->
    <div class="flex border-b border-zinc-200 dark:border-zinc-800">
        <button @click="activeTab = 'responses'" :class="activeTab === 'responses' ? 'border-accent text-accent' : 'border-transparent text-zinc-500 hover:text-zinc-700'" class="py-3 px-6 font-semibold text-sm border-b-2 -mb-px transition-colors">
            جدول الردود
        </button>
        <button @click="activeTab = 'reports'" :class="activeTab === 'reports' ? 'border-accent text-accent' : 'border-transparent text-zinc-500 hover:text-zinc-700'" class="py-3 px-6 font-semibold text-sm border-b-2 -mb-px transition-colors">
            التقرير البياني والتحليلي
        </button>
    </div>
    <!-- Responses Tab -->
    <div x-show="activeTab === 'responses'" class="space-y-4">
    {{-- What the answers say, before the table of what each person said. --}}
    @if($completion['assigned'] > 0 || collect($summaries)->sum('answered') > 0)
        <div class="bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-800 p-5 space-y-5 mb-6" dir="rtl">
            <div class="flex items-center justify-between flex-wrap gap-3">
                <h2 class="text-lg font-bold text-zinc-900 dark:text-white">خلاصة النتائج</h2>
                @if($completion['assigned'] > 0)
                    <div class="flex items-center gap-2 text-sm">
                        <span class="text-zinc-500">نسبة الاستجابة</span>
                        <flux:badge size="sm" :color="$completion['rate'] >= 70 ? 'emerald' : ($completion['rate'] >= 40 ? 'amber' : 'rose')">
                            {{ $completion['rate'] }}%
                        </flux:badge>
                        <span class="text-xs text-zinc-400 tabular-nums">
                            {{ $completion['completed'] }} من {{ $completion['assigned'] }}
                        </span>
                    </div>
                @endif
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                @foreach($summaries as $summary)
                    @continue($summary['answered'] === 0)
                    <div class="rounded-lg border border-zinc-100 dark:border-zinc-800 p-4 space-y-3">
                        <div class="flex items-start justify-between gap-3">
                            <span class="text-sm font-bold text-zinc-800 dark:text-zinc-100">{{ $summary['label'] }}</span>
                            <span class="text-[11px] text-zinc-400 whitespace-nowrap tabular-nums">{{ $summary['answered'] }} إجابة</span>
                        </div>

                        @if($summary['kind'] === 'scale')
                            <div class="flex items-baseline gap-2">
                                <span class="text-3xl font-black text-accent tabular-nums">{{ $summary['average'] ?? '—' }}</span>
                                <span class="text-sm text-zinc-400">/ {{ $summary['max'] }}</span>
                                @if($summary['positive_rate'] !== null)
                                    <span class="text-xs text-emerald-600 dark:text-emerald-400 mr-auto">
                                        {{ $summary['positive_rate'] }}% إيجابي
                                    </span>
                                @endif
                            </div>
                            <div class="space-y-1.5">
                                @foreach(array_reverse($summary['counts'], true) as $value => $count)
                                    @php $share = $summary['total'] > 0 ? round($count / $summary['total'] * 100) : 0; @endphp
                                    <div class="flex items-center gap-2">
                                        <span class="text-[11px] text-zinc-500 w-24 shrink-0 truncate">
                                            {{ $summary['labels'][$value] ?? $value }}
                                        </span>
                                        <div class="flex-1 h-2.5 rounded-full bg-zinc-100 dark:bg-zinc-800 overflow-hidden">
                                            <div class="h-full rounded-full bg-accent" style="width: {{ $share }}%"></div>
                                        </div>
                                        <span class="text-[11px] text-zinc-400 w-10 text-left tabular-nums">{{ $count }}</span>
                                    </div>
                                @endforeach
                            </div>

                        @elseif($summary['kind'] === 'choice')
                            <div class="space-y-1.5">
                                @foreach($summary['counts'] as $choice => $count)
                                    @continue($count === 0)
                                    <div class="flex items-center gap-2">
                                        <span class="text-[11px] text-zinc-500 w-28 shrink-0 truncate" title="{{ $choice }}">{{ $choice }}</span>
                                        <div class="flex-1 h-2.5 rounded-full bg-zinc-100 dark:bg-zinc-800 overflow-hidden">
                                            <div class="h-full rounded-full bg-accent" style="width: {{ $summary['shares'][$choice] ?? 0 }}%"></div>
                                        </div>
                                        <span class="text-[11px] text-zinc-400 w-16 text-left tabular-nums">
                                            {{ $count }} · {{ $summary['shares'][$choice] ?? 0 }}%
                                        </span>
                                    </div>
                                @endforeach
                            </div>

                        @elseif($summary['kind'] === 'text')
                            <div class="space-y-1.5 max-h-40 overflow-auto">
                                @foreach($summary['samples'] as $sample)
                                    <p class="text-xs text-zinc-600 dark:text-zinc-300 bg-zinc-50 dark:bg-zinc-800/60 rounded-lg px-2.5 py-1.5">
                                        {{ is_array($sample) ? implode('، ', $sample) : $sample }}
                                    </p>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @endif

        <!-- Search and Filters bar -->
        <div class="grid grid-cols-1 md:grid-cols-12 gap-4 bg-white dark:bg-zinc-900 p-4 rounded-xl border border-zinc-200 dark:border-zinc-800">
            <div class="md:col-span-4">
                <flux:input wire:model.live.debounce.300ms="search" placeholder="البحث في الردود والإجابات..." icon="magnifying-glass" />
            </div>
            <!-- Stage Filter -->
            <div x-data="{ open: false }" class="relative md:col-span-2" @click.away="open = false">
                <button type="button" @click="open = !open" class="flex items-center justify-between w-full rounded-lg border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 px-3 py-2 text-sm text-zinc-700 dark:text-zinc-300 font-medium hover:bg-zinc-50 dark:hover:bg-zinc-950 transition-colors">
                    <span class="truncate">
                        @if(count($filterStageIds) === 0)
                            كل المراحل الدراسية
                        @elseif(count($filterStageIds) === 1)
                            المرحلة: {{ $stages->firstWhere('id', $filterStageIds[0])?->name }}
                        @else
                            المراحل: {{ count($filterStageIds) }} محددة
                        @endif
                    </span>
                    <flux:icon name="chevron-down" class="size-3.5 text-zinc-400 shrink-0 ms-2" />
                </button>
                <div x-show="open" class="absolute z-50 mt-1 w-full rounded-lg border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-2 shadow-lg space-y-1 max-h-48 overflow-y-auto" style="display: none;">
                    @foreach($filterStages as $stage)
                        <label class="flex items-center gap-2 px-2 py-1.5 rounded-md hover:bg-zinc-50 dark:hover:bg-zinc-950 cursor-pointer text-sm text-zinc-700 dark:text-zinc-300">
                            <input type="checkbox" value="{{ $stage->id }}" wire:model.live="filterStageIds" class="rounded text-accent focus:ring-accent" />
                            <span>{{ $stage->name }}</span>
                        </label>
                    @endforeach
                </div>
            </div>

            <!-- Age Filter -->
            <div x-data="{ open: false }" class="relative md:col-span-2" @click.away="open = false">
                <button type="button" @click="open = !open" class="flex items-center justify-between w-full rounded-lg border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 px-3 py-2 text-sm text-zinc-700 dark:text-zinc-300 font-medium hover:bg-zinc-50 dark:hover:bg-zinc-950 transition-colors">
                    <span class="truncate">
                        @if(count($filterAges) === 0)
                            كل الأعمار
                        @elseif(count($filterAges) === 1)
                            العمر: {{ $filterAges[0] }} سنة
                        @else
                            الأعمار: {{ count($filterAges) }} محددة
                        @endif
                    </span>
                    <flux:icon name="chevron-down" class="size-3.5 text-zinc-400 shrink-0 ms-2" />
                </button>
                <div x-show="open" class="absolute z-50 mt-1 w-full rounded-lg border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-2 shadow-lg space-y-1 max-h-48 overflow-y-auto" style="display: none;">
                    @foreach($availableAges as $age)
                        <label class="flex items-center gap-2 px-2 py-1.5 rounded-md hover:bg-zinc-50 dark:hover:bg-zinc-950 cursor-pointer text-sm text-zinc-700 dark:text-zinc-300">
                            <input type="checkbox" value="{{ $age }}" wire:model.live="filterAges" class="rounded text-accent focus:ring-accent" />
                            <span>{{ $age }} سنة</span>
                        </label>
                    @endforeach
                </div>
            </div>

            <!-- Custom Field Filter Select -->
            <div class="{{ $filterFieldId ? 'md:col-span-2' : 'md:col-span-4' }}">
                <select wire:model.live="filterFieldId" class="block w-full rounded-lg border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 px-3 py-2 text-sm text-zinc-700 dark:text-zinc-300 font-medium hover:bg-zinc-50 dark:hover:bg-zinc-950 transition-colors focus:ring-2 focus:ring-accent focus:outline-hidden">
                    <option value="">-- تصفية حسب سؤال معين --</option>
                    @foreach($form->fields as $field)
                        @continue(\App\Support\SurveyFieldTypes::isLayout($field['type']))
                        <option value="{{ $field['id'] }}">{{ $field['label'] }}</option>
                    @endforeach
                </select>
            </div>

            <!-- Custom Field Filter Value -->
            @if($filterFieldId)
                @php
                    $selectedField = collect($form->fields)->firstWhere('id', $filterFieldId);
                @endphp
                <div class="md:col-span-2">
                    @if($selectedField && !empty($selectedField['options']))
                        <select wire:model.live="filterFieldValue" class="block w-full rounded-lg border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 px-3 py-2 text-sm text-zinc-700 dark:text-zinc-300 font-medium hover:bg-zinc-50 dark:hover:bg-zinc-950 transition-colors focus:ring-2 focus:ring-accent focus:outline-hidden">
                            <option value="">-- كل القيم --</option>
                            @foreach($selectedField['options'] as $option)
                                <option value="{{ $option }}">{{ $option }}</option>
                            @endforeach
                        </select>
                    @else
                        <flux:input wire:model.live.debounce.300ms="filterFieldValue" placeholder="اكتب قيمة التصفية..." />
                    @endif
                </div>
            @endif
        </div>

        <!-- Sorting Control bar -->
        <div class="flex flex-wrap items-center justify-between gap-4 bg-zinc-50 dark:bg-zinc-950 px-4 py-2.5 rounded-xl border border-zinc-200 dark:border-zinc-800 text-xs text-zinc-650 dark:text-zinc-400">
            <div class="flex items-center gap-2 flex-wrap">
                <span class="font-semibold text-zinc-500">ترتيب النتائج حسب:</span>
                <button type="button" wire:click="setSort('created_at')" class="px-2.5 py-1 rounded-md transition-all font-medium {{ $sortBy === 'created_at' ? 'bg-accent text-white font-semibold' : 'hover:bg-zinc-200 dark:hover:bg-zinc-800 text-zinc-700 dark:text-zinc-300' }}">تاريخ الرد</button>
                <button type="button" wire:click="setSort('name')" class="px-2.5 py-1 rounded-md transition-all font-medium {{ $sortBy === 'name' ? 'bg-accent text-white font-semibold' : 'hover:bg-zinc-200 dark:hover:bg-zinc-800 text-zinc-700 dark:text-zinc-300' }}">الاسم</button>
                <button type="button" wire:click="setSort('stage')" class="px-2.5 py-1 rounded-md transition-all font-medium {{ $sortBy === 'stage' ? 'bg-accent text-white font-semibold' : 'hover:bg-zinc-200 dark:hover:bg-zinc-800 text-zinc-700 dark:text-zinc-300' }}">المرحلة</button>
                <button type="button" wire:click="setSort('age')" class="px-2.5 py-1 rounded-md transition-all font-medium {{ $sortBy === 'age' ? 'bg-accent text-white font-semibold' : 'hover:bg-zinc-200 dark:hover:bg-zinc-800 text-zinc-700 dark:text-zinc-300' }}">العمر</button>
            </div>
            <button type="button" wire:click="toggleSortDirection" class="flex items-center gap-1.5 hover:text-zinc-900 dark:hover:text-white transition-colors">
                <span>اتجاه الترتيب:</span>
                <span class="font-bold text-accent">{{ $sortDirection === 'asc' ? 'تصاعدي (أصغر/أقدم)' : 'تنازلي (أكبر/أحدث)' }}</span>
                <flux:icon name="{{ $sortDirection === 'asc' ? 'arrow-up' : 'arrow-down' }}" class="size-3.5" />
            </button>
        </div>

        <!-- Selection action bar -->
        @if(count($selectedResponseIds) > 0)
            <div class="flex items-center justify-between gap-3 flex-wrap bg-accent/5 dark:bg-accent/10 border border-accent/30 rounded-xl px-4 py-3">
                <span class="text-sm font-semibold text-zinc-700 dark:text-zinc-200">
                    تم تحديد {{ count($selectedResponseIds) }} ردًّا
                </span>
                <div class="flex items-center gap-2">
                    <flux:button wire:click="openBulkModal(true)" size="sm" variant="primary" icon="user-plus" class="bg-accent hover:bg-accent/90 text-white border-0">
                        إنشاء حسابات للمحدّد
                    </flux:button>
                    <flux:button wire:click="clearSelection" size="sm" variant="ghost">إلغاء التحديد</flux:button>
                </div>
            </div>
        @endif

        <!-- Responses Table -->
        <div class="bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-800 overflow-auto max-h-[70vh] shadow-xs relative">
            @if($responses->isEmpty())
                <div class="p-12 text-center text-zinc-500 dark:text-zinc-400">
                    لا توجد ردود مطابقة للبحث حالياً.
                </div>
            @else
                <table class="w-full text-start border-collapse text-sm">
                    <thead class="sticky top-0 bg-zinc-50 dark:bg-zinc-950 z-10 shadow-[0_1px_0_0_rgba(0,0,0,0.1)] dark:shadow-[0_1px_0_0_rgba(255,255,255,0.1)]">
                        <tr class="text-zinc-700 dark:text-zinc-300 font-semibold border-b border-zinc-200 dark:border-zinc-800">
                            <th class="p-4 text-start w-10 bg-inherit">
                                @if($unprocessedCount > 0)
                                    <input type="checkbox" wire:click="toggleSelectAllUnprocessed"
                                        @checked(count($selectedResponseIds) >= $unprocessedCount)
                                        class="rounded text-accent focus:ring-accent" title="تحديد كل غير المعالَج" />
                                @endif
                            </th>
                            <th class="p-4 text-start bg-inherit">تاريخ الرد</th>
                            @foreach($form->fields as $field)
                                @continue(\App\Support\SurveyFieldTypes::isLayout($field['type']))
                                <th class="p-4 text-start min-w-[120px] bg-inherit">{{ $field['label'] }}</th>
                            @endforeach
                            <th class="p-4 text-start bg-inherit">الحالة / الربط</th>
                            <th class="p-4 text-start bg-inherit">الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-150 dark:divide-zinc-850">
                        @foreach($responses as $response)
                            <tr class="hover:bg-zinc-50/50 dark:hover:bg-zinc-950/20 text-zinc-850 dark:text-zinc-200" wire:key="response-{{ $response->id }}">
                                <td class="p-4 whitespace-nowrap">
                                    @if(!$response->student_id)
                                        <input type="checkbox" value="{{ $response->id }}" wire:model.live="selectedResponseIds"
                                            class="rounded text-accent focus:ring-accent" />
                                    @endif
                                </td>
                                <td class="p-4 whitespace-nowrap text-xs text-zinc-400 dark:text-zinc-500">
                                    <x-hijri-date :date="$response->created_at" style="withTime" />
                                </td>
                                @foreach($form->fields as $field)
                                    @continue(\App\Support\SurveyFieldTypes::isLayout($field['type']))
                                    @php
                                        $fieldId = $field['id'];
                                        $answer = $response->answers[$fieldId] ?? null;
                                    @endphp
                                    <td class="p-4">
                                        @if($field['type'] === 'image' && $answer)
                                            <a href="{{ asset('storage/' . $answer) }}" target="_blank" class="block w-10 h-10 rounded-lg overflow-hidden border border-zinc-200 dark:border-zinc-800 hover:scale-105 transition-transform">
                                                <img src="{{ asset('storage/' . $answer) }}" class="w-full h-full object-cover" />
                                            </a>
                                        @elseif($field['type'] === 'multiselect' && is_array($answer))
                                            <div class="flex flex-wrap gap-1">
                                                @foreach($answer as $opt)
                                                    <span class="inline-flex px-1.5 py-0.5 rounded text-[10px] bg-zinc-100 dark:bg-zinc-800 text-zinc-700 dark:text-zinc-300 font-medium">
                                                        {{ $opt }}
                                                    </span>
                                                @endforeach
                                            </div>
                                        @elseif($field['type'] === 'likert' && $answer !== null && $answer !== '')
                                            <span class="text-xs font-medium">{{ \App\Support\SurveyFieldTypes::likertScale()[(int) $answer] ?? $answer }}</span>
                                        @elseif(\App\Support\SurveyFieldTypes::isScale($field['type']) && $answer !== null && $answer !== '')
                                            @php $bounds = \App\Support\SurveyFieldTypes::scaleBounds($field); @endphp
                                            <span class="text-xs font-bold tabular-nums">{{ $answer }}<span class="text-zinc-400 font-normal"> / {{ $bounds['max'] }}</span></span>
                                        @else
                                            <span title="{{ is_array($answer) ? implode(', ', $answer) : $answer }}">
                                                {{ is_array($answer) ? implode(', ', $answer) : $answer }}
                                            </span>
                                        @endif
                                    </td>
                                @endforeach
                                <td class="p-4 whitespace-nowrap">
                                    @if($response->student_id)
                                        <div class="flex items-center gap-1.5 text-xs text-emerald-600 dark:text-emerald-400">
                                            <flux:icon name="check-circle" class="size-4 shrink-0" />
                                            <span class="font-medium">مرتبط بـ: {{ $response->student->name }}</span>
                                        </div>
                                    @else
                                        <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-semibold bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-400">
                                            غير معالج
                                        </span>
                                    @endif
                                </td>
                                <td class="p-4 whitespace-nowrap flex items-center gap-2">
                                    @if(!$response->student_id)
                                        <flux:button wire:click="openCreateModal({{ $response->id }})" size="sm" variant="filled" class="text-xs">
                                            إنشاء حساب طالب
                                        </flux:button>
                                        <flux:button wire:click="openLinkModal({{ $response->id }})" size="sm" variant="ghost" class="text-xs">
                                            ربط بطالب قائم
                                        </flux:button>
                                    @endif
                                    <flux:button wire:click="deleteResponse({{ $response->id }})" wire:confirm="هل أنت متأكد من رغبتك في حذف هذا الرد؟" size="sm" variant="ghost" icon="trash" class="text-rose-500 hover:text-rose-600 hover:bg-rose-50 dark:hover:bg-rose-950/20" />
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>

    <!-- Reports Tab -->
    <div x-show="activeTab === 'reports'" class="space-y-6">
        @if(empty($reportsData))
            <div class="bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-800 p-8 text-center text-zinc-500 dark:text-zinc-400">
                النموذج لا يحتوي على حقول من نوع "خيارات" أو "خيارات متعددة" لعرض تقارير بيانية لها.
            </div>
        @else
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                @foreach($reportsData as $report)
                    <div class="bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-800 p-5 space-y-4">
                        <h3 class="font-bold text-zinc-900 dark:text-white border-b border-zinc-100 dark:border-zinc-800 pb-3">
                            {{ $report['label'] }}
                        </h3>
                        
                        <div class="space-y-4">
                            @foreach($report['options'] as $option => $count)
                                @php
                                    $pct = $report['total'] > 0 ? round(($count / $report['total']) * 100, 1) : 0;
                                @endphp
                                <div class="space-y-1">
                                    <div class="flex items-center justify-between text-xs font-semibold text-zinc-700 dark:text-zinc-300">
                                        <span>{{ $option }}</span>
                                        <span>{{ $count }} رد ({{ $pct }}%)</span>
                                    </div>
                                    <div class="w-full bg-zinc-100 dark:bg-zinc-800 h-3 rounded-full overflow-hidden">
                                        <div class="h-full rounded-full transition-all duration-500" style="background-color: {{ $form->color }}; width: {{ $pct }}%"></div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    <!-- Modal: Create Student Account -->
    <flux:modal wire:model="showCreateModal" class="w-full max-w-lg space-y-4">
        <div>
            <h2 class="text-lg font-bold text-zinc-900 dark:text-white">إنشاء حساب طالب جديد</h2>
            <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-1">القيم معبّأة تلقائيًا من الرد؛ صحّح ما لا يتطابق قبل الحفظ. يُنشأ الحساب بالحالة (تحت التسجيل).</p>
        </div>

        <div class="max-h-[60vh] overflow-y-auto space-y-4 pe-1">
            <flux:field>
                <flux:label>اسم الطالب المعتمد *</flux:label>
                <flux:input wire:model="newStudentName" />
                <flux:error name="newStudentName" />
            </flux:field>

            <flux:field>
                <flux:label>البريد الإلكتروني (يُستخدم للدخول)</flux:label>
                <label class="flex items-center gap-2 cursor-pointer text-xs text-zinc-500 dark:text-zinc-400 mb-1">
                    <input type="checkbox" wire:model.live="newStudentRandomEmail" class="rounded text-accent focus:ring-accent" />
                    <span>توليد بريد عشوائي تلقائيًا</span>
                </label>
                <flux:input wire:model="newStudentEmail" :disabled="$newStudentRandomEmail" placeholder="ahmad@example.com أو اتركه للعشوائي" />
                <flux:error name="newStudentEmail" />
            </flux:field>

            <div class="grid grid-cols-2 gap-3">
                <flux:field>
                    <flux:label>الجوال</flux:label>
                    <flux:input type="tel" wire:model="newStudentPhone" dir="ltr" />
                    <flux:error name="newStudentPhone" />
                </flux:field>
                <flux:field>
                    <flux:label>تاريخ الميلاد</flux:label>
                    <flux:input type="date" wire:model="newStudentBirthDate" />
                    <flux:error name="newStudentBirthDate" />
                </flux:field>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <flux:field>
                    <flux:label>الجنسية</flux:label>
                    <flux:input wire:model="newStudentNationality" />
                    <flux:error name="newStudentNationality" />
                </flux:field>
                <flux:field>
                    <flux:label>رقم الهوية / الإقامة</flux:label>
                    <flux:input wire:model="newStudentNationalId" dir="ltr" />
                    <flux:error name="newStudentNationalId" />
                </flux:field>
            </div>

            <flux:field>
                <flux:label>كلمة المرور *</flux:label>
                <flux:input type="text" wire:model="newStudentPassword" />
                <flux:error name="newStudentPassword" />
            </flux:field>

            <div class="grid grid-cols-2 gap-3">
                <flux:field>
                    <flux:label>المرحلة (اختياري)</flux:label>
                    <select wire:model="targetStageId" class="block w-full rounded-lg border border-zinc-200 dark:border-zinc-800 bg-transparent px-3 py-2 text-sm">
                        <option value="">-- بلا مرحلة --</option>
                        @foreach($stages as $s)
                            <option value="{{ $s->id }}">{{ $s->name }}</option>
                        @endforeach
                    </select>
                    <flux:error name="targetStageId" />
                </flux:field>
                <flux:field>
                    <flux:label>الحلقة (اختياري)</flux:label>
                    <select wire:model="targetCircleId" class="block w-full rounded-lg border border-zinc-200 dark:border-zinc-800 bg-transparent px-3 py-2 text-sm">
                        <option value="">-- بلا حلقة --</option>
                        @foreach($circles as $c)
                            <option value="{{ $c->id }}">{{ $c->name }} ({{ $c->stage->name }})</option>
                        @endforeach
                    </select>
                    <flux:error name="targetCircleId" />
                </flux:field>
            </div>
            <p class="text-[11px] text-zinc-400">عند اختيار حلقة تُعتمد مرحلتها تلقائيًا. اختيار المرحلة وحدها يُنشئ الطالب بلا حلقة ضمن تلك المرحلة.</p>
        </div>

        <div class="flex justify-end gap-3 pt-4 border-t border-zinc-150 dark:border-zinc-850">
            <flux:button @click="$wire.showCreateModal = false" variant="ghost">إلغاء</flux:button>
            <flux:button wire:click="createStudentAccount" variant="primary">حفظ وإنشاء الحساب</flux:button>
        </div>
    </flux:modal>

    <!-- Modal: Link to Existing Student -->
    <flux:modal wire:model="showLinkModal" class="w-full max-w-md space-y-4">
        <div>
            <h2 class="text-lg font-bold text-zinc-900 dark:text-white">ربط الرد بطالب قائم</h2>
            <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-1">اختر الطالب من حلقاتك لربط هذا الرد ببياناته.</p>
        </div>

        <flux:field>
            <flux:label>اختر الطالب من القائمة *</flux:label>
            <select wire:model="linkStudentId" class="block w-full rounded-lg border border-zinc-200 dark:border-zinc-800 bg-transparent px-3 py-2 text-sm">
                <option value="">-- اختر طالباً --</option>
                @foreach($students as $st)
                    <option value="{{ $st->id }}">{{ $st->name }}</option>
                @endforeach
            </select>
            <flux:error name="linkStudentId" />
        </flux:field>

        <flux:field>
            <flux:label>الاسم المعتمد للطالب *</flux:label>
            <div class="space-y-2">
                <label class="flex items-center gap-2 cursor-pointer text-sm">
                    <input type="radio" wire:model="linkNameOption" value="existing" class="text-accent focus:ring-accent" />
                    <span>الاحتفاظ بالاسم الحالي للطالب في النظام</span>
                </label>
                <label class="flex items-center gap-2 cursor-pointer text-sm">
                    <input type="radio" wire:model="linkNameOption" value="response" class="text-accent focus:ring-accent" />
                    <span>تعديل اسم الطالب في النظام إلى الاسم الوارد في الاستمارة</span>
                </label>
            </div>
            <flux:error name="linkNameOption" />
        </flux:field>

        <div class="flex justify-end gap-3 pt-4 border-t border-zinc-150 dark:border-zinc-850">
            <flux:button @click="$wire.showLinkModal = false" variant="ghost">إلغاء</flux:button>
            <flux:button wire:click="linkToExistingStudent" variant="primary">ربط البيانات</flux:button>
        </div>
    </flux:modal>

    <!-- Modal: Bulk Create -->
    <flux:modal wire:model="showBulkModal" class="w-full max-w-2xl space-y-4">
        <div>
            <h2 class="text-lg font-bold text-zinc-900 dark:text-white">
                {{ $bulkSelectedOnly ? 'إنشاء حسابات للردود المحددة' : 'الإنشاء الجماعي لحسابات الطلاب' }}
            </h2>
            <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-1">
                @if($bulkSelectedOnly)
                    سيقتصر الإنشاء على <span class="font-bold text-accent">{{ count($bulkScopeIds) }}</span> ردًّا محددًا.
                @endif
                اربط حقول النموذج ببيانات الطالب، ثم حلّل الردود. تُنشأ الردود الصالحة دفعةً، وتُعرض المشكِلة لمراجعتها يدويًا قبل إنشائها.
            </p>
        </div>

        <div class="max-h-[62vh] overflow-y-auto space-y-5 pe-1">
            <!-- Field mapping -->
            <div class="space-y-3">
                <h3 class="text-sm font-bold text-zinc-700 dark:text-zinc-300">ربط الحقول بالبيانات</h3>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    @php
                        $attrLabels = ['name' => 'الاسم *', 'email' => 'البريد', 'phone' => 'الجوال', 'birth_date' => 'تاريخ الميلاد', 'nationality' => 'الجنسية', 'national_id' => 'رقم الهوية / الإقامة'];
                    @endphp
                    @foreach($attrLabels as $attr => $label)
                        <div>
                            <label class="block text-xs font-medium text-zinc-600 dark:text-zinc-400 mb-1">{{ $label }}</label>
                            <select wire:model="bulkMap.{{ $attr }}" class="block w-full rounded-lg border border-zinc-200 dark:border-zinc-800 bg-transparent px-3 py-2 text-sm">
                                <option value="">-- لا شيء --</option>
                                @foreach($form->fields as $field)
                                    @continue(\App\Support\SurveyFieldTypes::isLayout($field['type']))
                                    <option value="{{ $field['id'] }}">{{ $field['label'] }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endforeach
                </div>
            </div>

            <!-- Global options -->
            <div class="space-y-3 border-t border-zinc-100 dark:border-zinc-800 pt-4">
                <h3 class="text-sm font-bold text-zinc-700 dark:text-zinc-300">إعدادات عامة للدفعة</h3>
                <label class="flex items-center gap-2 cursor-pointer text-sm text-zinc-600 dark:text-zinc-300">
                    <input type="checkbox" wire:model="bulkRandomEmail" class="rounded text-accent focus:ring-accent" />
                    <span>توليد بريد عشوائي لكل حساب (يتجاهل حقل البريد)</span>
                </label>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <flux:field>
                        <flux:label>كلمة مرور موحّدة *</flux:label>
                        <flux:input type="text" wire:model="bulkPassword" />
                        <flux:error name="bulkPassword" />
                    </flux:field>
                    <flux:field>
                        <flux:label>المرحلة (اختياري)</flux:label>
                        <select wire:model="bulkStageId" class="block w-full rounded-lg border border-zinc-200 dark:border-zinc-800 bg-transparent px-3 py-2 text-sm">
                            <option value="">-- بلا مرحلة --</option>
                            @foreach($stages as $s)
                                <option value="{{ $s->id }}">{{ $s->name }}</option>
                            @endforeach
                        </select>
                        <flux:error name="bulkStageId" />
                    </flux:field>
                    <flux:field>
                        <flux:label>الحلقة (اختياري)</flux:label>
                        <select wire:model="bulkCircleId" class="block w-full rounded-lg border border-zinc-200 dark:border-zinc-800 bg-transparent px-3 py-2 text-sm">
                            <option value="">-- بلا حلقة --</option>
                            @foreach($circles as $c)
                                <option value="{{ $c->id }}">{{ $c->name }} ({{ $c->stage->name }})</option>
                            @endforeach
                        </select>
                        <flux:error name="bulkCircleId" />
                    </flux:field>
                </div>
            </div>

            <!-- Analysis results -->
            @if($bulkAnalyzed)
                <div class="space-y-4 border-t border-zinc-100 dark:border-zinc-800 pt-4">
                    <div class="flex items-center gap-3 text-sm">
                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400 font-semibold">
                            <flux:icon name="check-circle" class="size-4" /> {{ count($bulkReady) }} جاهز
                        </span>
                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-400 font-semibold">
                            <flux:icon name="exclamation-triangle" class="size-4" /> {{ count($bulkNeedsReview) }} يحتاج مراجعة
                        </span>
                    </div>

                    @if(count($bulkReady) > 0)
                        <flux:button wire:click="createReadyStudents" variant="primary" class="bg-accent hover:bg-accent/90 text-white border-0" icon="bolt">
                            إنشاء الحسابات الجاهزة ({{ count($bulkReady) }})
                        </flux:button>
                    @endif

                    @if(count($bulkNeedsReview) > 0)
                        <div class="space-y-2">
                            <h4 class="text-sm font-bold text-amber-700 dark:text-amber-400">ردود تحتاج مراجعة يدوية</h4>
                            @foreach($bulkNeedsReview as $row)
                                <div class="rounded-lg border border-amber-200 dark:border-amber-500/30 bg-amber-50/40 dark:bg-amber-500/5 p-3 space-y-2">
                                    <div class="flex flex-wrap gap-1.5">
                                        @foreach($row['reasons'] as $reason)
                                            <span class="text-[11px] px-1.5 py-0.5 rounded bg-amber-100 dark:bg-amber-500/20 text-amber-800 dark:text-amber-300">{{ $reason }}</span>
                                        @endforeach
                                    </div>
                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                                        <div>
                                            <label class="block text-[11px] text-zinc-500 mb-1">الاسم</label>
                                            <flux:input size="sm" wire:model="reviewEdits.{{ $row['response_id'] }}.name" />
                                            <flux:error name="reviewEdits.{{ $row['response_id'] }}.name" />
                                        </div>
                                        <div>
                                            <label class="block text-[11px] text-zinc-500 mb-1">تاريخ الميلاد @if($row['birth_raw'])<span class="text-amber-600">(الوارد: {{ $row['birth_raw'] }})</span>@endif</label>
                                            <flux:input size="sm" type="date" wire:model="reviewEdits.{{ $row['response_id'] }}.birth_date" />
                                            <flux:error name="reviewEdits.{{ $row['response_id'] }}.birth_date" />
                                        </div>
                                    </div>
                                    <div class="flex justify-end">
                                        <flux:button size="sm" wire:click="createReviewedStudent({{ $row['response_id'] }})" variant="filled" class="text-xs">إنشاء هذا الحساب</flux:button>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif

                    @if(count($bulkReady) === 0 && count($bulkNeedsReview) === 0)
                        <p class="text-sm text-zinc-500 text-center py-4">لا توجد ردود غير معالَجة لإنشاء حسابات لها.</p>
                    @endif
                </div>
            @endif
        </div>

        <div class="flex justify-end gap-3 pt-4 border-t border-zinc-150 dark:border-zinc-850">
            <flux:button @click="$wire.showBulkModal = false" variant="ghost">إغلاق</flux:button>
            <flux:button wire:click="analyzeBulk" variant="primary" icon="magnifying-glass">
                {{ $bulkAnalyzed ? 'إعادة التحليل' : 'تحليل الردود' }}
            </flux:button>
        </div>
    </flux:modal>
</div>
