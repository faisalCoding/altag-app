@props(['rows', 'parties', 'showCircle' => true])

@php
    use App\Support\Agreements;
    use App\Support\HijriDate;
@endphp

{{--
    Who has given their word on an agreement, a row a student and a column a
    party: the word with its name and day, or what is still owed with that
    party's own link to copy or send. Shared by the supervisor's follow-up and
    the teacher's page.
--}}
<div class="overflow-x-auto rounded-2xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900" data-agreement-students>
    <table class="min-w-full text-sm">
        <thead class="bg-zinc-50 dark:bg-zinc-800/60 text-zinc-500">
            <tr>
                <th class="px-4 py-2.5 text-start font-medium">الطالب</th>
                @foreach ($parties as $party)
                    <th class="px-4 py-2.5 text-start font-medium">{{ Agreements::PARTIES[$party] }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
            @forelse ($rows as $row)
                <tr wire:key="agreement-student-{{ $row['student']->id }}">
                    <td class="px-4 py-2.5">
                        <div class="font-medium text-zinc-900 dark:text-zinc-100">{{ $row['student']->name }}</div>
                        @if ($showCircle && $row['student']->circle)
                            <div class="text-xs text-zinc-500">{{ $row['student']->circle->name }}</div>
                        @endif
                    </td>
                    @foreach ($parties as $party)
                        @php $standing = $row['parties'][$party]; @endphp
                        <td class="px-4 py-2.5">
                            @if ($standing['signature'])
                                <div class="flex items-center gap-1.5 text-emerald-700 dark:text-emerald-400" data-agreement-signed>
                                    <flux:icon icon="check-circle" variant="mini" class="size-4 shrink-0" />
                                    <span class="font-medium">أقرّ</span>
                                </div>
                                <div class="text-xs text-zinc-500">
                                    {{ $standing['signature']->signer_name }} · {{ HijriDate::dayMonth($standing['signature']->signed_at->setTimezone('Asia/Riyadh')) }}
                                    · {{ $standing['signature']->channel === 'account' ? 'من الحساب' : 'عبر الرابط' }}
                                </div>
                            @else
                                <div class="flex flex-wrap items-center gap-1.5" data-agreement-owed>
                                    <span class="text-amber-700 dark:text-amber-400 text-xs font-semibold me-1">لم يُقرّ</span>
                                    @if ($standing['whatsapp'])
                                        <flux:button size="xs" icon="chat-bubble-left-right" :href="$standing['whatsapp']" target="_blank" rel="noopener">واتساب</flux:button>
                                    @endif
                                    <flux:button size="xs" variant="ghost" icon="link"
                                        x-on:click="navigator.clipboard.writeText(@js($standing['link'])); $flux.toast({ text: 'نُسخ رابط {{ $party === 'guardian' ? 'ولي الأمر' : 'الطالب' }}', variant: 'success' })">نسخ الرابط</flux:button>
                                </div>
                            @endif
                        </td>
                    @endforeach
                </tr>
            @empty
                <tr>
                    <td colspan="{{ count($parties) + 1 }}" class="px-4 py-8 text-center text-zinc-500">لا يوجد طلاب هنا.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
