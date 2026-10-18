@php
    use App\Support\Branding;

    $needsName = $party === 'guardian' && ! $signedIn;
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

    <x-agreements.wizard :agreements="$agreements" :needs-name="$needsName" :name="$signedIn?->name ?? ''"
        :store-key="'agreement-progress:'.$student->id.':'.$party" />
</div>
