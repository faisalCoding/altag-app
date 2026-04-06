@php
    $formatter = new \IntlDateFormatter(
        'ar_SA@calendar=islamic-umalqura',
        \IntlDateFormatter::FULL,
        \IntlDateFormatter::NONE,
        'Asia/Riyadh',
        \IntlDateFormatter::TRADITIONAL,
        'd MMMM yyyy'
    );
@endphp
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>طباعة خطة الطالب - {{ $plan->student->name }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        @media print {
            .no-print {
                display: none !important;
            }

            body {
                background-color: white !important;
                font-size: 11pt;
            }

            @page {
                margin: 0.2cm;
            }
        }

        .print-table th,
        .print-table td {
            border: 0.7px solid #d4d4d8;
        }

        .print-table th {
            background-color: #f4f4f5;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        .print-sunday {
            background-color: #f4f4f5;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
    </style>
</head>

<body class="bg-white text-zinc-900 font-sans antialiased p-4 sm:p-8 max-w-5xl mx-auto">

    <div class="no-print mb-4 flex justify-end gap-2">
        <button onclick="window.print()"
            class="px-3 py-1.5 bg-indigo-600 text-white rounded shadow-sm hover:bg-indigo-700 font-medium text-xs transition-colors">
            طباعة الخطة
        </button>
        <a href="{{ route('teacher.student-plans') }}"
            class="px-3 py-1.5 bg-zinc-100 text-zinc-700 rounded hover:bg-zinc-200 font-medium text-xs transition-colors">
            رجوع
        </a>
    </div>

    <!-- Header -->
    <div class="text-center mb-4 pb-4 border-b border-zinc-200">



        <div class="flex justify-between gap-2 text-xs font-semibold container mx-auto">
            <div class="flex justify-center items-end border border-zinc-200 rounded-4xl p-5 pt-3">
                <div class="flex justify-start items-end w-26">
                    <img src="{{ asset('images/altag_logo.png') }}" alt="Logo" class="h-26 object-contain" />
                </div>
                <div class="flex flex-col items-start">
                    <h1 class="text-lg  mb-2">
                        @if($plan->plan_type === 'hifz')
                            خطة الحفظ
                        @elseif($plan->plan_type === 'review')
                            خطة المراجعة
                        @else
                            خطة الحفظ والمراجعة
                        @endif
                    </h1>
                    <div class="flex items-end">
                        <span class="text-zinc-500 ml-1">الطالب:</span>
                        <span>{{ $plan->student->name }}</span>
                    </div>
                </div>
            </div>
            <div class="flex flex-col justify-around border border-zinc-200 rounded-4xl py-7 px-3">
                <div class="flex flex-wrap justify-start items-cend gap-2 w-full">
                    <div class="flex items-end">
                        <span class="text-zinc-500 ml-1">الحلقة:</span>
                        <span>{{ $plan->student->circle->name ?? 'غير محدد' }}</span>
                    </div>

                </div>
                <div class="">
                    <span class="text-zinc-500 ml-1">المعلم:</span>
                    <span>{{ auth()->guard('teacher')->user()->name }}</span>
                </div>
                <div class="flexflex-wrap justify-start gap-2 w-full">
                    <div class="">
                        <span class="text-zinc-500 ml-1">التاريخ:</span>
                        <span>{{ $formatter->format($plan->start_date->timestamp) }}</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Table -->
    <div class="overflow-hidden border rounded-2xl border-zinc-300">
        <table class="w-full text-center print-table text-xs sm:text-sm border-collapse">
            <thead>
                <tr>
                    <th class="py-2 px-1 text-xs w-50">التاريخ</th>
                    <th class="py-2 px-1 text-xs w-16">اليوم</th>

                    @if(in_array($plan->plan_type, ['hifz', 'hifz_review']))
                        <th class="py-2 px-2 text-xs  border-r border-zinc-300 text-gray-800 w-1/3">الـحـفـظ</th>
                        <th class="py-2 px-1 text-xs  w-20">انجاز الحفظ</th>
                    @endif

                    @if(in_array($plan->plan_type, ['review', 'hifz_review']))
                        <th class="py-2 px-2 text-xs  border-r border-zinc-300 text-gray-800 w-1/3">الـمـراجـعـة</th>
                        <th class="py-2 px-1 text-xs  w-20">انجاز المراجعة</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @foreach($plan->days as $day)
                    <tr class=" @if($day->day_name == 'الأحد') print-sunday @endif">
                        <td class="py-2 px-1 text-[11px] text-zinc-600 ">{{ $formatter->format($day->date->timestamp) }}
                        </td>
                        <td class="py-2 px-1 text-[11px] bg-zinc-50/50">{{ $day->day_name }}</td>

                        @if(in_array($plan->plan_type, ['hifz', 'hifz_review']))
                            <td class="py-2 px-2 border-r border-zinc-300 text-right leading-relaxed ">
                                {{ $day->formatRange('hifz') }}
                            </td>
                            <td class="py-2 px-1 align-middle"></td>
                        @endif

                        @if(in_array($plan->plan_type, ['review', 'hifz_review']))
                            <td class="py-2 px-2 border-r border-zinc-300 text-right leading-relaxed ">
                                {{ $day->formatRange('review') }}
                            </td>
                            <td class="py-2 px-1 align-middle"></td>
                        @endif
                    </tr>
                @endforeach
            </tbody>
        </table>

    </div>

    <hr class="my-4">
    <p class="text-center text-xs text-zinc-500">جدة - حي الواحة - جامع الزبيدي - حلقات التاج القرآنية التابعة لجمعية
        خيركم لتعليم القرآن الكريم</p>
</body>

</html>