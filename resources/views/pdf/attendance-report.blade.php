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

<table class="grid">
    <thead>
        <tr>
            <th rowspan="2" class="namecol">الحلقة</th>
            @foreach ($monthGroups as $group)
                <th colspan="{{ $group['span'] }}" class="monthcell">{{ $group['label'] }}</th>
            @endforeach
            <th rowspan="2" class="totalcol">الإجمالي</th>
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
        @php
            $grandPresent = 0;
            $grandParticipants = 0;
            $perDay = array_fill_keys($dates, ['present' => 0, 'total' => 0]);
        @endphp

        @forelse ($groupedCircles as $stageName => $circles)
            <tr class="stagerow">
                <td colspan="{{ count($dates) + 2 }}">{{ $stageName }}</td>
            </tr>

            @foreach ($circles as $circle)
                @php
                    $present = 0;
                    $participants = 0;
                    $daysWithData = 0;
                @endphp
                <tr>
                    <td class="namecol">{{ $circle->name }}</td>

                    @foreach ($dates as $date)
                        @php
                            $cell = $attendanceData[$circle->id][$date] ?? null;
                            if ($cell) {
                                $present += $cell['present'];
                                $participants += $cell['total'];
                                $perDay[$date]['present'] += $cell['present'];
                                $perDay[$date]['total'] += $cell['total'];
                                $daysWithData++;
                            }
                        @endphp
                        <td>
                            @if ($cell)
                                <span class="present">{{ $ar($cell['present']) }}</span><span class="of">/{{ $ar($cell['total']) }}</span>
                            @else
                                <span class="none">—</span>
                            @endif
                        </td>
                    @endforeach

                    @php
                        $grandPresent += $present;
                        $grandParticipants += $participants;
                    @endphp
                    <td class="totalcol">
                        <span class="big">{{ $ar($present) }}</span><span class="of">/{{ $ar($participants) }}</span><br>
                        <span class="sub">متوسط {{ $ar($daysWithData > 0 ? round($participants / $daysWithData) : 0) }}</span>
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

    @if (count($groupedCircles) > 0)
        <tfoot>
            <tr>
                <td class="namecol">الإجمالي الكلي</td>
                @foreach ($dates as $date)
                    <td>
                        @if ($perDay[$date]['total'] > 0)
                            <span class="present">{{ $ar($perDay[$date]['present']) }}</span><span class="of">/{{ $ar($perDay[$date]['total']) }}</span>
                        @else
                            <span class="none">—</span>
                        @endif
                    </td>
                @endforeach
                <td class="totalcol">
                    <span class="big">{{ $ar($grandPresent) }}</span><span class="of">/{{ $ar($grandParticipants) }}</span>
                </td>
            </tr>
        </tfoot>
    @endif
</table>

<p class="foot">طُبع في {{ HijriDate::full(now('Asia/Riyadh')) }} · الرقم الأول حضور والثاني عدد المشاركين</p>

</body>

</html>
