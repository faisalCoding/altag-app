@php
    use App\Models\StudentExam;
    use App\Support\HijriDate;
@endphp
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ __('دفعة اختبار') }} - {{ $when }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <x-branding-styles />
    <style>
        @page { size: A4 portrait; margin: 12mm; }
        body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        thead { display: table-header-group; }
        tr { break-inside: avoid; }
        @media print {
            .no-print { display: none !important; }
            body { background-color: white !important; padding: 0 !important; }
            .sheet { box-shadow: none !important; border: 0 !important; padding: 0 !important; }
        }
    </style>
</head>

{{-- Opens the print dialog at once: the page is reached from «طباعة» on the batch. --}}
<body class="bg-zinc-100 p-4 text-zinc-900" onload="window.print()">
    <div class="no-print mx-auto mb-4 flex max-w-4xl flex-wrap items-center justify-between gap-2">
        <p class="text-sm text-zinc-600">{{ __('اطبع الكشف، أو اختر «حفظ بتنسيق PDF» من نافذة الطباعة.') }}</p>
        <button onclick="window.print()" class="rounded-lg bg-zinc-900 px-4 py-2 text-sm font-bold text-white hover:bg-zinc-700">{{ __('طباعة') }}</button>
    </div>

    <div class="sheet mx-auto max-w-4xl rounded-2xl border border-zinc-200 bg-white p-8 shadow-sm">
        <header class="mb-6 border-b border-zinc-200 pb-4">
            <p class="text-sm text-zinc-500">{{ __('كشف أسماء دفعة الاختبار') }}</p>
            <h1 class="mt-1 text-xl font-bold">{{ $when }}</h1>
            <p class="mt-1 text-sm text-zinc-600">{{ __('عدد الطلاب:') }} {{ HijriDate::arabicDigits($roster->count()) }}</p>
            @if($batch->notes)
                <p class="mt-2 text-sm text-zinc-600">{{ $batch->notes }}</p>
            @endif
        </header>

        <table class="w-full border-collapse text-sm">
            <thead>
                <tr class="bg-zinc-100">
                    <th class="border border-zinc-300 px-2 py-2 w-10">{{ __('م') }}</th>
                    <th class="border border-zinc-300 px-2 py-2 text-start">{{ __('اسم الطالب') }}</th>
                    <th class="border border-zinc-300 px-2 py-2 text-start">{{ __('المرحلة') }}</th>
                    <th class="border border-zinc-300 px-2 py-2 text-start">{{ __('الحلقة') }}</th>
                    <th class="border border-zinc-300 px-2 py-2 text-start">{{ __('مستوى الاختبار') }}</th>
                    <th class="border border-zinc-300 px-2 py-2 w-24">{{ __('النتيجة') }}</th>
                    <th class="border border-zinc-300 px-2 py-2 w-20">{{ __('الدرجة') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse($roster as $exam)
                    <tr>
                        <td class="border border-zinc-300 px-2 py-2.5 text-center">{{ HijriDate::arabicDigits($loop->iteration) }}</td>
                        <td class="border border-zinc-300 px-2 py-2.5 font-bold">{{ $exam->student?->name }}</td>
                        <td class="border border-zinc-300 px-2 py-2.5">{{ $exam->student?->circle?->stage?->name }}</td>
                        <td class="border border-zinc-300 px-2 py-2.5">{{ $exam->student?->circle?->name }}</td>
                        <td class="border border-zinc-300 px-2 py-2.5">{{ $exam->examLevel?->name }}</td>
                        {{-- Left blank to be written in on the day, until a result is recorded. --}}
                        <td class="border border-zinc-300 px-2 py-2.5 text-center">{{ in_array($exam->status, StudentExam::RESULTS, true) ? StudentExam::STATUS_LABELS[$exam->status] : '' }}</td>
                        <td class="border border-zinc-300 px-2 py-2.5 text-center">{{ $exam->score_percentage === null ? '' : rtrim(rtrim((string) $exam->score_percentage, '0'), '.') }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="border border-zinc-300 px-2 py-6 text-center text-zinc-500">{{ __('لا طلاب في هذه الدفعة.') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</body>

</html>
