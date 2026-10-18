@php
    use App\Support\Agreements;
    use App\Support\HijriDate;
    use Illuminate\Support\Carbon;

    $ar = fn ($n) => HijriDate::arabicDigits($n);
    $rate = fn (int $n, int $total) => $total > 0 ? (int) round($n / $total * 100) : 0;
    $partyLabels = ['student' => 'الطلاب', 'guardian' => 'أولياء الأمور'];
@endphp

<div dir="rtl" class="space-y-6" data-agreement-reports>
    <div class="flex flex-col sm:flex-row sm:items-end justify-between gap-4">
        <div class="w-full sm:w-64">
            <flux:radio.group wire:model.live="mode" variant="segmented" aria-label="نوع التقرير">
                <flux:radio value="general" label="تقرير عام" />
                <flux:radio value="individual" label="تقرير فردي" />
            </flux:radio.group>
        </div>
        @if ($mode === 'general')
            <div class="w-full sm:w-52">
                <flux:select wire:model.live="stage" label="المرحلة">
                    <flux:select.option value="">كل المراحل</flux:select.option>
                    @foreach ($this->stages as $option)
                        <flux:select.option value="{{ $option->id }}">{{ $option->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
        @endif
    </div>

    <div wire:loading.class="opacity-50" wire:target="mode, stage, agreementId, template, pickStudent, clearStudent" class="space-y-6 transition-opacity">
        @if ($mode === 'general')
            {{-- The general agreements, each party's rate. --}}
            <section class="space-y-3">
                <flux:heading size="lg">الإقرارات العامة</flux:heading>

                @if ($overview->isEmpty())
                    <div class="rounded-2xl border border-dashed border-zinc-300 dark:border-zinc-700 p-8 text-center text-sm text-zinc-500">لا إقرارات عامة منشورة لهذه المراحل.</div>
                @else
                    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                        @foreach ($overview as $entry)
                            @php $isPicked = $picked && $picked['agreement']->id === $entry['agreement']->id; @endphp
                            <button type="button" wire:key="overview-{{ $entry['agreement']->id }}" wire:click="$set('agreementId', {{ $entry['agreement']->id }})"
                                aria-pressed="{{ $isPicked ? 'true' : 'false' }}" data-report-agreement="{{ $entry['agreement']->id }}"
                                class="rounded-2xl border bg-white dark:bg-zinc-900 p-4 text-start space-y-3 transition-colors {{ $isPicked ? 'border-accent ring-1 ring-accent' : 'border-zinc-200 dark:border-zinc-700 hover:border-zinc-300' }}">
                                <div>
                                    <div class="font-semibold">{{ $entry['agreement']->currentVersion->title }}</div>
                                    <div class="text-xs text-zinc-500">
                                        {{ Agreements::TYPES[$entry['agreement']->currentVersion->type] ?? '' }} ·
                                        {{ $entry['agreement']->all_stages ? 'جميع المراحل' : $entry['agreement']->stages->pluck('name')->implode('، ') }}
                                    </div>
                                </div>
                                @foreach ($entry['parties'] as $party => $count)
                                    @php $percent = $rate($count['signed'], $count['total']); @endphp
                                    <div data-report-party="{{ $party }}">
                                        <div class="flex items-center justify-between text-xs">
                                            <span class="text-zinc-500">أقرّ من {{ $partyLabels[$party] }}</span>
                                            <span class="tabular-nums font-semibold">{{ $ar($count['signed']) }} / {{ $ar($count['total']) }} · {{ $ar($percent) }}٪</span>
                                        </div>
                                        <div class="mt-1 h-1.5 rounded-full bg-zinc-100 dark:bg-zinc-800 overflow-hidden">
                                            <div class="h-full rounded-full bg-emerald-500" style="width: {{ $percent }}%"></div>
                                        </div>
                                    </div>
                                @endforeach
                            </button>
                        @endforeach
                    </div>

                    @if ($picked)
                        <div class="space-y-2">
                            <flux:heading size="sm">حسب الحلقة · {{ $picked['agreement']->currentVersion->title }}</flux:heading>
                            <div class="overflow-x-auto rounded-2xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900" data-report-circles>
                                <table class="min-w-full text-sm">
                                    <thead class="bg-zinc-50 dark:bg-zinc-800/60 text-zinc-500">
                                        <tr>
                                            <th class="px-4 py-2.5 text-start font-medium">الحلقة</th>
                                            <th class="px-4 py-2.5 text-start font-medium">المرحلة</th>
                                            <th class="px-4 py-2.5 text-start font-medium">الطلاب</th>
                                            @foreach (array_keys($picked['parties']) as $party)
                                                <th class="px-4 py-2.5 text-start font-medium">أقرّ من {{ $partyLabels[$party] }}</th>
                                            @endforeach
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                                        @foreach ($byCircle as $i => $row)
                                            <tr wire:key="circle-row-{{ $picked['agreement']->id }}-{{ $i }}">
                                                <td class="px-4 py-2.5 font-medium">{{ $row['circle'] }}</td>
                                                <td class="px-4 py-2.5 text-zinc-500">{{ $row['stage'] }}</td>
                                                <td class="px-4 py-2.5 tabular-nums">{{ $ar($row['total']) }}</td>
                                                @foreach ($row['parties'] as $party => $signed)
                                                    @php $percent = $rate($signed, $row['total']); @endphp
                                                    <td class="px-4 py-2.5">
                                                        <div class="flex items-center gap-2">
                                                            <div class="h-1.5 w-20 rounded-full bg-zinc-100 dark:bg-zinc-800 overflow-hidden">
                                                                <div class="h-full rounded-full {{ $percent === 100 ? 'bg-emerald-500' : ($percent >= 50 ? 'bg-amber-400' : 'bg-rose-400') }}" style="width: {{ $percent }}%"></div>
                                                            </div>
                                                            <span class="tabular-nums text-xs">{{ $ar($signed) }} · {{ $ar($percent) }}٪</span>
                                                        </div>
                                                    </td>
                                                @endforeach
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    @endif
                @endif
            </section>

            {{-- The templates: sent and answered, by template and by teacher. --}}
            <section class="space-y-3">
                <div class="flex flex-wrap items-end justify-between gap-3">
                    <flux:heading size="lg">النماذج المرسلة</flux:heading>
                    <div class="w-56">
                        <flux:select wire:model.live="template" size="sm">
                            <flux:select.option value="">كل النماذج</flux:select.option>
                            @foreach ($this->templates as $option)
                                <flux:select.option value="{{ $option->id }}">{{ $option->currentVersion?->title ?? $option->title }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    </div>
                </div>

                @if ($byTemplate->isEmpty())
                    <div class="rounded-2xl border border-dashed border-zinc-300 dark:border-zinc-700 p-8 text-center text-sm text-zinc-500">لم يُرسل المعلمون نموذجاً بعد.</div>
                @else
                    <div class="grid gap-4 xl:grid-cols-2">
                        <div class="overflow-x-auto rounded-2xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900" data-report-templates>
                            <table class="min-w-full text-sm">
                                <thead class="bg-zinc-50 dark:bg-zinc-800/60 text-zinc-500">
                                    <tr>
                                        <th class="px-4 py-2.5 text-start font-medium">النموذج</th>
                                        <th class="px-4 py-2.5 text-start font-medium">أُرسل</th>
                                        <th class="px-4 py-2.5 text-start font-medium">أُجيب</th>
                                        <th class="px-4 py-2.5 text-start font-medium">المعلمون</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                                    @foreach ($byTemplate as $i => $row)
                                        <tr wire:key="template-row-{{ $i }}">
                                            <td class="px-4 py-2.5 font-medium">{{ $row['title'] }}</td>
                                            <td class="px-4 py-2.5 tabular-nums">{{ $ar($row['sent']) }}</td>
                                            <td class="px-4 py-2.5 tabular-nums">{{ $ar($row['signed']) }} <span class="text-xs text-zinc-500">· {{ $ar($rate($row['signed'], $row['sent'])) }}٪</span></td>
                                            <td class="px-4 py-2.5 tabular-nums">{{ $ar($row['teachers']) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <div class="overflow-x-auto rounded-2xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900" data-report-teachers>
                            <table class="min-w-full text-sm">
                                <thead class="bg-zinc-50 dark:bg-zinc-800/60 text-zinc-500">
                                    <tr>
                                        <th class="px-4 py-2.5 text-start font-medium">المعلم</th>
                                        <th class="px-4 py-2.5 text-start font-medium">أُرسل</th>
                                        <th class="px-4 py-2.5 text-start font-medium">أُجيب</th>
                                        <th class="px-4 py-2.5 text-start font-medium">آخر إرسال</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                                    @foreach ($byTeacher as $i => $row)
                                        <tr wire:key="teacher-row-{{ $i }}">
                                            <td class="px-4 py-2.5">
                                                <div class="font-medium">{{ $row['name'] }}</div>
                                                @if ($row['circles'])
                                                    <div class="text-xs text-zinc-500">{{ $row['circles'] }}</div>
                                                @endif
                                            </td>
                                            <td class="px-4 py-2.5 tabular-nums">{{ $ar($row['sent']) }}</td>
                                            <td class="px-4 py-2.5 tabular-nums">{{ $ar($row['signed']) }} <span class="text-xs text-zinc-500">· {{ $ar($rate($row['signed'], $row['sent'])) }}٪</span></td>
                                            <td class="px-4 py-2.5 text-xs text-zinc-500 whitespace-nowrap">{{ $row['last'] ? HijriDate::dayMonth(Carbon::parse($row['last'], 'UTC')->setTimezone('Asia/Riyadh')) : '—' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endif
            </section>
        @else
            {{-- One student: their search, then where they stand. --}}
            @unless ($student)
                <div class="max-w-md space-y-2">
                    <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" label="الطالب" placeholder="اكتب حرفين من اسمه على الأقل" />
                    @if ($this->matches->isNotEmpty())
                        <div class="rounded-2xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 divide-y divide-zinc-100 dark:divide-zinc-800 overflow-hidden" data-report-matches>
                            @foreach ($this->matches as $match)
                                <button type="button" wire:key="match-{{ $match->id }}" wire:click="pickStudent({{ $match->id }})"
                                    class="flex w-full items-center justify-between gap-3 px-4 py-2.5 text-start hover:bg-zinc-50 dark:hover:bg-zinc-800">
                                    <span class="font-medium">{{ $match->name }}</span>
                                    <span class="text-xs text-zinc-500">{{ $match->circle?->name ?? 'بلا حلقة' }}{{ $match->status !== 'active' ? ' · غير نشط' : '' }}</span>
                                </button>
                            @endforeach
                        </div>
                    @elseif (mb_strlen(trim($search)) >= 2)
                        <p class="text-sm text-zinc-500">لا طالب بهذا الاسم.</p>
                    @endif
                </div>
            @else
                <div class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-5 py-4" data-report-student="{{ $student->id }}">
                    <div>
                        <div class="text-lg font-bold">{{ $student->name }}</div>
                        <div class="text-sm text-zinc-500">
                            {{ $student->circle?->name ?? 'بلا حلقة' }}{{ $stageName ? ' · '.$stageName : '' }}
                            · ولي الأمر: {{ $student->guardian?->name ?? 'غير مسجل' }}
                        </div>
                    </div>
                    <flux:button size="sm" variant="ghost" icon="arrow-path" wire:click="clearStudent">طالب آخر</flux:button>
                </div>

                <section class="space-y-2">
                    <flux:heading size="lg">الإقرارات العامة</flux:heading>
                    @if ($standings === [])
                        <p class="text-sm text-zinc-500">لا إقرارات عامة تطلبها مرحلته.</p>
                    @else
                        <div class="overflow-x-auto rounded-2xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900" data-report-standings>
                            <table class="min-w-full text-sm">
                                <thead class="bg-zinc-50 dark:bg-zinc-800/60 text-zinc-500">
                                    <tr>
                                        <th class="px-4 py-2.5 text-start font-medium">الإقرار</th>
                                        <th class="px-4 py-2.5 text-start font-medium">الطالب</th>
                                        <th class="px-4 py-2.5 text-start font-medium">ولي الأمر</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                                    @foreach ($standings as $entry)
                                        <tr wire:key="standing-{{ $entry['agreement']->id }}">
                                            <td class="px-4 py-2.5">
                                                <div class="font-medium">{{ $entry['agreement']->currentVersion->title }}</div>
                                                <div class="text-xs text-zinc-500">{{ Agreements::TYPES[Agreements::typeFor($entry['agreement'], $student->circle_id)] ?? '' }} · الإصدار {{ $ar($entry['agreement']->currentVersion->number) }}</div>
                                            </td>
                                            @foreach (['student', 'guardian'] as $party)
                                                <td class="px-4 py-2.5">
                                                    @if (! array_key_exists($party, $entry['parties']))
                                                        <span class="text-xs text-zinc-400">لا يُطلب منه</span>
                                                    @elseif ($signature = $entry['parties'][$party]['signature'])
                                                        <div class="flex items-center gap-1.5 text-emerald-700 dark:text-emerald-400">
                                                            <flux:icon icon="check-circle" variant="mini" class="size-4" />
                                                            <span class="font-medium">أقرّ</span>
                                                        </div>
                                                        <div class="text-xs text-zinc-500">{{ $signature->signer_name }} · {{ HijriDate::dayMonth($signature->signed_at->setTimezone('Asia/Riyadh')) }}</div>
                                                    @else
                                                        <div class="flex flex-wrap items-center gap-1.5">
                                                            <span class="text-amber-700 dark:text-amber-400 text-xs font-semibold">لم يُقرّ</span>
                                                            <flux:button size="xs" variant="ghost" icon="link"
                                                                x-on:click="navigator.clipboard.writeText(@js($entry['parties'][$party]['link'])); $flux.toast({ text: 'نُسخ رابط الإقرار', variant: 'success' })">نسخ الرابط</flux:button>
                                                        </div>
                                                    @endif
                                                </td>
                                            @endforeach
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </section>

                <section class="space-y-2">
                    <flux:heading size="lg">النماذج المرسلة إليه</flux:heading>
                    <x-agreements.requests :requests="$requests" :show-student="false" show-agreement />
                    @if ($cancelled > 0)
                        <p class="text-xs text-zinc-500">وأُلغي {{ $ar($cancelled) }} {{ $cancelled === 1 ? 'طلب' : 'طلبات' }} قبل الإجابة.</p>
                    @endif
                </section>
            @endunless
        @endif
    </div>
</div>
