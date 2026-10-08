@php
    use App\Support\Branding;
    use App\Support\HijriDate;

    $ar = fn ($n) => HijriDate::arabicDigits((int) $n);
    $brand = Branding::color();
    $brandDark = Branding::shade($brand, 0.55);

    // mPDF does not read #RRGGBBAA, so a tint has to be mixed against white
    // here rather than written as an alpha — the stage rows came out solid.
    $tint = '#'.collect(str_split(substr($brand, 1), 2))
        ->map(fn ($pair) => str_pad(dechex((int) round(hexdec($pair) * 0.12 + 255 * 0.88)), 2, '0', STR_PAD_LEFT))
        ->implode('');

    $dates = array_column($grid['dates'], 'date');
    $summary = $grid['summary'];

    // The screen's colour bands, as mPDF takes them: solid hex, no alpha.
    $band = fn (?int $rate) => match (true) {
        $rate === null => 'background-color: #f4f4f5; color: #71717a;',
        $rate >= 90 => 'background-color: #bbf7d0; color: #14532d;',
        $rate >= 75 => 'background-color: #ecfccb; color: #365314;',
        $rate >= 60 => 'background-color: #fde68a; color: #451a03;',
        default => 'background-color: #fecdd3; color: #4c0519;',
    };
    $percent = fn (?int $rate) => $rate === null ? '—' : $ar($rate).'٪';

    // Grouped here rather than in the table, so the header markup stays readable.
    $monthGroups = [];
    foreach ($dates as $d) {
        $label = HijriDate::monthYear($d);
        if ($monthGroups && end($monthGroups)['label'] === $label) {
            $monthGroups[count($monthGroups) - 1]['span']++;
        } else {
            $monthGroups[] = ['label' => $label, 'span' => 1];
        }
    }
