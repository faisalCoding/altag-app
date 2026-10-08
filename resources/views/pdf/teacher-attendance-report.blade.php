@php
    use App\Support\Branding;
    use App\Support\HijriDate;

    $ar = fn ($n) => HijriDate::arabicDigits((int) $n);
    $brand = Branding::color();
    $brandDark = Branding::shade($brand, 0.55);

    $labels = ['present' => 'حاضر', 'late' => 'متأخر', 'excused' => 'مستأذن', 'absent' => 'غائب'];
@endphp
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <title>تقرير حضور المعلمين</title>
    <style>
        /* The face and the layout of the students' report, so the two sheets
           read as one set when they are printed side by side. */
        body { font-family: lamasans, sans-serif; font-size: 9px; color: #27272a; margin: 0; }

        .brandbar { height: 3px; background-color: {{ $brand }}; }

        .head { width: 100%; padding: 10px 0 6px 0; }
        .head td { border: none; padding: 0; vertical-align: middle; }
        .head .logo { width: 56px; }
        .head .title { width: 44%; }
        .head h1 { font-size: 14px; margin: 0; font-weight: bold; color: {{ $brandDark }}; }
        .head .academy { font-size: 9px; color: #71717a; margin: 2px 0 0 0; }
        .head .meta { width: 44%; text-align: left; font-size: 8px; color: #71717a; line-height: 1.6; }

        table.grid { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
        table.grid th, table.grid td {
            border: 0.5px solid #d4d4d8;
            padding: 4px 5px;
            text-align: center;
            vertical-align: middle;
        }
        table.grid thead th { background-color: #fafafa; font-weight: bold; color: #3f3f46; }

        .namecol { text-align: right; padding-right: 8px; width: 24%; }
        .sub { font-size: 7px; color: #71717a; }
        .none { color: #d4d4d8; }
        .bad { color: #be123c; font-weight: bold; }
        .rate { font-weight: bold; color: {{ $brandDark }}; }

        tfoot td { background-color: #f4f4f5; font-weight: bold; }

        h2 { font-size: 10px; color: {{ $brandDark }}; margin: 8px 0 4px 0; }
        table.days td { text-align: right; }

        .foot { font-size: 7px; color: #a1a1aa; text-align: center; }
    </style>
</head>

<body>

<div class="brandbar"></div>

<table class="head">
    <tr>
        <td class="logo"><img src="{{ Branding::logoFilePath() }}" width="48" alt=""></td>
        <td class="title">
            <h1>تقرير حضور المعلمين</h1>
            <p class="academy">{{ Branding::siteName() }}</p>
        </td>
        <td class="meta">
            من {{ HijriDate::full($from) }}<br>
            إلى {{ HijriDate::full($to) }}<br>
            {{ $stageNames }}
        </td>
    </tr>
</table>

<table class="grid">
    <thead>
        <tr>
            <th class="namecol">المعلم</th>
            <th>أيام الدوام</th>
            @foreach ($labels as $label)
                <th>{{ $label }}</th>
            @endforeach
            <th>لم يُحضَّر</th>
            <th>نسبة الحضور</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($rows as $row)
            <tr>
                <td class="namecol">
                    {{ $row['teacher']->name }}<br>
                    <span class="sub">{{ $row['circles'] ?: 'بلا حلقة' }}</span>
                </td>
                <td>{{ $ar($row['working']) }}</td>
                @foreach (array_keys($labels) as $status)
                    <td class="{{ $row[$status] > 0 ? '' : 'none' }}">{{ $ar($row[$status]) }}</td>
                @endforeach
                <td class="{{ $row['unrecorded'] > 0 ? 'bad' : 'none' }}">{{ $ar($row['unrecorded']) }}</td>
                <td class="rate">{{ $row['rate'] === null ? '—' : $ar($row['rate']).'٪' }}</td>
            </tr>
        @empty
            <tr>
                <td colspan="8" style="padding: 24px; color: #a1a1aa;">لا يوجد معلمون في المراحل المختارة.</td>
            </tr>
        @endforelse
    </tbody>
    @if ($rows->isNotEmpty())
        <tfoot>
            <tr>
                <td class="namecol">الإجمالي</td>
                <td>—</td>
                @foreach (array_keys($labels) as $status)
                    <td>{{ $ar($totals[$status]) }}</td>
                @endforeach
                <td>{{ $ar($totals['unrecorded']) }}</td>
                <td class="rate">{{ $totals['rate'] === null ? '—' : $ar($totals['rate']).'٪' }}</td>
            </tr>
        </tfoot>
    @endif
</table>

@php
    $awayRows = $rows->filter(fn (array $row) => $row['away']->isNotEmpty());
@endphp

@if ($awayRows->isNotEmpty())
    <h2>أيام الغياب والتأخر والاستئذان</h2>
    <table class="grid days">
        <thead>
            <tr>
                <th class="namecol">المعلم</th>
                <th>اليوم</th>
                <th>الحالة</th>
                <th>التفاصيل</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($awayRows as $row)
                @foreach ($row['away'] as $day)
                    <tr>
                        <td class="namecol">{{ $loop->first ? $row['teacher']->name : '' }}</td>
                        <td>{{ HijriDate::withWeekday($day->date) }}</td>
                        <td>{{ $labels[$day->status] ?? $day->status }}</td>
                        <td>
                            @if ($day->status === 'late' && $day->arrived_at)
                                حضر الساعة {{ $day->arrivalLabel() }}@if ($minutes = $day->minutesLate()) (متأخراً {{ $ar($minutes) }} دقيقة)@endif.
                            @endif
                            {{ $day->notes ? 'السبب: '.$day->notes.'.' : '' }}
                            {{ $day->substitute && in_array($day->status, \App\Models\TeacherAttendance::AWAY, true) ? 'البديل: '.$day->substitute->name.'.' : '' }}
                        </td>
                    </tr>
                @endforeach
            @endforeach
        </tbody>
    </table>
@endif

<p class="foot">طُبع في {{ HijriDate::full(now('Asia/Riyadh')) }} — نسبة الحضور = الحاضر والمتأخر من الأيام المسجّلة دون أيام الاستئذان</p>

</body>

</html>
