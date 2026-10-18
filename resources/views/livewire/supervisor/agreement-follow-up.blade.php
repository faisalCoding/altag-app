@php
    use App\Support\Agreements;
    use App\Support\HijriDate;

    $ar = fn ($n) => HijriDate::arabicDigits($n);
    $version = $this->agreement->currentVersion;
@endphp

<div dir="rtl" class="space-y-5">
    <div class="flex flex-col lg:flex-row lg:items-end justify-between gap-4">
        <div class="space-y-1">
            <flux:button size="sm" variant="ghost" icon="arrow-right" :href="$this->backUrl()" wire:navigate>الإقرارات</flux:button>
            <flux:heading size="xl" class="font-bold">{{ $version->title }}</flux:heading>
            <flux:subheading>
                {{ $this->agreement->isTemplate() ? 'نموذج يرسله المعلم' : 'إقرار عام' }} ·
                {{ Agreements::TYPES[$version->type] ?? $version->type }} · {{ Agreements::AUDIENCES[$version->audience] }} · الإصدار {{ $ar($version->number) }}
                · {{ $this->agreement->all_stages ? 'جميع المراحل' : $this->agreement->stages->pluck('name')->implode('، ') }}
            </flux:subheading>
        </div>
        <div class="flex flex-wrap items-end gap-2">
            <div class="w-48">
                <flux:select wire:model.live="circle" label="الحلقة">
                    <flux:select.option value="">كل الحلقات</flux:select.option>
                    @foreach ($this->circles as $option)
                        <flux:select.option value="{{ $option->id }}">{{ $option->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
            <div class="w-40">
                <flux:select wire:model.live="show" label="عرض">
                    <flux:select.option value="">الكل</flux:select.option>
                    <flux:select.option value="owed">من لم يُقرّ</flux:select.option>
                    <flux:select.option value="signed">من أقرّ</flux:select.option>
                </flux:select>
            </div>
        </div>
    </div>

    @if ($requests !== null)
        <div class="grid gap-3 sm:grid-cols-3" data-template-totals>
            @foreach ([['أُرسل', $totals['sent']], ['أُجيب', $totals['signed']], ['طالباً أُرسل إليهم', $totals['students']]] as [$label, $value])
                <div class="rounded-2xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-4 py-3">
                    <div class="text-sm text-zinc-500">{{ $label }}</div>
                    <div class="text-2xl font-bold tabular-nums">{{ $ar($value) }}</div>
                </div>
            @endforeach
        </div>

        <p class="text-xs text-zinc-500">كل إرسال من المعلم طلبٌ مستقل له رابطه وتاريخه وردّه؛ ولا يُدخل الرابط أحداً إلى حسابه.</p>

        <div wire:loading.class="opacity-50" wire:target="circle, show" class="transition-opacity">
            <x-agreements.requests :requests="$requests" />
        </div>
    @else
    <div class="grid gap-3 {{ count($parties) > 1 ? 'sm:grid-cols-2' : '' }}">
        @foreach ($totals as $party => $count)
            @php $rate = $count['total'] > 0 ? round($count['signed'] / $count['total'] * 100) : 0; @endphp
            <div class="rounded-2xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-4 py-3" data-agreement-total="{{ $party }}">
                <div class="flex items-center justify-between">
                    <span class="text-sm text-zinc-500">أقرّ من {{ $party === 'guardian' ? 'أولياء الأمور' : 'الطلاب' }}</span>
                    <span class="text-lg font-bold tabular-nums">{{ $ar($count['signed']) }} <span class="text-sm font-normal text-zinc-400">من {{ $ar($count['total']) }}</span></span>
                </div>
                <div class="mt-2 h-2 rounded-full bg-zinc-100 dark:bg-zinc-800 overflow-hidden">
                    <div class="h-full rounded-full bg-emerald-500" style="width: {{ $rate }}%"></div>
                </div>
            </div>
        @endforeach
    </div>

    <p class="text-xs text-zinc-500">لكل طالب رابط خاص به، ولولي أمره رابط آخر؛ لا يُدخل الرابط أحداً إلى حسابه، فيمكن إرساله بأمان.</p>

    <div wire:loading.class="opacity-50" wire:target="circle, show" class="transition-opacity">
        <x-agreements.students :rows="$rows" :parties="$parties" />
    </div>
    @endif
</div>
