@php
    use App\Support\Branding;
    use App\Support\HijriDate;
@endphp

<div class="w-full max-w-xl space-y-5">
    @push('meta')
        <meta name="robots" content="noindex, nofollow">
        <title>إقرار من الحلقة - {{ Branding::siteName() }}</title>
    @endpush

    <div class="flex flex-col items-center text-center gap-2">
        <img src="{{ Branding::logoUrl() }}" alt="" class="size-16 object-contain">
        <h1 class="text-xl font-bold text-zinc-900">إقرار من الحلقة</h1>
        <p class="text-sm text-zinc-600">
            @if ($request->party === 'guardian')
                لولي أمر الطالب <span class="font-semibold text-zinc-900">{{ $student->name }}</span>
            @else
                للطالب <span class="font-semibold text-zinc-900">{{ $student->name }}</span>
            @endif
            @if ($student->circle)
                · {{ $student->circle->name }}
            @endif
        </p>
    </div>

    @if ($request->cancelled_at)
        <div class="rounded-2xl border border-dashed border-zinc-300 p-8 text-center text-zinc-500" data-request-cancelled>
            أُلغي هذا الطلب، ولم يعد مطلوباً.
        </div>
    @else
        {{-- Why the teacher sent it, and when. --}}
        <div class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900" data-request-note>
            <p>
                أرسله {{ $request->sender?->name ?? 'المعلم' }}
                في {{ HijriDate::withWeekday($request->sent_at->setTimezone('Asia/Riyadh')) }}.
            </p>
            @if ($request->note)
                <p class="mt-1 font-semibold">{{ $request->note }}</p>
            @endif
        </div>

        <x-agreements.wizard :agreements="$agreements" :needs-name="$needsName" :name="$signedIn?->name ?? ''"
            :store-key="'agreement-request:'.$request->id" />
    @endif
</div>
