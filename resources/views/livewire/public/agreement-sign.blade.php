@php
    use App\Support\Agreements;
    use App\Support\Branding;
    use App\Support\HijriDate;

    $owed = $agreements->whereNull('signature')->count();
@endphp

<div class="w-full max-w-xl space-y-5">
    @push('meta')
        <meta name="robots" content="noindex, nofollow">
        <title>إقرارات الحلقة - {{ Branding::siteName() }}</title>
    @endpush

    <div class="flex flex-col items-center text-center gap-2">
        <img src="{{ Branding::logoUrl() }}" alt="" class="size-16 object-contain">
        <h1 class="text-xl font-bold text-zinc-900">إقرارات الحلقة</h1>
        <p class="text-sm text-zinc-600">
            @if ($party === 'guardian')
                لولي أمر الطالب <span class="font-semibold text-zinc-900">{{ $student->name }}</span>
            @else
                للطالب <span class="font-semibold text-zinc-900">{{ $student->name }}</span>
            @endif
            @if ($student->circle)
                · {{ $student->circle->name }}
            @endif
        </p>
    </div>

    @if ($agreements->isEmpty())
        <div class="rounded-2xl border border-dashed border-zinc-300 p-8 text-center text-zinc-500" data-agreements-none>
            لا توجد إقرارات مطلوبة الآن.
        </div>
    @else
        <div class="rounded-2xl px-4 py-3 text-sm {{ $owed > 0 ? 'bg-amber-50 text-amber-900 border border-amber-200' : 'bg-emerald-50 text-emerald-900 border border-emerald-200' }}" data-agreements-progress>
            @if ($owed > 0)
                بقي {{ HijriDate::arabicDigits($owed) }} من {{ HijriDate::arabicDigits($agreements->count()) }}: اقرأ كل إقرار ثم ضع علامتك واضغط «إقرار».
            @else
                جزاكم الله خيراً، اكتملت الإقرارات كلها.
            @endif
        </div>

        @if ($party === 'guardian' && $owed > 0)
            <div class="rounded-2xl border border-zinc-200 bg-white p-4 space-y-2">
                @if ($signedIn)
                    <p class="text-sm text-zinc-600">تُقرّ باسم <span class="font-semibold text-zinc-900">{{ $signedIn->name }}</span> من حسابك.</p>
                @else
                    <flux:input wire:model.blur="signerName" label="اسم ولي الأمر" placeholder="الاسم كما تريد أن يُسجَّل في الإقرار" required />
                @endif
            </div>
        @endif

        @foreach ($agreements as $item)
            @php
                $agreement = $item['agreement'];
                $version = $agreement->currentVersion;
                $signature = $item['signature'];
            @endphp
            <article wire:key="agreement-{{ $agreement->id }}" class="rounded-2xl border bg-white p-5 space-y-4 {{ $signature ? 'border-emerald-200' : 'border-zinc-200' }}" data-agreement="{{ $agreement->id }}">
                <header class="space-y-1">
                    <span class="inline-block rounded-full bg-zinc-100 px-2.5 py-0.5 text-xs font-semibold text-zinc-700">{{ Agreements::TYPES[$item['type']] ?? $item['type'] }}</span>
                    <h2 class="text-lg font-bold text-zinc-900">{{ $version->title }}</h2>
                </header>

                <ol class="list-[arabic-indic] space-y-2 ps-6 text-[15px] leading-7 text-zinc-800 marker:font-bold marker:text-zinc-400">
                    @foreach ($version->clauses as $clause)
                        <li>{{ $clause }}</li>
                    @endforeach
                </ol>

                @if ($signature)
                    <div class="flex items-center gap-2 rounded-xl bg-emerald-50 px-3 py-2 text-sm text-emerald-800" data-agreement-signed>
                        <flux:icon icon="check-circle" variant="mini" class="size-5 shrink-0" />
                        <span>أقرّ {{ $signature->signer_name }} في {{ HijriDate::withWeekday($signature->signed_at->setTimezone('Asia/Riyadh')) }}</span>
                    </div>
                @else
                    <div class="space-y-3 border-t border-zinc-100 pt-4">
                        <label class="flex items-start gap-2 text-sm font-medium text-zinc-800 cursor-pointer">
                            <input type="checkbox" wire:model="ticked.{{ $agreement->id }}" class="mt-0.5 size-4 rounded border-zinc-400">
                            <span>قرأتُ ما ورد أعلاه وأُقرّ بالالتزام به.</span>
                        </label>
                        @error("ticked.{$agreement->id}")
                            <p class="text-sm text-rose-600">{{ $message }}</p>
                        @enderror
                        @if ($party === 'guardian')
                            @error('signerName')
                                <p class="text-sm text-rose-600">{{ $message }}</p>
                            @enderror
                        @endif
                        <flux:button variant="primary" class="w-full" wire:click="sign({{ $agreement->id }})" wire:target="sign({{ $agreement->id }})">
                            إقرار
                        </flux:button>
                    </div>
                @endif
            </article>
        @endforeach
    @endif
</div>
