@php
    use App\Support\CircleRules;
    use App\Support\HijriDate;

    $ar = fn ($n) => HijriDate::arabicDigits((int) $n);
    $percent = $counts['all'] > 0 ? (int) round($counts['done'] / $counts['all'] * 100) : 0;
@endphp

<div class="space-y-5">
    @if(! $this->binding)
        <div class="rounded-2xl border border-dashed border-zinc-300 dark:border-zinc-700 p-8 text-center text-zinc-500">
            لم يُنشر إصدار من الأنظمة بعد. انشر الأنظمة من تبويب «الأنظمة» ليبدأ المعلمون والمشرفون بالإقرار بها.
        </div>
    @else
        {{-- How far the academy has come on the version that asked. --}}
        <div class="rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-4 sm:p-5 space-y-3" data-acknowledged="{{ $counts['done'] }}" data-people="{{ $counts['all'] }}">
            <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                <span class="text-2xl font-bold text-zinc-900 dark:text-white">{{ $ar($counts['done']) }} <span class="text-base font-normal text-zinc-500">من {{ $ar($counts['all']) }}</span></span>
                <span class="text-sm text-zinc-500">أقرّوا بالإصدار {{ $ar($this->binding->number) }} أو ما بعده</span>
            </div>
            <div class="h-2 rounded-full bg-zinc-100 dark:bg-zinc-800 overflow-hidden">
                <div class="h-full rounded-full bg-emerald-500" style="width: {{ $percent }}%"></div>
            </div>
        </div>

        <div class="flex flex-wrap gap-2">
            @foreach(['all' => 'الكل', 'pending' => 'لم يُقِرّ', 'done' => 'أقرّ'] as $key => $label)
                <button type="button" wire:click="$set('status', '{{ $key }}')"
                    @class([
                        'px-3 py-1.5 rounded-full text-sm font-bold transition-colors',
                        'bg-accent text-white' => $status === $key,
                        'bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-700 text-zinc-600 dark:text-zinc-300' => $status !== $key,
                    ])>{{ $label }}</button>
            @endforeach
            <span class="w-px bg-zinc-200 dark:bg-zinc-700 mx-1"></span>
            @foreach(['all' => 'الجميع', 'teacher' => 'المعلمون', 'supervisor' => 'المشرفون'] as $key => $label)
                <button type="button" wire:click="$set('role', '{{ $key }}')"
                    @class([
                        'px-3 py-1.5 rounded-full text-sm font-bold transition-colors',
                        'bg-zinc-800 text-white dark:bg-zinc-100 dark:text-zinc-900' => $role === $key,
                        'bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-700 text-zinc-600 dark:text-zinc-300' => $role !== $key,
                    ])>{{ $label }}</button>
            @endforeach
        </div>

        <div class="rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 divide-y divide-zinc-100 dark:divide-zinc-800">
            @forelse($shown as $person)
                <div wire:key="person-{{ $person['id'] }}" class="p-3 sm:p-4 flex flex-wrap items-center gap-3" data-person="{{ $person['id'] }}" data-owes="{{ $person['owed'] === [] ? 'no' : 'yes' }}">
                    <div class="flex-1 min-w-[12rem] space-y-1">
                        <div class="flex flex-wrap items-center gap-1.5">
                            <span class="font-bold text-zinc-900 dark:text-white">{{ $person['name'] }}</span>
                            @foreach($person['roles'] as $personRole)
                                <flux:badge size="sm" :color="$personRole === 'teacher' ? 'sky' : 'violet'">{{ CircleRules::ROLE_LABELS[$personRole] }}</flux:badge>
                            @endforeach
                        </div>
                        <div class="text-sm">
                            @if($person['owed'] === [])
                                <span class="text-emerald-600 dark:text-emerald-400 font-medium">أقرّ بالإصدار {{ $ar($person['acknowledgement']->version->number) }}</span>
                                <span class="text-zinc-500">· {{ HijriDate::full($person['acknowledgement']->acknowledged_at) }}</span>
                            @elseif($person['acknowledgement'])
                                <span class="text-amber-600 dark:text-amber-400 font-medium">لم يُقِرّ بصفته {{ collect($person['owed'])->map(fn ($r) => CircleRules::ROLE_LABELS[$r])->implode(' و') }} بعد</span>
                            @else
                                <span class="text-rose-600 dark:text-rose-400 font-medium">لم يُقِرّ بعد</span>
                            @endif
                        </div>
                    </div>

                    <div class="flex items-center gap-2">
                        @if($person['owed'] !== [])
                            @if($person['reminder'])
                                <flux:button size="sm" icon="chat-bubble-left-ellipsis" href="{{ $person['reminder'] }}" target="_blank" data-reminder>تذكير</flux:button>
                            @else
                                <span class="text-xs text-zinc-400">لا رقم للتذكير</span>
                            @endif
                        @endif
                        @if($person['acknowledgement'])
                            <flux:button size="sm" variant="ghost" icon="document-arrow-down" href="{{ route('manager.circle-rules.pdf', $person['acknowledgement']) }}">الإقرار PDF</flux:button>
                        @endif
                    </div>
                </div>
            @empty
                <div class="p-8 text-center text-zinc-500">لا أحد هنا.</div>
            @endforelse
        </div>
    @endif
</div>
