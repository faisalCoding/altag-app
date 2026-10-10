@php
    use App\Models\RuleClause;
    use App\Support\HijriDate;

    $current = $this->versions->first();
    $audienceTones = [
        'shared' => 'bg-zinc-800 text-white dark:bg-zinc-100 dark:text-zinc-900',
        'teacher' => 'bg-sky-600 text-white',
        'supervisor' => 'bg-violet-600 text-white',
    ];
@endphp

<div class="space-y-5">
    {{-- Where the draft stands against what teachers and supervisors read. --}}
    <div class="rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-4 sm:p-5 flex flex-col sm:flex-row sm:items-center gap-4">
        <div class="flex-1 min-w-0 space-y-1">
            <div class="font-bold text-zinc-900 dark:text-white">
                @if($current)
                    المنشور: الإصدار {{ HijriDate::arabicDigits($current->number) }}
                    <span class="text-sm font-normal text-zinc-500">· {{ HijriDate::full($current->published_at) }}</span>
                @else
                    لم تُنشر الأنظمة بعد
                @endif
            </div>
            <div class="text-sm {{ $this->unpublished ? 'text-amber-600 dark:text-amber-400' : 'text-zinc-500' }}">
                @if($this->unpublished)
                    في المسودة تعديلات لم تُنشر؛ لا يراها المعلمون والمشرفون حتى تنشرها.
                @else
                    المسودة مطابقة للمنشور.
                @endif
            </div>
        </div>
        <flux:button variant="primary" icon="paper-airplane" wire:click="openPublish" :disabled="! $this->unpublished">
            نشر إصدار جديد
        </flux:button>
    </div>

    <div class="flex flex-wrap items-center gap-2 text-xs text-zinc-500">
        <span>لكل بند جمهوره:</span>
        @foreach(RuleClause::AUDIENCES as $key => $label)
            <span class="px-2 py-0.5 rounded-full font-bold {{ $audienceTones[$key] }}">{{ $label }}</span>
        @endforeach
        <span>— يقرأ المعلم المشترك وبنود المعلمين، والمشرف المشترك وبنود المشرفين.</span>
    </div>

    @foreach($this->sections as $sectionIndex => $section)
        <div wire:key="section-{{ $section->id }}" class="rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 overflow-hidden">
            <div class="flex items-center gap-2 p-3 sm:p-4 bg-zinc-50 dark:bg-zinc-800/60 border-b border-zinc-100 dark:border-zinc-800">
                <span class="shrink-0 size-7 rounded-full bg-accent/10 text-accent dark:text-zinc-100 text-sm font-bold flex items-center justify-center">{{ HijriDate::arabicDigits($sectionIndex + 1) }}</span>
                <flux:input wire:model.blur="titles.{{ $section->id }}" class="flex-1 font-bold" placeholder="عنوان القسم" aria-label="عنوان القسم" />
                <flux:button size="sm" variant="ghost" icon="chevron-up" wire:click="moveSection({{ $section->id }}, -1)" :disabled="$sectionIndex === 0" aria-label="تقديم القسم" />
                <flux:button size="sm" variant="ghost" icon="chevron-down" wire:click="moveSection({{ $section->id }}, 1)" :disabled="$loop->last" aria-label="تأخير القسم" />
                <flux:button size="sm" variant="ghost" icon="trash" class="text-rose-500"
                    wire:click="removeSection({{ $section->id }})"
                    wire:confirm="يُحذف القسم وبنوده من المسودة. تبقى الإصدارات المنشورة كما هي. متابعة؟"
                    aria-label="حذف القسم" />
            </div>

            <div class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @foreach($section->clauses as $clauseIndex => $clause)
                    <div wire:key="clause-{{ $clause->id }}" class="p-3 sm:p-4 space-y-2">
                        <div class="flex flex-wrap items-center gap-1.5">
                            <span class="text-xs font-bold text-zinc-400 ms-0.5 me-1">{{ HijriDate::arabicDigits($clauseIndex + 1) }}</span>
                            @foreach(RuleClause::AUDIENCES as $key => $label)
                                <button type="button" wire:click="$set('audiences.{{ $clause->id }}', '{{ $key }}')"
                                    @class([
                                        'px-2.5 py-1 rounded-full text-xs font-bold transition-colors',
                                        $audienceTones[$key] => ($audiences[$clause->id] ?? 'shared') === $key,
                                        'bg-zinc-100 text-zinc-500 hover:bg-zinc-200 dark:bg-zinc-800 dark:text-zinc-400 dark:hover:bg-zinc-700' => ($audiences[$clause->id] ?? 'shared') !== $key,
                                    ])>
                                    {{ $label }}
                                </button>
                            @endforeach
                            <div class="flex-1"></div>
                            <flux:button size="xs" variant="ghost" icon="chevron-up" wire:click="moveClause({{ $clause->id }}, -1)" :disabled="$clauseIndex === 0" aria-label="تقديم البند" />
                            <flux:button size="xs" variant="ghost" icon="chevron-down" wire:click="moveClause({{ $clause->id }}, 1)" :disabled="$loop->last" aria-label="تأخير البند" />
                            <flux:button size="xs" variant="ghost" icon="x-mark" class="text-rose-500" wire:click="removeClause({{ $clause->id }})" aria-label="حذف البند" />
                        </div>
                        <flux:textarea wire:model.blur="bodies.{{ $clause->id }}" rows="2" resize="vertical" placeholder="نص البند" aria-label="نص البند" />
                    </div>
                @endforeach
            </div>

            <div class="p-3 sm:p-4 border-t border-zinc-100 dark:border-zinc-800">
                <flux:button size="sm" variant="ghost" icon="plus" wire:click="addClause({{ $section->id }})">إضافة بند</flux:button>
            </div>
        </div>
    @endforeach

    <flux:button icon="plus" wire:click="addSection" class="w-full">إضافة قسم</flux:button>

    @if($this->versions->isNotEmpty())
        <div class="rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-4 sm:p-5 space-y-3">
            <flux:heading size="sm">الإصدارات المنشورة</flux:heading>
            <div class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @foreach($this->versions as $version)
                    <div wire:key="version-{{ $version->id }}" class="py-2.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">
                        <span class="font-bold">الإصدار {{ HijriDate::arabicDigits($version->number) }}</span>
                        <span class="text-zinc-500">{{ HijriDate::full($version->published_at) }}</span>
                        @if($version->requires_acknowledgement)
                            <flux:badge size="sm" color="amber">طلب الإقرار</flux:badge>
                        @else
                            <flux:badge size="sm" color="zinc">تصحيح لفظي</flux:badge>
                        @endif
                        <span class="text-zinc-500">{{ HijriDate::arabicDigits($version->acknowledgements_count) }} إقرار</span>
                        @if($version->notes)
                            <span class="w-full text-zinc-500 text-xs">{{ $version->notes }}</span>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    <flux:modal name="publish-rules" class="md:w-[480px]">
        <div class="space-y-5">
            <div>
                <flux:heading size="lg">نشر إصدار جديد</flux:heading>
                <flux:subheading>يصل النص المنشور إلى المعلمين والمشرفين، ولا يتغير بعد نشره.</flux:subheading>
            </div>

            @if($current)
                <flux:radio.group wire:model="kind" label="نوع التعديل">
                    <flux:radio value="substantive" label="تعديل جوهري" description="يُطلب من الجميع الإقرار من جديد، ويُنبَّهون بذلك." />
                    <flux:radio value="wording" label="تصحيح لفظي" description="تبقى الإقرارات السابقة سارية، ولا يُطلب إقرار جديد." />
                </flux:radio.group>
            @else
                <div class="flex items-start gap-2 rounded-xl bg-sky-50 dark:bg-sky-900/20 text-sky-800 dark:text-sky-200 text-sm p-3">
                    <flux:icon icon="information-circle" class="size-5 shrink-0" />
                    <span>هذا أول إصدار، فيُطلب من جميع المعلمين والمشرفين الإقرار به.</span>
                </div>
            @endif

            <flux:textarea wire:model="notes" label="ما الذي تغيّر؟ (اختياري)" rows="2" />

            <div class="flex gap-2">
                <flux:spacer />
                <flux:modal.close>
                    <flux:button variant="ghost">إلغاء</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" wire:click="publish">نشر</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
