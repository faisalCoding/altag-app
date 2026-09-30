@php
    /*
     * One grade, one colour, everywhere on the page: green for ممتاز, blue for
     * جيد, amber for مقبول. The classes are spelled out rather than built from
     * the grade so Tailwind can see them, and the print-colour rules below keep
     * them on paper — browsers drop background colours when printing otherwise.
     */
    $grades = [
        3 => ['label' => 'ممتاز', 'cell' => 'grade-excellent'],
        2 => ['label' => 'جيد', 'cell' => 'grade-good'],
        1 => ['label' => 'مقبول', 'cell' => 'grade-acceptable'],
    ];
@endphp
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title }} - {{ $student->name }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <x-branding-styles />
    <style>
        @media print {
            .no-print { display: none !important; }
            body { background-color: white !important; font-size: 11pt; }
            @page { margin: 0.4cm; }
        }

        .print-table th,
        .print-table td { border: 0.7px solid #d4d4d8; }

        .print-table th {
            background-color: #f4f4f5;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        /* Graded days keep their colour on paper as well as on screen. */
        .grade-excellent,
        .grade-good,
        .grade-acceptable {
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
            font-weight: 700;
        }

        .grade-excellent { background-color: #dcfce7; color: #15803d; }
        .grade-good      { background-color: #dbeafe; color: #1d4ed8; }
        .grade-acceptable{ background-color: #fef3c7; color: #b45309; }
    </style>
</head>

<body class="bg-white text-zinc-900 font-sans antialiased p-4 sm:p-8 max-w-5xl mx-auto">

    <div class="no-print mb-4 flex flex-wrap justify-between items-center gap-2">
        <div class="flex items-center gap-3 text-xs">
            <span class="text-zinc-500">دلالة الألوان:</span>
            @foreach ($grades as $grade)
                <span class="px-2 py-0.5 rounded {{ $grade['cell'] }}">{{ $grade['label'] }}</span>
            @endforeach
            <span class="px-2 py-0.5 rounded border border-zinc-200 text-zinc-400">لم يُقيَّم</span>
        </div>

        <div class="flex gap-2">
            <button onclick="window.print()"
                class="px-3 py-1.5 bg-indigo-600 text-white rounded shadow-sm hover:bg-indigo-700 font-medium text-xs">
                طباعة الخطة
            </button>
            <a href="{{ route('student.dashboard') }}"
                class="px-3 py-1.5 bg-zinc-100 text-zinc-700 rounded hover:bg-zinc-200 font-medium text-xs">
                رجوع
            </a>
        </div>
    </div>

    {{-- Header --}}
    <div class="text-center mb-4 pb-4 border-b border-zinc-200">
        <div class="flex flex-wrap justify-between gap-2 text-xs font-semibold">
            <div class="flex justify-center items-end border border-zinc-200 rounded-3xl p-5 pt-3">
                <div class="flex justify-start items-end w-26">
                    <img src="{{ App\Support\Branding::logoUrl() }}" alt="Logo" class="h-24 object-contain" />
                </div>
                <div class="flex flex-col items-start">
                    <h1 class="text-lg mb-2">{{ $title }}</h1>
                    @if ($subtitle)
                        <div class="text-zinc-500 mb-1">{{ $subtitle }}</div>
                    @endif
                    <div class="flex items-end">
                        <span class="text-zinc-500 ml-1">الطالب:</span>
                        <span>{{ $student->name }}</span>
                    </div>
                </div>
            </div>
            <div class="flex flex-col justify-around border border-zinc-200 rounded-3xl py-7 px-4 gap-1">
                <div>
                    <span class="text-zinc-500 ml-1">الحلقة:</span>
                    <span>{{ $student->circle->name ?? 'غير محدد' }}</span>
                </div>
                @if ($startDate)
                    <div>
                        <span class="text-zinc-500 ml-1">تبدأ من:</span>
                        <span>{{ \App\Support\HijriDate::full($startDate) }}</span>
                    </div>
                @endif
            </div>
        </div>
    </div>

    @php
        $showHifz = collect($rows)->contains(fn ($row) => $row['hifz'] !== null);
        $showReview = collect($rows)->contains(fn ($row) => $row['review'] !== null);
    @endphp

    <div class="overflow-hidden border rounded-2xl border-zinc-300">
        <table class="w-full text-center print-table text-xs sm:text-sm border-collapse">
            <thead>
                <tr>
                    <th class="py-2 px-1 text-xs w-44">التاريخ</th>
                    <th class="py-2 px-1 text-xs w-16">اليوم</th>
                    @if ($showHifz)
                        <th class="py-2 px-2 text-xs border-r border-zinc-300 text-gray-800">الـحـفـظ</th>
                        <th class="py-2 px-1 text-xs w-20">إنجاز الحفظ</th>
                    @endif
                    @if ($showReview)
                        <th class="py-2 px-2 text-xs border-r border-zinc-300 text-gray-800">الـمـراجـعـة</th>
                        <th class="py-2 px-1 text-xs w-20">إنجاز المراجعة</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $row)
                    <tr>
                        <td class="py-2 px-1 text-[11px] text-zinc-600">
                            {{ $row['date'] ? \App\Support\HijriDate::full($row['date']) : '—' }}
                        </td>
                        <td class="py-2 px-1 text-[11px] bg-zinc-50/50">{{ $row['day_name'] ?? '—' }}</td>

                        @foreach (['hifz', 'review'] as $part)
                            @continue($part === 'hifz' ? ! $showHifz : ! $showReview)
                            @php $cell = $row[$part]; @endphp

                            <td class="py-2 px-2 border-r border-zinc-300 text-right leading-relaxed">
                                {{ $cell['range'] ?? '—' }}
                            </td>
                            <td class="py-2 px-1 align-middle {{ $grades[$cell['achievement'] ?? null]['cell'] ?? '' }}">
                                {{-- Blank rather than a dash: an ungraded day is one the student still owes. --}}
                                {{ $grades[$cell['achievement'] ?? null]['label'] ?? '' }}
                            </td>
                        @endforeach
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="py-8 text-zinc-400">لا توجد أيام مجدولة في هذه الخطة.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <hr class="my-4">
    <p class="text-center text-xs text-zinc-500">جدة - حي الواحة - جامع الزبيدي - حلقات التاج القرآنية التابعة لجمعية
        خيركم لتعليم القرآن الكريم</p>
</body>

</html>
