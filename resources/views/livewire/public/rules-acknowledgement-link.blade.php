@php
    use App\Support\Branding;
    use App\Support\CircleRules;
    use App\Support\HijriDate;

    $version = $this->version;
    $stage = $stageId ? $this->stages->firstWhere('id', $stageId) : null;
    $person = $this->person;
@endphp

<div class="w-full max-w-xl space-y-5">
    @push('meta')
        <meta name="robots" content="noindex, nofollow">
        <title>الإقرار بأنظمة الحلقات - {{ Branding::siteName() }}</title>
    @endpush

    <div class="flex flex-col items-center text-center gap-2">
        <img src="{{ Branding::logoUrl() }}" alt="" class="size-16 object-contain">
        <h1 class="text-xl font-bold text-zinc-900">الإقرار بأنظمة الحلقات</h1>
        <p class="text-sm text-zinc-500">{{ Branding::siteName() }}</p>
    </div>

    @if(! $version)
        <div class="rounded-2xl border border-dashed border-zinc-300 p-8 text-center text-zinc-500">
            لم تُنشر أنظمة الحلقات بعد.
        </div>
    @elseif($doneName)
        <div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-6 text-center space-y-3" data-rules-link-done>
            <flux:icon icon="check-badge" class="size-10 text-emerald-600 mx-auto" />
            <p class="font-bold text-emerald-900">شكراً لك {{ $doneName }}، حُفظ إقرارك بالأنظمة.</p>
            <flux:button wire:click="startOver" variant="ghost">إقرار شخص آخر</flux:button>
        </div>
    @elseif(! $stage)
        {{-- 1. The stage. --}}
        <div class="space-y-3">
            <p class="font-bold text-zinc-800">اختر مرحلتك</p>
            <div class="grid grid-cols-2 gap-2.5">
                @foreach($this->stages as $candidate)
                    <button type="button" wire:click="chooseStage({{ $candidate->id }})" wire:key="stage-{{ $candidate->id }}"
                        class="rounded-xl border border-zinc-200 bg-white px-4 py-3.5 text-sm font-bold text-zinc-800 hover:border-accent hover:text-accent transition-colors">
                        {{ $candidate->name }}
                    </button>
                @endforeach
            </div>
        </div>
    @elseif(! $person)
        {{-- 2. The name. --}}
        <div class="space-y-3">
            <div class="flex items-center justify-between gap-3">
                <p class="font-bold text-zinc-800">اختر اسمك <span class="font-normal text-zinc-500">· {{ $stage->name }}</span></p>
                <button type="button" wire:click="back" class="text-sm text-zinc-500 hover:text-zinc-800">تغيير المرحلة</button>
            </div>
            <div class="rounded-2xl border border-zinc-200 bg-white divide-y divide-zinc-100">
                @forelse($this->people as $candidate)
                    @php
                        $roles = CircleRules::rolesOf($candidate);
                        $owes = in_array($candidate->id, $this->owing, true);
                    @endphp
                    <button type="button" wire:key="person-{{ $candidate->id }}"
                        @if($owes) wire:click="choosePerson({{ $candidate->id }})" @else disabled @endif
                        @class([
                            'w-full flex items-center gap-3 px-4 py-3 text-start',
                            'hover:bg-zinc-50' => $owes,
                            'opacity-60 cursor-default' => ! $owes,
                        ])>
                        <span class="flex-1 font-medium text-zinc-900">{{ $candidate->name }}</span>
                        <span class="text-xs text-zinc-500">{{ collect($roles)->map(fn ($r) => CircleRules::ROLE_LABELS[$r])->implode(' و') }}</span>
                        @if($owes)
                            <flux:icon icon="chevron-left" class="size-4 text-zinc-400" />
                        @else
                            <span class="text-xs font-bold text-emerald-600">أقرّ</span>
                        @endif
                    </button>
                @empty
                    <p class="p-6 text-center text-sm text-zinc-500">لا معلمين ولا مشرفين في هذه المرحلة.</p>
                @endforelse
            </div>
        </div>
    @else
        {{-- 3. The rules, and the word. --}}
        <div class="space-y-4">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <p class="font-bold text-zinc-900">{{ $person->name }}</p>
                    <p class="text-xs text-zinc-500">الإصدار {{ HijriDate::arabicDigits($version->number) }} · نُشر {{ HijriDate::full($version->published_at) }}</p>
                </div>
                <button type="button" wire:click="back" class="text-sm text-zinc-500 hover:text-zinc-800">لستُ {{ explode(' ', trim($person->name))[0] }}</button>
            </div>

            <x-rules.text :text="$this->text" />

            <div class="rounded-2xl border border-zinc-200 bg-white p-5 space-y-4">
                <flux:checkbox wire:model="agreed" label="قرأتُ هذه الأنظمة وأتعهد بالالتزام بها" />
                <flux:error name="agreed" />
                <flux:button variant="primary" icon="check" wire:click="acknowledge" x-bind:disabled="! $wire.agreed" class="w-full">
                    إقرار
                </flux:button>
            </div>
        </div>
    @endif
</div>
