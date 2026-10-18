{{--
    النماذج: the teacher picks a template, the students, who of them is
    asked, the kind and the reason — and sends. Each send waits below with
    its own link until it is answered.
--}}
@php
    use App\Support\Agreements as AgreementRules;
    use App\Support\AgreementText;
    use App\Support\HijriDate;

    $ar = fn ($n) => HijriDate::arabicDigits($n);
    $template = $this->template;
@endphp

<div class="space-y-5">
    <div class="rounded-2xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-5 space-y-5" data-template-send>
        <div>
            <flux:heading size="lg">إرسال نموذج</flux:heading>
            <p class="text-xs text-zinc-500">يمكنك إرسال النموذج نفسه للطالب أكثر من مرة؛ لكل إرسال رابطه وتاريخه وردّه.</p>
        </div>

        @if ($this->templates->isEmpty())
            <div class="rounded-xl border border-dashed border-zinc-300 dark:border-zinc-700 p-6 text-center text-sm text-zinc-500">
                لم يُنشر نموذج لحلقتك بعد؛ ينشئ النماذجَ المشرفُ أو الإدارة.
            </div>
        @else
            <div class="max-w-md">
                <flux:select wire:model.live="templateId" label="النموذج">
                    @foreach ($this->templates as $option)
                        <flux:select.option value="{{ $option->id }}">{{ $option->currentVersion->title }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            @if ($template)
                @php $version = $template->currentVersion; @endphp
                <details class="rounded-xl bg-zinc-50 dark:bg-zinc-800/50 px-4 py-3" wire:key="template-text-{{ $template->id }}">
                    <summary class="cursor-pointer text-sm font-medium">نص النموذج · {{ $ar(count($version->topics ?? [])) }} {{ count($version->topics ?? []) === 1 ? 'موضوع' : 'موضوعات' }}</summary>
                    <div class="mt-3 space-y-4">
                        @foreach (AgreementRules::topicsOf($version->topics) as $t => $topic)
                            <div wire:key="template-topic-{{ $template->id }}-{{ $t }}">
                                <p class="text-sm font-bold">{{ $ar($t + 1) }}. {{ AgreementText::format($topic['title']) }}</p>
                                <ol class="mt-1.5 list-[arabic-indic] space-y-1.5 ps-6 text-sm leading-6 text-zinc-700 dark:text-zinc-300">
                                    @foreach ($topic['clauses'] as $clause)
                                        <li>{{ AgreementText::format($clause) }}</li>
                                    @endforeach
                                </ol>
                            </div>
                        @endforeach
                    </div>
                </details>

                <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    <flux:radio.group wire:model="sendTo" label="إلى" variant="segmented">
                        <flux:radio value="student" label="الطالب" />
                        <flux:radio value="guardian" label="ولي الأمر" />
                        <flux:radio value="both" label="كلاهما" />
                    </flux:radio.group>

                    <flux:select wire:model="sendType" label="نوع الإقرار">
                        @foreach (AgreementRules::TYPES as $key => $label)
                            <flux:select.option value="{{ $key }}">{{ $label }}{{ $key === $version->type ? ' (كما في النموذج)' : '' }}</flux:select.option>
                        @endforeach
                    </flux:select>

                    <flux:input wire:model="note" label="سبب الإرسال" placeholder="اختياري، يظهر في الرسالة وأعلى الإقرار" maxlength="300" />
                </div>

                <div class="space-y-3">
                    <div class="flex flex-wrap items-end justify-between gap-3">
                        <flux:heading size="sm">الطلاب</flux:heading>
                        @if ($pickableCircles->count() > 1)
                            <div class="w-48">
                                <flux:select wire:model.live="sendCircle" size="sm">
                                    <flux:select.option value="">كل حلقاتي</flux:select.option>
                                    @foreach ($pickableCircles as $circle)
                                        <flux:select.option value="{{ $circle->id }}">{{ $circle->name }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                            </div>
                        @endif
                    </div>

                    @if ($pickable->isEmpty())
                        <p class="text-sm text-zinc-500">لا طلاب في حلقاتك ممن يصلهم هذا النموذج.</p>
                    @else
                        <flux:checkbox.group wire:model="recipients" data-template-recipients>
                            <div class="mb-2">
                                <flux:checkbox.all label="الكل" />
                            </div>
                            <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                                @foreach ($pickable as $student)
                                    <label wire:key="recipient-{{ $student->id }}"
                                        class="flex items-center gap-3 rounded-xl border border-zinc-200 dark:border-zinc-700 px-3 py-2 cursor-pointer hover:border-zinc-300 has-[:checked]:border-accent has-[:checked]:bg-accent/5">
                                        <flux:checkbox value="{{ $student->id }}" />
                                        <span class="min-w-0">
                                            <span class="block truncate text-sm font-medium">{{ $student->name }}</span>
                                            @if ($pickableCircles->count() > 1)
                                                <span class="block text-xs text-zinc-500">{{ $student->circle?->name }}</span>
                                            @endif
                                        </span>
                                    </label>
                                @endforeach
                            </div>
                        </flux:checkbox.group>
                    @endif
                    <flux:error name="recipients" />
                    <flux:error name="recipients.*" />
                </div>

                <div class="flex flex-wrap items-center gap-3">
                    <flux:button variant="primary" icon="paper-airplane" wire:click="send" data-template-send-button>
                        إرسال
                    </flux:button>
                    <span class="text-sm text-zinc-500" x-show="$wire.recipients.length > 0" x-cloak>
                        إلى <span class="font-semibold tabular-nums" x-text="$wire.recipients.length.toLocaleString('ar-SA')"></span> طالب
                    </span>
                </div>
            @endif
        @endif
    </div>

    <div class="space-y-3">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <flux:heading size="lg">سجل الإرسال</flux:heading>
                <p class="text-xs text-zinc-500">أرسل لكل واحد رابطه بالواتساب أو انسخه؛ ولا يُدخل الرابط أحداً إلى حسابه.</p>
            </div>
            <div class="flex flex-wrap items-end gap-2">
                <div class="w-52">
                    <flux:select wire:model.live="historyTemplate" size="sm">
                        <flux:select.option value="">كل النماذج</flux:select.option>
                        @foreach ($this->templates as $option)
                            <flux:select.option value="{{ $option->id }}">{{ $option->currentVersion->title }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>
                <div class="w-36">
                    <flux:select wire:model.live="historyShow" size="sm">
                        <flux:select.option value="">الكل</flux:select.option>
                        <flux:select.option value="owed">من لم يُقرّ</flux:select.option>
                        <flux:select.option value="signed">من أقرّ</flux:select.option>
                    </flux:select>
                </div>
            </div>
        </div>

        <div wire:loading.class="opacity-50" wire:target="historyTemplate, historyShow, send, cancelRequest" class="transition-opacity">
            <x-agreements.requests :requests="$requests" show-agreement cancellable />
        </div>
    </div>
</div>
