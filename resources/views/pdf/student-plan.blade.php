<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <title>خطة الطالب - {{ $plan->student->name }}</title>
    <style>
        body {
            margin: 0;
            padding: 16px;
            font-size: 11px;
            font-family: 'Tajawal', 'DejaVu Sans', sans-serif;
            direction: rtl;
        }

        .header {
            text-align: center;
            margin-bottom: 20px;
            border-b: 1px solid #e4e4e7;
            padding-bottom: 12px;
        }

        .logo-container {
            text-align: center;
            margin-bottom: 10px;
        }

        .logo-container img {
            height: 70px;
            object-fit: contain;
        }

        .header h1 {
            font-size: 18px;
            margin: 0 0 5px 0;
            font-weight: bold;
            color: #1f2937;
        }

        .info-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
            background-color: #f9fafb;
            border: 1px solid #e5e7eb;
        }

        .info-table td {
            border: none;
            padding: 8px 12px;
            text-align: right;
            font-size: 12px;
            width: 50%;
            color: #374151;
        }

        .info-table td strong {
            color: #6b7280;
            margin-left: 5px;
        }

        .plan-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }

        .plan-table th,
        .plan-table td {
            border: 1px solid #d4d4d8;
            padding: 6px 8px;
            text-align: center;
            font-size: 11px;
        }

        .plan-table th {
            background-color: #f4f4f5;
            font-weight: bold;
            color: #27272a;
        }

        .plan-table td.text-right {
            text-align: right;
            padding-right: 12px;
        }

        .plan-table td.date-cell {
            color: #4b5563;
        }

        .sunday-row td {
            background-color: #f4f4f5;
        }

        .footer {
            margin-top: 30px;
            text-align: center;
            font-size: 11px;
            color: #71717a;
            border-top: 1px solid #e4e4e7;
            padding-top: 12px;
        }
    </style>
</head>

<body>
    @php
        @endphp

    <div class="header">
        <div class="logo-container">
            @if(file_exists(App\Support\Branding::logoFilePath()))
                <img src="{{ App\Support\Branding::logoFilePath() }}" alt="Logo">
            @endif
        </div>
        <h1>
            @if($plan->plan_type === 'hifz')
                خطة الحفظ للقرآن الكريم
            @elseif($plan->plan_type === 'review')
                خطة المراجعة للقرآن الكريم
            @else
                خطة الحفظ والمراجعة للقرآن الكريم
            @endif
        </h1>
    </div>

    <table class="info-table">
        <tr>
            <td><strong>الطالب:</strong> {{ $plan->student->name }}</td>
            <td><strong>الحلقة:</strong> {{ $plan->student->circle->name ?? 'غير محدد' }}</td>
        </tr>
        <tr>
            <td><strong>المعلم:</strong> {{ auth()->guard('teacher')->user()->name }}</td>
            <td><strong>تاريخ البدء:</strong> {{ \App\Support\HijriDate::full($plan->start_date) }}</td>
        </tr>
        <tr>
            <td><strong>عدد أيام الخطة:</strong> {{ $plan->days_count }} يومًا</td>
            <td><strong>نوع الخطة:</strong> 
                @if($plan->plan_type === 'review')
                    مراجعة فقط
                @elseif($plan->plan_type === 'hifz_review')
                    حفظ ومراجعة
                @else
                    حفظ فقط
                @endif
            </td>
        </tr>
    </table>

    <table class="plan-table">
        <thead>
            <tr>
                <th style="width: 25%;">التاريخ</th>
                <th style="width: 15%;">اليوم</th>

                @if(in_array($plan->plan_type, ['hifz', 'hifz_review']))
                    <th style="width: 30%;">الـحـفـظ</th>
                @endif

                @if(in_array($plan->plan_type, ['review', 'hifz_review']))
                    <th style="width: 30%;">الـمـراجـعـة</th>
                @endif
            </tr>
        </thead>
        <tbody>
            @foreach($plan->days as $day)
                <tr class="{{ $day->day_name == 'الأحد' ? 'sunday-row' : '' }}">
                    <td class="date-cell">{{ \App\Support\HijriDate::full($day->date) }}</td>
                    <td>{{ $day->day_name }}</td>

                    @if(in_array($plan->plan_type, ['hifz', 'hifz_review']))
                        <td class="text-right">
                            {{ $day->formatRange('hifz') ?? '—' }}
                        </td>
                    @endif

                    @if(in_array($plan->plan_type, ['review', 'hifz_review']))
                        <td class="text-right">
                            {{ $day->formatRange('review') ?? '—' }}
                        </td>
                    @endif
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="footer">
        جدة - حي الواحة - جامع الزبيدي - حلقات التاج القرآنية التابعة لجمعية خيركم لتعليم القرآن الكريم
    </div>
</body>

</html>
