@php
    use App\Support\Branding;
    use App\Support\CircleRules;
    use App\Support\HijriDate;

    $ar = fn ($n) => HijriDate::arabicDigits((int) $n);
    $brand = Branding::color();
    $brandDark = Branding::shade($brand, 0.55);
    $person = $acknowledgement->user;
    $roles = collect($acknowledgement->roles)->map(fn ($role) => CircleRules::ROLE_LABELS[$role] ?? $role)->implode(' و');
    $at = $acknowledgement->acknowledged_at->copy()->setTimezone('Asia/Riyadh');
@endphp
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <title>إقرار بأنظمة الحلقات</title>
    <style>
        body { font-family: lamasans, sans-serif; font-size: 10.5px; color: #27272a; margin: 0; line-height: 1.7; }
        .brandbar { height: 3px; background-color: {{ $brand }}; }
        .head { width: 100%; padding: 10px 0 8px 0; }
        .head td { border: none; padding: 0; vertical-align: middle; }
        .head .logo { width: 56px; }
        .head h1 { font-size: 15px; margin: 0; font-weight: bold; color: {{ $brandDark }}; }
        .head .academy { font-size: 9px; color: #71717a; margin: 2px 0 0 0; }
        .head .meta { width: 40%; text-align: left; font-size: 9px; color: #71717a; }

        table.facts { width: 100%; border-collapse: collapse; margin: 6px 0 12px 0; }
        table.facts td { border: 0.5px solid #e4e4e7; padding: 5px 8px; }
        table.facts .label { width: 22%; background-color: #fafafa; color: #52525b; font-weight: bold; }

        h2 { font-size: 11.5px; color: {{ $brandDark }}; margin: 12px 0 4px 0; }
        .clause { margin: 0 0 4px 0; }
        .number { color: {{ $brandDark }}; font-weight: bold; }

        .pledge { margin-top: 16px; padding: 10px 12px; border: 1px solid {{ $brand }}; background-color: #fafafa; font-weight: bold; }
        .trail { margin-top: 10px; font-size: 8.5px; color: #71717a; }
        .warn { color: #b91c1c; font-weight: bold; }
    </style>
</head>

<body>
    <div class="brandbar"></div>
    <table class="head">
        <tr>
            <td class="logo"><img src="{{ Branding::logoFilePath() }}" width="48" alt=""></td>
            <td>
                <h1>إقرار بأنظمة الحلقات</h1>
                <p class="academy">{{ Branding::siteName() }}</p>
            </td>
            <td class="meta">الإصدار {{ $ar($acknowledgement->version->number) }}<br>نُشر {{ HijriDate::full($acknowledgement->version->published_at) }}</td>
        </tr>
    </table>

    <table class="facts">
        <tr><td class="label">المُقِرّ</td><td>{{ $person?->name ?? '—' }}</td></tr>
        <tr><td class="label">الصفة</td><td>{{ $roles }}</td></tr>
        {{-- The hour in Latin digits: mPDF reorders an Arabic "٠٩:٢٨ م" inside a right-to-left line. --}}
        <tr><td class="label">وقت الإقرار</td><td>{{ HijriDate::full($at) }} — الساعة {{ $at->format('H:i') }} بتوقيت الرياض</td></tr>
    </table>

    @foreach($text as $section)
        <h2>{{ $section['title'] }}</h2>
        @foreach($section['clauses'] as $clause)
            <p class="clause">
                <span class="number">{{ $ar($clause['number']) }}.</span>
                {{ $clause['body'] }}
            </p>
        @endforeach
    @endforeach

    <div class="pledge">قرأتُ هذه الأنظمة وأتعهد بالالتزام بها.</div>

    {{-- One fact a line: an address beside Arabic punctuation is torn apart by
         the reordering of a right-to-left line. The text's fingerprint itself
         stays in the database — mPDF runs its letters and digits backwards —
         and the page says whether the text above still matches it. --}}
    <div class="trail">
        <div>{{ $acknowledgement->channel === 'public_link' ? 'أُعطي هذا الإقرار من الرابط العام باختيار الاسم من قائمة المرحلة' : 'أُعطي هذا الإقرار من حساب المُقِرّ في الموقع' }}</div>
        <div>عنوان الجهاز: {{ $acknowledgement->ip ?? '—' }}</div>
        @if($intact)
            <div>النص أعلاه مطابق لبصمة النص المحفوظة لحظة الإقرار</div>
        @else
            <div class="warn">النص أعلاه لا يطابق بصمة النص المحفوظة لحظة الإقرار</div>
        @endif
    </div>
</body>

</html>
