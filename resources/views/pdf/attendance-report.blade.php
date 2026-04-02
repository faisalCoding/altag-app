<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>تقرير الحضور والغياب</title>
    <style>
        body {
            margin: 0;
            padding: 20px;
            font-size: 11px; /* Smaller font to fit columns */
        }
        .header {
            text-align: center;
            margin-bottom: 20px;
        }
        .header h1 {
            font-size: 18px;
            margin: 0 0 5px 0;
            font-weight: bold;
        }
        .header p {
            margin: 0;
            color: #555;
            font-size: 14px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            table-layout: auto;
        }
        th, td {
            border: 1px solid #ddd;
            padding: 4px;
            text-align: center;
        }
        th {
            background-color: #f1f5f9;
            font-weight: bold;
            color: #333;
        }
        .stage-row td {
            background-color: #e2e8f0;
            font-weight: bold;
            text-align: right;
            padding-right: 15px;
        }
        .circle-name {
            text-align: right;
        }
        .total-col {
            font-weight: bold;
            background-color: #f8fafc;
        }
        .present {
            color: #16a34a;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>تقرير الحضور والغياب للحلقات</h1>
        <p>الفترة من: {{ $printFrom }} إلى {{ $printTo }}</p>
    </div>

    <table>
        <thead>
            <tr>
                <th rowspan="2" style="width: 18%">الحلقة / المرحلة</th>
                @foreach($dates as $date)
                    @php
                        $formatter = new \IntlDateFormatter('ar_SA@calendar=islamic-umalqura', \IntlDateFormatter::FULL, \IntlDateFormatter::NONE, 'Asia/Riyadh', \IntlDateFormatter::TRADITIONAL, 'MMM');
                        $hijriMonth = $formatter->format(strtotime($date));
                    @endphp
                    <th>{{ $hijriMonth }}</th>
                @endforeach
                <th rowspan="2" class="total-col" style="width: 12%">الإجمالي<br>(حضور / مسجلين)</th>
            </tr>
            <tr>
                @foreach($dates as $date)
                    @php
                        $dayNameFormatter = new \IntlDateFormatter('ar_SA@calendar=islamic-umalqura', \IntlDateFormatter::FULL, \IntlDateFormatter::NONE, 'Asia/Riyadh', \IntlDateFormatter::TRADITIONAL, 'E');
                        $dayName = $dayNameFormatter->format(strtotime($date));
                        $hijriDayFormatter = new \IntlDateFormatter('ar_SA@calendar=islamic-umalqura', \IntlDateFormatter::FULL, \IntlDateFormatter::NONE, 'Asia/Riyadh', \IntlDateFormatter::TRADITIONAL, 'd');
                        $dayNum = $hijriDayFormatter->format(strtotime($date));
                    @endphp
                    <th style="font-size: 9px; max-width: 30px;">{{ $dayNum }}<br>{{ $dayName }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach($groupedCircles as $stageName => $circles)
                <tr class="stage-row">
                    <td colspan="{{ count($dates) + 2 }}">{{ $stageName }}</td>
                </tr>
                @foreach($circles as $circle)
                    @php
                        $circleTotalPresent = 0;
                        $circleGlobalTotal = 0;
                        $totalDaysWithData = 0;
                    @endphp
                    <tr>
                        <td class="circle-name">{{ $circle->name }}</td>
                        @foreach($dates as $date)
                            @php
                                $cellData = $attendanceData[$circle->id][$date] ?? null;
                            @endphp
                            <td>
                                @if($cellData)
                                    @php
                                        $circleTotalPresent += $cellData['present'];
                                        $circleGlobalTotal += $cellData['total'];
                                        $totalDaysWithData++;
                                    @endphp
                                    <span class="present">{{ $cellData['present'] }}</span> / {{ $cellData['total'] }}
                                @else
                                    -
                                @endif
                            </td>
                        @endforeach
                        <td class="total-col">
                            @php
                                $avgStudents = $totalDaysWithData > 0 ? round($circleGlobalTotal / $totalDaysWithData) : 0;
                            @endphp
                            <span class="present">{{ $circleTotalPresent }}</span> / {{ $circleGlobalTotal }}
                        </td>
                    </tr>
                @endforeach
            @endforeach
        </tbody>
    </table>
</body>
</html>