@endphp
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <title>تقرير الحضور والغياب</title>
    <style>
        /* Lama Sans, registered with mPDF in config/pdf.php — a sheet printed
           from a screen should be set in the face that screen was read in. */
        body { font-family: lamasans, sans-serif; font-size: 9px; color: #27272a; margin: 0; }

        .brandbar { height: 3px; background-color: {{ $brand }}; }

        /* The width is load-bearing: without it mPDF shrinks the table to its
           content, and in a right-to-left page that bunches the whole header
           against the right edge with the rest of the line left empty. */
        .head { width: 100%; padding: 10px 0 6px 0; }
        .head td { border: none; padding: 0; vertical-align: middle; }
        .head .logo { width: 56px; }
        .head .title { width: 44%; }
        .head h1 { font-size: 14px; margin: 0; font-weight: bold; color: {{ $brandDark }}; }
        .head .academy { font-size: 9px; color: #71717a; margin: 2px 0 0 0; }
        .head .meta { width: 44%; text-align: left; font-size: 8px; color: #71717a; line-height: 1.6; }

        table.grid { width: 100%; border-collapse: collapse; }
        table.grid th, table.grid td {
            border: 0.5px solid #d4d4d8;
            padding: 3px 4px;
            text-align: center;
            vertical-align: middle;
        }

        table.grid thead th { background-color: #fafafa; font-weight: bold; color: #3f3f46; }
        .monthcell { font-size: 8px; color: #71717a; font-weight: normal; }
        .daycell { font-size: 8px; line-height: 1.3; }
        .dayname { color: #a1a1aa; font-weight: normal; }

        .namecol { text-align: right; padding-right: 8px; width: 16%; }

        .stagerow td {
            background-color: {{ $tint }};
            color: {{ $brandDark }};
            font-weight: bold;
            text-align: right;
            padding: 4px 8px;
            font-size: 10px;
        }

        .present { font-weight: bold; }
        .of { color: #a1a1aa; }
        .none { color: #d4d4d8; }

        .off { background-color: #f4f4f5; }
        .missing { color: #e11d48; font-weight: bold; font-size: 7px; border: 1px dashed #fb7185; }
        .pending { color: #a1a1aa; font-size: 7px; }
        .unmarked { font-size: 6.5px; }

        .summary { width: 100%; margin-bottom: 6px; }
        .summary td { border: none; padding: 0 0 0 14px; font-size: 9px; color: #52525b; }
        .summary b { font-size: 11px; color: #27272a; }

        .totalcol { background-color: #fafafa; width: 9%; }
        .totalcol .big { font-weight: bold; font-size: 11px; color: {{ $brandDark }}; }
        .totalcol .sub { font-size: 7px; color: #71717a; }

        tfoot td { background-color: #f4f4f5; font-weight: bold; }
        tfoot .namecol { color: {{ $brandDark }}; }

        .foot { font-size: 7px; color: #a1a1aa; text-align: center; }
    </style>
</head>

<body>

<div class="brandbar"></div>

{{-- A table rather than floats: mPDF lays those out poorly, and the header has
     three columns that must sit on one line. --}}
<table class="head">
    <tr>
        {{-- Sized with the attribute, not with CSS: mPDF reads the attribute and
             ignores the rule, and the logo came out filling the page. --}}
        <td class="logo"><img src="{{ Branding::logoFilePath() }}" width="48" alt=""></td>
        <td class="title">
            <h1>تقرير الحضور والغياب</h1>
            <p class="academy">{{ Branding::siteName() }}</p>
        </td>
        <td class="meta">
            من {{ HijriDate::full($fromDate) }}<br>
            إلى {{ HijriDate::full($toDate) }}<br>
            {{ $stageNames ?? 'كل المراحل' }}
        </td>
    </tr>
</table>

<table class="summary">
    <tr>
        <td>نسبة الحضور: <b>{{ $percent($summary['rate']) }}</b></td>
        <td>أيام دوام بلا تحضير: <b>{{ $ar($summary['missing']) }}</b></td>
        <td>طلاب لم يُسجَّلوا: <b>{{ $ar($summary['unmarked']) }}</b></td>
        <td>أكثر حلقة غياباً: <b>{{ $summary['worst'] ? $summary['worst']['name'].' ('.$percent($summary['worst']['rate']).')' : '—' }}</b></td>
    </tr>
</table>

<table class="grid">
    <thead>
        <tr>
            <th rowspan="2" class="namecol">الحلقة</th>
            @foreach ($monthGroups as $group)
                <th colspan="{{ $group['span'] }}" class="monthcell">{{ $group['label'] }}</th>
            @endforeach
            <th rowspan="2" class="totalcol">النسبة</th>
        </tr>
        <tr>
            @foreach ($dates as $date)
                <th class="daycell">
                    {{-- format() already returns Arabic-Indic digits; running them through
                         arabicDigits() again cast «١٣» to an int and printed ٠. --}}
                    <span class="dayname">{{ HijriDate::weekday($date) }}</span><br>{{ HijriDate::format($date, 'd') }}
                </th>
            @endforeach
        </tr>
    </thead>

    <tbody>
        @forelse ($grid['groups'] as $group)
            <tr class="stagerow">
                <td colspan="{{ count($dates) + 2 }}">{{ $group['stage'] }}</td>
            </tr>

            @foreach ($group['circles'] as $row)
                <tr>
                    <td class="namecol">{{ $row['circle']->name }}</td>

                    @foreach ($dates as $date)
                        @php
                            $cell = $row['cells'][$date];
                        @endphp
                        @switch ($cell['state'])
                            @case('data')
                                <td style="{{ $band($cell['rate']) }}">
                                    <span class="present">{{ $ar($cell['present']) }}</span> / {{ $ar($cell['expected'] - $cell['excused']) }}
                                    @if ($cell['unmarked'] > 0)
                                        <br><span class="unmarked">{{ $ar($cell['unmarked']) }} لم يُسجَّل</span>
                                    @endif
                                </td>
                                @break
                            @case('missing')
                                <td class="missing">لم يُحضَّر</td>
                                @break
                            @case('pending')
                                <td class="pending">لم يُحضَّر بعد</td>
                                @break
                            @case('off')
                                <td class="off"></td>
                                @break
                            @default
                                <td><span class="none">—</span></td>
                        @endswitch
                    @endforeach

                    <td class="totalcol">
                        <span class="big">{{ $percent($row['totals']['rate']) }}</span><br>
                        <span class="sub">غياب {{ $ar($row['totals']['absent']) }}، تأخر {{ $ar($row['totals']['late']) }}@if ($row['totals']['missing'] > 0)، {{ $ar($row['totals']['missing']) }} بلا تحضير@endif</span>
                    </td>
                </tr>
            @endforeach
        @empty
            <tr>
                <td colspan="{{ count($dates) + 2 }}" style="padding: 24px; color: #a1a1aa;">
                    لا حلقات في المراحل المختارة.
                </td>
            </tr>
        @endforelse
    </tbody>

    @if (count($grid['groups']) > 0)
        <tfoot>
            <tr>
                <td class="namecol">المجمع</td>
                @foreach ($dates as $date)
                    <td>{{ $percent($grid['days'][$date]['rate']) }}</td>
                @endforeach
                <td class="totalcol"><span class="big">{{ $percent($summary['rate']) }}</span></td>
            </tr>
        </tfoot>
    @endif
</table>

<p class="foot">طُبع في {{ HijriDate::full(now('Asia/Riyadh')) }} — الخلية: الحاضرون (والمتأخرون) من الطلاب المشاركين، والمستأذن خارجها — الرمادي ليس يوم دوام</p>

</body>

</html>
