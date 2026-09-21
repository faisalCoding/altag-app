<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <title>تقرير الحضور والغياب</title>
    <style>
        body {
            margin: 0;
            padding: 16px;
            font-size: 10px;
            font-family: 'Tajawal', 'DejaVu Sans', sans-serif;
        }

        .header {
            text-align: center;
            margin-bottom: 16px;
        }

        .header img {
            height: 60px;
            margin-bottom: 8px;
            object-fit: contain;
        }

        .header h1 {
            font-size: 16px;
            margin: 0 0 4px 0;
            font-weight: bold;
        }

        .header p {
            margin: 0;
            color: #555;
            font-size: 12px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            table-layout: auto;
        }

        th, td {
            border: 1px solid #d1d5db;
            padding: 3px 4px;
            text-align: center;
            vertical-align: middle;
        }

        /* Sticky-like header */
        th {
            background-color: #f1f5f9;
            font-weight: bold;
            color: #374151;
        }

        /* Stage separator row */
        .stage-row td {
            background-color: #e2e8f0;
            font-weight: bold;
            text-align: right;
            padding-right: 12px;
            font-size: 11px;
        }

        /* Circle name cell */
        .circle-name {
            text-align: right;
            padding-right: 8px;
            font-weight: 500;
            color: #374151;
        }

        /* Cell data */
        .present { color: #16a34a; font-weight: bold; }
        .total-num { color: #6b7280; }
        .dash { color: #d1d5db; }

        /* Total column */
        .col-total {
            background-color: #f8fafc;
            font-weight: bold;
        }

        /* Grand total row */
        .grand-total td {
            background-color: #e2e8f0;
            font-weight: bold;
        }

        .grand-total .circle-name {
            background-color: #cbd5e1;
        }
    </style>
</head>

<body>
    <div class="header">
        <img src="{{ App\Support\Branding::logoFilePath() }}" alt="Logo">
        <h1>تقرير الحضور والغياب للحلقات</h1>
        @php
            @endphp
        <p>الفترة من: {{ \App\Support\HijriDate::full($fromDate) }} إلى {{ \App\Support\HijriDate::full($toDate) }}</p>
    </div>

    <table>
        <thead>
            {{-- Month row --}}
            <tr>
                <th rowspan="2" style="width: 15%; text-align: right; padding-right: 8px;">الحلقة / المرحلة</th>
                @php
                    $monthGroups = [];
                    $prevMonth = null;
                    foreach ($dates as $d) {
                        $m = \App\Support\HijriDate::format($d, 'MMM yyyy');
                        if ($m === $prevMonth) {
                            $monthGroups[count($monthGroups) - 1]['span']++;
                        } else {
                            $monthGroups[] = ['label' => $m, 'span' => 1];
                            $prevMonth = $m;
                        }
                    }
                @endphp
                @foreach($monthGroups as $mg)
                    <th colspan="{{ $mg['span'] }}" style="font-size: 8px;">{{ $mg['label'] }}</th>
                @endforeach
                <th rowspan="2" class="col-total" style="width: 10%;">الإجمالي<br>(حضور / مشاركون)</th>
            </tr>
            {{-- Day row --}}
            <tr>
                @foreach($dates as $date)
                    @php
                        $dayNum  = \App\Support\HijriDate::format($date, 'd');
                        $dayName = \App\Support\HijriDate::format($date, 'E');
                    @endphp
                    <th style="font-size: 8px; max-width: 28px;">{{ $dayNum }}<br>{{ $dayName }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @php
                $grandTotalPresent      = 0;
                $grandTotalParticipants = 0;
                $grandPerDay            = array_fill_keys($dates, ['present' => 0, 'total' => 0]);
            @endphp

            @foreach($groupedCircles as $stageName => $circles)
                {{-- Stage Row --}}
                <tr class="stage-row">
                    <td colspan="{{ count($dates) + 2 }}">{{ $stageName }}</td>
                </tr>

                @foreach($circles as $circle)
                    @php
                        $circleTotalPresent      = 0;
                        $circleGlobalTotal       = 0;
                        $daysWithData            = 0;
                    @endphp
                    <tr>
                        <td class="circle-name">{{ $circle->name }}</td>

                        @foreach($dates as $date)
                            @php
                                $cell = $attendanceData[$circle->id][$date] ?? null;
                                if ($cell) {
                                    $circleTotalPresent      += $cell['present'];
                                    $circleGlobalTotal       += $cell['total'];
                                    $grandPerDay[$date]['present'] += $cell['present'];
                                    $grandPerDay[$date]['total']   += $cell['total'];
                                    $daysWithData++;
                                }
                            @endphp
                            <td>
                                @if($cell)
                                    <span class="present">{{ $cell['present'] }}</span><span class="total-num">/{{ $cell['total'] }}</span>
                                @else
                                    <span class="dash">—</span>
                                @endif
                            </td>
                        @endforeach

                        @php
                            $grandTotalPresent      += $circleTotalPresent;
                            $grandTotalParticipants += $circleGlobalTotal;
                            $avgTotal = $daysWithData > 0 ? round($circleGlobalTotal / $daysWithData) : 0;
                        @endphp
                        <td class="col-total">
                            <span class="present">{{ $circleTotalPresent }}</span><br>
                            <small style="color: #6b7280;">متوسط: {{ $avgTotal }}</small><br>
                            <small style="color: #3b82f6;">المشاركون: {{ $circleGlobalTotal }}</small>
                        </td>
                    </tr>
                @endforeach
            @endforeach
        </tbody>

        {{-- Grand Total Footer --}}
        <tfoot>
            <tr class="grand-total">
                <td class="circle-name">الإجمالي الكلي</td>
                @foreach($dates as $date)
                    <td>
                        @if($grandPerDay[$date]['total'] > 0)
                            <span class="present">{{ $grandPerDay[$date]['present'] }}</span>
                            <span class="total-num">/{{ $grandPerDay[$date]['total'] }}</span>
                        @else
                            <span class="dash">—</span>
                        @endif
                    </td>
                @endforeach
                <td class="col-total">
                    <span class="present">{{ $grandTotalPresent }}</span><br>
                    <small style="color: #3b82f6;">المشاركون: {{ $grandTotalParticipants }}</small>
                </td>
            </tr>
        </tfoot>
    </table>
</body>

</html>