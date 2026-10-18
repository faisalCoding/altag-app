@php
    use App\Support\Agreements as AgreementRules;
    use App\Support\HijriDate;

    $ar = fn ($n) => HijriDate::arabicDigits($n);
    $statusBadge = ['published' => ['منشور', 'green'], 'draft' => ['مسودة', 'zinc'], 'archived' => ['مؤرشف', 'amber']];
@endphp

<div dir="rtl" class="space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" class="font-bold">الإقرارات</flux:heading>
            <flux:subheading>ما يُقرّ به طلاب مراحلك وأولياء أمورهم: التعهدات والسياسات والمبادئ والتوجيهات.</flux:subheading>
        </div>
        @unless ($editing)
            <flux:button variant="primary" icon="plus" wire:click="create">إقرار جديد</flux:button>
        @endunless
    </div>

    @if ($editing)
        <div class="rounded-2xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-5 space-y-5" data-agreement-editor>
            <flux:heading size="lg">{{ $editingId ? 'تعديل الإقرار' : 'إقرار جديد' }}</flux:heading>

            <div class="grid gap-4 md:grid-cols-2">
                <flux:input wire:model="title" label="عنوان الإقرار" placeholder="مثال: تعهد الالتزام بأنظمة الحلقة" />
                <flux:select wire:model="type" label="نوع الإقرار">
                    @foreach (AgreementRules::TYPES as $key => $label)
                        <flux:select.option value="{{ $key }}">{{ $label }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            <div class="grid gap-4 md:grid-cols-2">
                <flux:radio.group wire:model="audience" label="يُقرّ به">
                    @foreach (AgreementRules::AUDIENCES as $key => $label)
                        <flux:radio value="{{ $key }}" label="{{ $label }}" />
                    @endforeach
                </flux:radio.group>

                <flux:checkbox.group wire:model="stageIds" label="المراحل">
                    @foreach ($this->stages as $stage)
                        <flux:checkbox value="{{ $stage->id }}" label="{{ $stage->name }}" />
                    @endforeach
                </flux:checkbox.group>
            </div>

            <div class="space-y-2">
                <flux:label>البنود</flux:label>
                @foreach ($clauses as $index => $clause)
                    <div wire:key="clause-{{ $index }}" class="flex items-start gap-2">
                        <span class="mt-2.5 w-6 shrink-0 text-center text-sm font-bold text-zinc-400">{{ $ar($index + 1) }}</span>
                        <flux:textarea wire:model="clauses.{{ $index }}" rows="2" resize="vertical" class="flex-1" placeholder="نص البند" />
                        <div class="flex flex-col gap-1">
                            <flux:button size="xs" variant="ghost" icon="chevron-up" wire:click="moveClause({{ $index }}, -1)" :disabled="$index === 0" aria-label="أعلى" />
                            <flux:button size="xs" variant="ghost" icon="chevron-down" wire:click="moveClause({{ $index }}, 1)" :disabled="$index === count($clauses) - 1" aria-label="أسفل" />
                            <flux:button size="xs" variant="ghost" icon="trash" wire:click="removeClause({{ $index }})" aria-label="حذف البند" />
                        </div>
                    </div>
                @endforeach
                @error('clauses') <p class="text-sm text-rose-600">{{ $message }}</p> @enderror
                <flux:button size="sm" icon="plus" wire:click="addClause">إضافة بند</flux:button>
            </div>

            @php $published = $editingId && $this->agreements->firstWhere('id', $editingId)?->currentVersion; @endphp
            @if ($published)
                <flux:radio.group wire:model="change" label="عند نشر التعديلات" class="rounded-xl bg-zinc-50 dark:bg-zinc-800/50 p-3">
                    <flux:radio value="substantive" label="تعديل جوهري: يُطلب الإقرار من جديد ممن أقرّ" />
                    <flux:radio value="wording" label="تصحيح صياغة: تبقى الإقرارات السابقة قائمة" />
                </flux:radio.group>
            @endif

            <div class="flex flex-wrap gap-2 border-t border-zinc-100 dark:border-zinc-800 pt-4">
                <flux:button variant="primary" wire:click="publish">{{ $published ? 'نشر التعديلات' : 'نشر' }}</flux:button>
                <flux:button wire:click="saveDraft">حفظ دون نشر</flux:button>
                <flux:button variant="ghost" wire:click="cancel">إلغاء</flux:button>
            </div>
        </div>
    @endif

    @if ($this->agreements->isEmpty() && ! $editing)
        <div class="rounded-2xl border border-dashed border-zinc-300 dark:border-zinc-700 p-10 text-center text-zinc-500">
            لا توجد إقرارات بعد. ابدأ بـ«إقرار جديد».
        </div>
    @endif

    <div class="grid gap-4 lg:grid-cols-2">
        @foreach ($this->agreements as $agreement)
            @php
                $version = $agreement->currentVersion;
                $unpublished = $version && ($version->title !== $agreement->title || $version->type !== $agreement->type || $version->audience !== $agreement->audience || $version->clauses !== $agreement->clauses);
                [$statusLabel, $statusColor] = $statusBadge[$agreement->status] ?? [$agreement->status, 'zinc'];
            @endphp
            <div wire:key="agreement-{{ $agreement->id }}" class="rounded-2xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-5 space-y-3" data-agreement-card="{{ $agreement->id }}">
                <div class="flex items-start justify-between gap-3">
                    <div class="space-y-1 min-w-0">
                        <div class="flex flex-wrap items-center gap-1.5">
                            <flux:badge size="sm" :color="$statusColor">{{ $statusLabel }}</flux:badge>
                            <flux:badge size="sm">{{ AgreementRules::TYPES[$agreement->type] ?? $agreement->type }}</flux:badge>
                            <flux:badge size="sm" color="sky">{{ AgreementRules::AUDIENCES[$agreement->audience] ?? $agreement->audience }}</flux:badge>
                            @if ($version)
                                <span class="text-xs text-zinc-500">الإصدار {{ $ar($version->number) }}</span>
                            @endif
                        </div>
                        <flux:heading size="lg" class="truncate">{{ $agreement->title }}</flux:heading>
                        <p class="text-xs text-zinc-500">{{ $agreement->stages->pluck('name')->implode('، ') }} · {{ $ar(count($agreement->clauses)) }} بنود</p>
                        @if ($unpublished)
                            <p class="text-xs font-medium text-amber-600 dark:text-amber-400">فيه تعديلات لم تُنشر بعد.</p>
                        @endif
                    </div>
                </div>

                @if (isset($counts[$agreement->id]))
                    <div class="grid gap-2 {{ count($counts[$agreement->id]) > 1 ? 'grid-cols-2' : 'grid-cols-1' }}">
                        @foreach ($counts[$agreement->id] as $party => $count)
                            @php $rate = $count['total'] > 0 ? round($count['signed'] / $count['total'] * 100) : 0; @endphp
                            <div class="rounded-xl bg-zinc-50 dark:bg-zinc-800/60 px-3 py-2">
                                <div class="flex items-center justify-between text-xs text-zinc-500">
                                    <span>{{ AgreementRules::PARTIES[$party] }}</span>
                                    <span class="tabular-nums">{{ $ar($count['signed']) }} من {{ $ar($count['total']) }}</span>
                                </div>
                                <div class="mt-1.5 h-1.5 rounded-full bg-zinc-200 dark:bg-zinc-700 overflow-hidden">
                                    <div class="h-full rounded-full bg-emerald-500" style="width: {{ $rate }}%"></div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif

                <div class="flex flex-wrap gap-2 pt-1">
                    @if ($version && $agreement->status !== 'archived')
                        <flux:button size="sm" variant="primary" icon="users" :href="route('supervisor.agreements.follow-up', $agreement)" wire:navigate>المتابعة والإرسال</flux:button>
                    @endif
                    @if ($agreement->status !== 'archived')
                        <flux:button size="sm" icon="pencil-square" wire:click="edit({{ $agreement->id }})">تعديل</flux:button>
                    @endif
                    @if ($agreement->status === 'archived')
                        <flux:button size="sm" icon="arrow-uturn-right" wire:click="restore({{ $agreement->id }})">استعادة</flux:button>
                    @elseif ($version)
                        <flux:button size="sm" variant="ghost" icon="archive-box" wire:click="archive({{ $agreement->id }})" wire:confirm="أرشفة الإقرار توقف طلبه من الطلاب وأولياء الأمور، وتبقى الإقرارات المسجّلة. متابعة؟">أرشفة</flux:button>
                    @else
                        <flux:button size="sm" variant="ghost" icon="trash" wire:click="delete({{ $agreement->id }})" wire:confirm="حذف المسودة نهائياً؟">حذف</flux:button>
                    @endif
                </div>
            </div>
        @endforeach
    </div>
</div>
