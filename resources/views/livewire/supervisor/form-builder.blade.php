<div class="space-y-6" x-data="{ color: '{{ $color }}' }">
    <!-- Header -->
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">
                {{ $isEditing ? 'تعديل تصميم النموذج' : 'إنشاء نموذج واستمارة جديدة' }}
            </h1>
            <p class="text-sm text-zinc-500 dark:text-zinc-400 mt-1">صمم الحقول والخيارات المخصصة للاستمارة وحدد حقول الربط مع حسابات الطلاب.</p>
        </div>
        <div class="flex gap-3">
            <flux:button as="a" :href="route('supervisor.forms')" variant="ghost">إلغاء</flux:button>
            <flux:button wire:click="save" variant="primary" class="bg-accent hover:bg-accent/90 text-white border-0">
                حفظ النموذج
            </flux:button>
            <flux:button wire:click="publish" variant="primary" icon="paper-airplane"
                class="!bg-emerald-600 hover:!bg-emerald-700 text-white border-0">
                {{ $status === 'published' ? 'إعادة النشر' : 'نشر وإسناد' }}
            </flux:button>
        </div>
    </div>

    {{-- Who owes this survey, and how hard it asks. --}}
    <div class="bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-800 p-5 space-y-5">
        <div class="flex items-center justify-between flex-wrap gap-3">
            <h2 class="text-lg font-bold text-zinc-900 dark:text-white">التوجيه والإسناد</h2>
            <div class="flex items-center gap-2">
                @if($status === 'published')
                    <flux:badge color="emerald" size="sm">منشورة</flux:badge>
                @else
                    <flux:badge color="zinc" size="sm">مسودة</flux:badge>
                @endif
                <span class="text-xs text-zinc-500">ستصل إلى <b class="tabular-nums">{{ $this->audienceSize() }}</b> شخصاً</span>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            @foreach($audienceRoles as $roleKey => $roleLabel)
                <div class="rounded-lg border border-zinc-200 dark:border-zinc-800 p-3 space-y-2">
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" wire:model.live="audience.all_{{ $roleKey }}s"
                            class="rounded border-zinc-300 text-accent focus:ring-accent" />
                        <span class="text-sm font-bold text-zinc-800 dark:text-zinc-100">كل {{ $roleLabel }}</span>
                    </label>

                    @if($roleKey !== 'manager')
                        <div class="text-[11px] text-zinc-400">أو حدّد مراحل بعينها:</div>
                        <div class="max-h-24 overflow-auto space-y-1 pr-1">
                            @foreach($stages as $stage)
                                <label class="flex items-center gap-2 cursor-pointer text-xs">
                                    <input type="checkbox" value="{{ $stage->id }}"
                                        wire:model.live="audience.stage_ids_for_{{ $roleKey }}s"
                                        class="rounded border-zinc-300 text-accent focus:ring-accent" />
                                    <span class="text-zinc-600 dark:text-zinc-300 truncate">{{ $stage->name }}</span>
                                </label>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endforeach
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 pt-3 border-t border-zinc-200 dark:border-zinc-800">
            <flux:field>
                <flux:label>الموعد النهائي (اختياري)</flux:label>
                <flux:input type="date" wire:model="due_date" />
                <flux:error name="due_date" />
            </flux:field>

            <div class="flex items-start gap-2 md:pt-6">
                <input type="checkbox" wire:model="is_blocking" id="is_blocking"
                    class="mt-1 rounded border-zinc-300 text-accent focus:ring-accent" />
                <label for="is_blocking" class="cursor-pointer">
                    <span class="text-sm font-medium text-zinc-800 dark:text-zinc-100">إلزامية: تمنع استخدام التطبيق حتى تُعبّأ</span>
                    <span class="block text-[11px] text-amber-600 dark:text-amber-400 mt-0.5">
                        استعملها بحذر — المستهدَف لن يرى صفحاته حتى يجيب. المديرون لا يُمنعون،
                        والمنع يرتفع تلقائياً بعد الموعد النهائي.
                    </span>
                </label>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Settings (Left Column) -->
        <div class="lg:col-span-1 space-y-6">
            <div class="bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-800 p-5 space-y-4">
                <h2 class="text-lg font-bold text-zinc-900 dark:text-white">خصائص النموذج</h2>
                
                <!-- Title -->
                <flux:field>
                    <flux:label>عنوان النموذج *</flux:label>
                    <flux:input wire:model="title" placeholder="مثال: استمارة تسجيل الطلاب الجدد" />
                    <flux:error name="title" />
                </flux:field>

                <!-- Description -->
                <flux:field>
                    <flux:label>وصف النموذج</flux:label>
                    <flux:textarea wire:model="description" rows="3" placeholder="اكتب وصفاً أو إرشادات للمتقدمين لتعبئة الاستمارة..." />
                    <flux:error name="description" />
                </flux:field>

                <!-- Policy Text -->
                <flux:field>
                    <flux:label>سياسة الاستخدام والشروط (اختياري)</flux:label>
                    <flux:textarea wire:model="policy_text" rows="4" placeholder="اكتب شروط الاستخدام أو السياسة التي يجب على المتقدم الموافقة عليها للدخول..." />
                    <flux:error name="policy_text" />
                </flux:field>

                <!-- Success Text -->
                <flux:field>
                    <flux:label>رسالة إتمام الاستمارة المخصصة (اختياري)</flux:label>
                    <flux:textarea wire:model="success_text" rows="3" placeholder="اكتب رسالة تظهر للمتقدم بعد إرسال استمارته بنجاح..." />
                    <flux:error name="success_text" />
                </flux:field>

                <!-- Slug -->
                <flux:field>
                    <flux:label>رابط الاستمارة المخصص *</flux:label>
                    <div class="flex items-stretch rounded-lg overflow-hidden border border-zinc-200 dark:border-zinc-800">
                        <span class="bg-zinc-50 dark:bg-zinc-800 text-zinc-500 px-3 flex items-center text-xs dir-ltr select-none border-e border-zinc-200 dark:border-zinc-800">
                            /f/
                        </span>
                        <input wire:model="slug" type="text" class="flex-1 px-3 py-1.5 text-sm bg-transparent border-0 focus:ring-0 dir-ltr text-left outline-hidden" placeholder="registration-2026" />
                    </div>
                    <flux:error name="slug" />
                </flux:field>

                <!-- Color -->
                <flux:field>
                    <flux:label>اللون المميز (سمة النموذج) *</flux:label>
                    <div class="flex items-center gap-3">
                        <input type="color" wire:model="color" x-model="color" class="w-10 h-10 rounded-lg cursor-pointer border-0 p-0" />
                        <flux:input wire:model="color" x-model="color" placeholder="#7a2727" class="flex-1" />
                    </div>
                    <flux:error name="color" />
                </flux:field>

                <!-- Header Image -->
                <flux:field>
                    <flux:label>الصورة الرئيسية (رأس الصفحة)</flux:label>
                    <div class="space-y-3">
                        @if($header_image_path)
                            <div class="relative rounded-lg overflow-hidden border border-zinc-200 dark:border-zinc-800 h-24">
                                <img src="{{ asset('storage/' . $header_image_path) }}" class="w-full h-full object-cover" />
                                <div class="absolute inset-0 bg-black/40 flex items-center justify-center">
                                    <span class="text-xs text-white">صورة رأس الصفحة الحالية</span>
                                </div>
                            </div>
                        @endif

                        <input type="file" wire:model="header_image_file" class="block w-full text-xs text-zinc-500 file:mr-4 file:py-1.5 file:px-3 file:rounded-md file:border-0 file:text-xs file:font-semibold file:bg-zinc-100 file:text-zinc-700 dark:file:bg-zinc-800 dark:file:text-zinc-300 file:cursor-pointer" />
                        <flux:error name="header_image_file" />
                    </div>
                </flux:field>

                <!-- Public Report Link Sharing -->
                <div class="pt-3 border-t border-zinc-150 dark:border-zinc-850">
                    <label class="flex items-center gap-2 cursor-pointer text-sm">
                        <input type="checkbox" wire:model="is_public_report" class="rounded border-zinc-300 text-accent focus:ring-accent" />
                        <span class="text-zinc-700 dark:text-zinc-300 font-semibold">مشاركة التقرير والنتائج علناً</span>
                    </label>
                    <p class="text-xs text-zinc-500 mt-1">عند تفعيله، سيتمكن أي شخص يمتلك الرابط من رؤية جدول الردود وتصفيتها وتجميعها دون تسجيل دخول.</p>
                </div>

                <!-- Share with all supervisors -->
                <div class="pt-3 border-t border-zinc-150 dark:border-zinc-850">
                    <label class="flex items-center gap-2 cursor-pointer text-sm">
                        <input type="checkbox" wire:model="is_supervisor_shared" class="rounded border-zinc-300 text-accent focus:ring-accent" />
                        <span class="text-zinc-700 dark:text-zinc-300 font-semibold">نموذج عام للمشرفين</span>
                    </label>
                    <p class="text-xs text-zinc-500 mt-1">عند تفعيله، يمكن لأي مشرف الدخول إلى هذا النموذج والتعديل عليه وإنشاء حسابات الطلاب ضمن مرحلته وحلقاته هو.</p>
                </div>
            </div>
        </div>

        <!-- Field Designer (Right Columns) -->
        <div class="lg:col-span-2 space-y-6">
            <div class="bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-800 p-5 space-y-6">
                <div class="flex items-center justify-between">
                    <h2 class="text-lg font-bold text-zinc-900 dark:text-white">حقول النموذج واستمارة الإدخال</h2>
                    
                    <div class="flex flex-col items-end gap-2">
                        <flux:button x-on:click="$flux.modal('paste-questions').show()"
                            size="sm" variant="primary" icon="clipboard-document-list" class="!bg-maroon hover:!bg-burgundy text-xs">
                            لصق الأسئلة دفعة واحدة
                        </flux:button>

                        {{-- Every type the registry offers, so a new one appears here by adding it there. --}}
                        <div class="flex flex-wrap gap-1.5 justify-end">
                            @foreach($fieldTypes as $typeKey => $meta)
                                <flux:button wire:click="addField('{{ $typeKey }}', '{{ $meta['label'] }}')"
                                    size="sm" :icon="$meta['icon']" class="text-xs">
                                    {{ $meta['label'] }}
                                </flux:button>
                            @endforeach
                        </div>
                    </div>
                </div>

                <flux:error name="fields" />

                <!-- Fields List -->
                <div class="space-y-4">
                    @foreach($fields as $index => $field)
                        <div class="p-4 bg-zinc-50 dark:bg-zinc-950 rounded-lg border border-zinc-200 dark:border-zinc-800 space-y-4">
                            <!-- Field Header Info -->
                            <div class="flex items-center justify-between gap-4">
                                <div class="flex items-center gap-2">
                                    <span class="text-xs font-semibold px-2 py-0.5 rounded bg-zinc-200 dark:bg-zinc-800 text-zinc-700 dark:text-zinc-300">
                                        {{ \App\Support\SurveyFieldTypes::label($field['type']) }}
                                    </span>
                                </div>
                                <div class="flex items-center gap-1">
                                    <flux:button wire:click="moveUp({{ $index }})" size="sm" variant="ghost" icon="chevron-up" class="h-8 w-8" :disabled="$index === 0" />
                                    <flux:button wire:click="moveDown({{ $index }})" size="sm" variant="ghost" icon="chevron-down" class="h-8 w-8" :disabled="$index === count($fields) - 1" />
                                    <flux:button wire:click="removeField({{ $index }})" size="sm" variant="ghost" icon="trash" class="h-8 w-8 text-rose-500 hover:text-rose-600 hover:bg-rose-50 dark:hover:bg-rose-950/20" />
                                </div>
                            </div>

                            @php $isLayout = \App\Support\SurveyFieldTypes::isLayout($field['type']); @endphp

                            <!-- Inputs Row -->
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                <!-- Label -->
                                <div class="md:col-span-2">
                                    <flux:field>
                                        <flux:label>{{ $isLayout ? 'عنوان القسم *' : 'اسم الحقل (السؤال) *' }}</flux:label>
                                        <flux:input wire:model="fields.{{ $index }}.label"
                                            placeholder="{{ $isLayout ? 'مثال: رضاك عن الحلقة' : 'مثال: الاسم الكامل للطالب' }}" />
                                        <flux:error name="fields.{{ $index }}.label" />
                                    </flux:field>
                                </div>

                                {{-- A section divider asks nothing, so it cannot be required. --}}
                                @if(! $isLayout)
                                    <div class="flex items-center md:pt-6">
                                        <flux:checkbox wire:model="fields.{{ $index }}.required" label="حقل إلزامي التعبئة" />
                                    </div>
                                @else
                                    <div class="flex items-center md:pt-6 text-xs text-zinc-400">
                                        يبدأ صفحة جديدة في الاستبانة
                                    </div>
                                @endif
                            </div>

                            {{-- Scale configuration and preview, so the shape of the answer is
                                 visible while writing the question rather than after publishing. --}}
                            @if($field['type'] === 'rating')
                                <div class="border-t border-zinc-200 dark:border-zinc-800 pt-3 space-y-2">
                                    <div class="flex items-end gap-4">
                                        <flux:field class="w-32">
                                            <flux:label>أعلى درجة</flux:label>
                                            <flux:input type="number" min="3" max="10" wire:model.live="fields.{{ $index }}.max" />
                                            <flux:error name="fields.{{ $index }}.max" />
                                        </flux:field>
                                        <div class="flex items-center gap-1 pb-2">
                                            @for($s = 1; $s <= max(3, min(10, (int) ($field['max'] ?? 5))); $s++)
                                                <flux:icon icon="star" variant="solid" class="size-5 text-amber-400" />
                                            @endfor
                                        </div>
                                    </div>
                                </div>
                            @elseif($field['type'] === 'likert')
                                <div class="border-t border-zinc-200 dark:border-zinc-800 pt-3">
                                    <flux:label class="mb-2">سُلَّم الموافقة</flux:label>
                                    <div class="flex flex-wrap gap-1.5">
                                        @foreach($likertScale as $value => $text)
                                            <span class="px-2.5 py-1 rounded-full bg-zinc-100 dark:bg-zinc-800 text-xs text-zinc-600 dark:text-zinc-300">{{ $text }}</span>
                                        @endforeach
                                    </div>
                                </div>
                            @elseif($field['type'] === 'nps')
                                <div class="border-t border-zinc-200 dark:border-zinc-800 pt-3">
                                    <flux:label class="mb-2">مقياس من ٠ إلى ١٠</flux:label>
                                    <div class="flex flex-wrap gap-1">
                                        @for($n = 0; $n <= 10; $n++)
                                            <span class="size-7 flex items-center justify-center rounded bg-zinc-100 dark:bg-zinc-800 text-xs font-medium text-zinc-600 dark:text-zinc-300">{{ $n }}</span>
                                        @endfor
                                    </div>
                                </div>
                            @elseif($field['type'] === 'yesno')
                                <div class="border-t border-zinc-200 dark:border-zinc-800 pt-3 flex gap-2">
                                    <span class="px-3 py-1 rounded-lg bg-emerald-50 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-300 text-xs font-medium">نعم</span>
                                    <span class="px-3 py-1 rounded-lg bg-rose-50 dark:bg-rose-900/30 text-rose-700 dark:text-rose-300 text-xs font-medium">لا</span>
                                </div>
                            @endif

                            <!-- Field Designations (Only for text type fields) -->
                            @if($field['type'] === 'text')
                                <div class="flex flex-wrap gap-4 p-3 bg-white dark:bg-zinc-900 rounded-md border border-zinc-150 dark:border-zinc-800 text-xs">
                                    <label class="flex items-center gap-2 cursor-pointer">
                                        <input type="checkbox"
                                            wire:click="toggleNameDesignation({{ $index }})"
                                            @checked($field['is_student_name'] ?? false)
                                            class="rounded border-zinc-300 text-accent focus:ring-accent" />
                                        <span class="text-zinc-700 dark:text-zinc-300 font-medium">اعتماد هذا الحقل كـ (اسم الطالب) عند إنشاء الحساب</span>
                                    </label>

                                    <label class="flex items-center gap-2 cursor-pointer">
                                        <input type="checkbox"
                                            wire:click="toggleUsernameDesignation({{ $index }})"
                                            @checked($field['is_student_username'] ?? false)
                                            class="rounded border-zinc-300 text-accent focus:ring-accent" />
                                        <span class="text-zinc-700 dark:text-zinc-300 font-medium">اعتماد هذا الحقل كـ (اسم مستخدم الطالب) للولوج للنظام</span>
                                    </label>
                                </div>
                            @endif

                            <!-- Choices/Options configuration, for the types that carry a list -->
                            @if(\App\Support\SurveyFieldTypes::hasOptions($field['type']))
                                <div class="space-y-2 border-t border-zinc-200 dark:border-zinc-800 pt-3">
                                    <flux:label>خيارات الإدخال للنموذج *</flux:label>
                                    <div class="space-y-2">
                                        @foreach($field['options'] ?? [] as $optIndex => $option)
                                            <div class="flex items-center gap-2">
                                                <flux:input wire:model="fields.{{ $index }}.options.{{ $optIndex }}" placeholder="أدخل اسم الخيار..." class="flex-1" />
                                                <flux:button wire:click="removeOption({{ $index }}, {{ $optIndex }})" size="sm" variant="ghost" icon="trash" class="text-rose-500" />
                                            </div>
                                        @endforeach
                                    </div>
                                    <flux:button wire:click="addOption({{ $index }})" size="sm" variant="ghost" icon="plus" class="text-xs">
                                        إضافة خيار جديد
                                    </flux:button>

                                    <div class="mt-3 pt-2 border-t border-dashed border-zinc-200 dark:border-zinc-800">
                                        <label class="flex items-center gap-2 cursor-pointer text-xs">
                                            <input type="checkbox"
                                                wire:model="fields.{{ $index }}.allow_other"
                                                class="rounded border-zinc-300 text-accent focus:ring-accent" />
                                            <span class="text-zinc-700 dark:text-zinc-300 font-semibold">تمكين خيار "أخرى" (إدخال يدوي من المستخدم)</span>
                                        </label>
                                    </div>
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>

    {{-- Paste a block of questions and have it broken up. Nothing enters the
         form until the supervisor accepts what the parser made of it. --}}
    <flux:modal name="paste-questions" class="min-w-[32rem] max-w-2xl">
        <div class="space-y-5">
            <div>
                <flux:heading size="lg">لصق الأسئلة دفعة واحدة</flux:heading>
                <flux:subheading>
                    الصق أسئلتك كما هي وسيرتّبها النظام ويحدد نوع كل سؤال. تراجعها قبل الإضافة.
                </flux:subheading>
            </div>

            @if($parsedPreview === [])
                <flux:field>
                    <flux:label>الأسئلة</flux:label>
                    <flux:textarea wire:model="pastedQuestions" rows="10" class="font-mono text-sm"
                        placeholder="رضاك عن الحلقة:&#10;1. ما مدى رضاك عن أداء المعلم؟&#10;2. هل تواصل معك المعلم؟&#10;3. ما الجهاز الذي تستخدمه؟&#10;- جوال&#10;- حاسب&#10;&#10;٤- اقتراحاتك لتطوير الحلقة" />
                </flux:field>

                <div class="text-xs text-zinc-500 dark:text-zinc-400 bg-zinc-50 dark:bg-zinc-800/60 rounded-lg p-3 space-y-1">
                    <p class="font-semibold text-zinc-600 dark:text-zinc-300">كيف يقرأ النظام نصّك</p>
                    <p>· سطر ينتهي بنقطتين <span class="font-mono">:</span> يصير عنوان قسم</p>
                    <p>· سطر يبدأ بشرطة <span class="font-mono">-</span> يصير خياراً للسؤال الذي فوقه</p>
                    <p>· «ما مدى رضاك» مقياس · «هل …» نعم/لا · «اقتراحاتك» نص طويل · «ترشّح» مقياس ترشيح</p>
                    <p>· الترقيم يُحذف تلقائياً، عربياً كان أو إنجليزياً</p>
                </div>

                <div class="flex gap-2">
                    <flux:spacer />
                    <flux:modal.close>
                        <flux:button variant="ghost">إلغاء</flux:button>
                    </flux:modal.close>
                    <flux:button wire:click="parsePastedQuestions" variant="primary"
                        class="!bg-maroon hover:!bg-burgundy">
                        فكّك الأسئلة
                    </flux:button>
                </div>
            @else
                <div class="space-y-2 max-h-80 overflow-auto">
                    @foreach($parsedPreview as $i => $preview)
                        @if($preview['type'] === 'section')
                            <div class="pt-3 pb-1 border-b border-zinc-200 dark:border-zinc-700">
                                <span class="text-sm font-bold text-zinc-800 dark:text-zinc-100">{{ $preview['label'] }}</span>
                                <span class="text-[10px] text-zinc-400 mr-2">قسم</span>
                            </div>
                        @else
                            <div class="flex items-start justify-between gap-3 p-2.5 rounded-lg bg-zinc-50 dark:bg-zinc-800/60">
                                <div class="min-w-0">
                                    <div class="text-sm text-zinc-800 dark:text-zinc-100">{{ $preview['label'] }}</div>
                                    @if(($preview['options'] ?? []) !== [])
                                        <div class="text-xs text-zinc-400 mt-0.5 truncate">{{ implode(' · ', $preview['options']) }}</div>
                                    @endif
                                </div>
                                <flux:badge size="sm" color="zinc">{{ \App\Support\SurveyFieldTypes::label($preview['type']) }}</flux:badge>
                            </div>
                        @endif
                    @endforeach
                </div>

                <p class="text-xs text-zinc-500">
                    ستُضاف {{ count($parsedPreview) }} عنصراً بعد الحقول الحالية، ويمكنك تعديل أي نوع بعد الإضافة.
                </p>

                <div class="flex gap-2">
                    <flux:spacer />
                    <flux:button wire:click="discardParsedQuestions" variant="ghost">رجوع للنص</flux:button>
                    <flux:button wire:click="enhanceWithAi" variant="ghost" icon="sparkles"
                        wire:loading.attr="disabled" wire:target="enhanceWithAi">
                        <span wire:loading.remove wire:target="enhanceWithAi">حسّن بالذكاء</span>
                        <span wire:loading wire:target="enhanceWithAi">جارٍ التحسين…</span>
                    </flux:button>
                    <flux:button wire:click="applyParsedQuestions"
                        x-on:click="$flux.modal('paste-questions').close()"
                        variant="primary" class="!bg-maroon hover:!bg-burgundy">
                        أضف الأسئلة
                    </flux:button>
                </div>
            @endif
        </div>
    </flux:modal>
</div>
