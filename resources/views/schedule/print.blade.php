@php
    // Shrinks a wide week (seven columns) to fit a landscape A4 sheet.
    $zoom = min(1, round(1060 / (360 + $poster['track']->columns * 150), 2));
@endphp
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $poster['week']->displayTitle() }} - {{ $poster['track']->name }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <x-branding-styles />
    <style>
        @page { size: A4 landscape; margin: 6mm; }
        body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        @media print {
            .no-print { display: none !important; }
            body { background-color: white !important; padding: 0 !important; }
            .schedule-poster { zoom: {{ $zoom }}; }
        }
    </style>
</head>

<body class="bg-zinc-100 p-4">
    <div class="no-print mx-auto mb-4 flex max-w-6xl flex-wrap items-center justify-between gap-2">
        <p class="text-sm text-zinc-600">اطبع الجدول، أو اختر «حفظ بتنسيق PDF» من نافذة الطباعة.</p>
        <button onclick="window.print()" class="rounded-lg bg-zinc-900 px-4 py-2 text-sm font-bold text-white hover:bg-zinc-700">طباعة</button>
    </div>

    <div class="overflow-x-auto">
        <x-schedule.poster :data="$poster" />
    </div>
</body>

</html>
