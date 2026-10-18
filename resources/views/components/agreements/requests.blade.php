@props(['requests', 'showStudent' => true, 'showAgreement' => false, 'cancellable' => false])

@php
    use App\Support\Agreements;
    use App\Support\HijriDate;
@endphp

{{--
    The sends of template agreements, newest first: to whom, under what kind
    and note, by whom and when — and the answer, or the send's own link to
    copy or send by WhatsApp while it waits. Shared by the follow-up, the
    teacher's page and the manager's reports.
--}}
<div class="overflow-x-auto rounded-2xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900" data-agreement-requests>
    <table class="min-w-full text-sm">
        <thead class="bg-zinc-50 dark:bg-zinc-800/60 text-zinc-500">
            <tr>
                @if ($showStudent)
                    <th class="px-4 py-2.5 text-start font-medium">الطالب</th>
                @endif
                @if ($showAgreement)
                    <th class="px-4 py-2.5 text-start font-medium">الإقرار</th>
                @endif
                <th class="px-4 py-2.5 text-start font-medium">إلى</th>
                <th class="px-4 py-2.5 text-start font-medium">السبب</th>
                <th class="px-4 py-2.5 text-start font-medium">أُرسل</th>
                <th class="px-4 py-2.5 text-start font-medium">الحالة</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
            @forelse ($requests as $request)
                <tr wire:key="agreement-request-{{ $request->id }}">
                    @if ($showStudent)
                        <td class="px-4 py-2.5">
                            <div class="font-medium text-zinc-900 dark:text-zinc-100">{{ $request->student?->name }}</div>
                            @if ($request->student?->circle)
                                <div class="text-xs text-zinc-500">{{ $request->student->circle->name }}</div>
                            @endif
                        </td>
                    @endif
                    @if ($showAgreement)
                        <td class="px-4 py-2.5">
                            <div class="font-medium">{{ $request->version?->title }}</div>
                            <div class="text-xs text-zinc-500">{{ Agreements::TYPES[$request->type] ?? $request->type }}</div>
                        </td>
                    @endif
                    <td class="px-4 py-2.5 whitespace-nowrap">
                        {{ Agreements::PARTIES[$request->party] ?? $request->party }}
                        @unless ($showAgreement)
                            <div class="text-xs text-zinc-500">{{ Agreements::TYPES[$request->type] ?? $request->type }}</div>
                        @endunless
                    </td>
                    <td class="px-4 py-2.5 text-zinc-600 dark:text-zinc-300 max-w-60">{{ $request->note ?? '—' }}</td>
                    <td class="px-4 py-2.5 whitespace-nowrap text-xs text-zinc-500">
                        {{ HijriDate::dayMonth($request->sent_at->setTimezone('Asia/Riyadh')) }}
                        @if ($request->sender)
                            <div>{{ $request->sender->name }}</div>
                        @endif
                    </td>
                    <td class="px-4 py-2.5">
                        @if ($request->signed_at)
                            <div class="flex items-center gap-1.5 text-emerald-700 dark:text-emerald-400" data-request-signed>
                                <flux:icon icon="check-circle" variant="mini" class="size-4 shrink-0" />
                                <span class="font-medium">أقرّ</span>
                            </div>
                            <div class="text-xs text-zinc-500">
                                {{ $request->signer_name }} · {{ HijriDate::dayMonth($request->signed_at->setTimezone('Asia/Riyadh')) }}
                                · {{ $request->channel === 'account' ? 'من الحساب' : 'عبر الرابط' }}
                            </div>
                        @else
                            @php $whatsapp = Agreements::requestWhatsappUrl($request); @endphp
                            <div class="flex flex-wrap items-center gap-1.5" data-request-open>
                                <span class="text-amber-700 dark:text-amber-400 text-xs font-semibold me-1">لم يُقرّ</span>
                                @if ($whatsapp)
                                    <flux:button size="xs" icon="chat-bubble-left-right" :href="$whatsapp" target="_blank" rel="noopener">واتساب</flux:button>
                                @endif
                                <flux:button size="xs" variant="ghost" icon="link"
                                    x-on:click="navigator.clipboard.writeText(@js(Agreements::requestLink($request))); $flux.toast({ text: 'نُسخ رابط الإقرار', variant: 'success' })">نسخ الرابط</flux:button>
                                @if ($cancellable)
                                    <flux:button size="xs" variant="ghost" icon="x-mark" wire:click="cancelRequest({{ $request->id }})"
                                        wire:confirm="إلغاء هذا الطلب؟ لن يُطلب الإقرار عبر رابطه بعد ذلك." data-request-cancel>إلغاء</flux:button>
                                @endif
                            </div>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="px-4 py-8 text-center text-zinc-500">{{ $showAgreement ? 'لا إرسال بعد.' : 'لم يُرسَل هذا الإقرار بعد.' }}</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
