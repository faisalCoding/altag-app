<?php

use Livewire\Component;
use App\Models\Student;
use App\Models\Surah;
use App\Models\Ayah;
use App\Models\StudentPlan;
use App\Models\StudentPlanDay;
use App\Services\QuranPlanService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

new class extends Component
{
    public $studentId;
    public $startDate;
    public $daysCount = 10;
    public $activeDays = ['Sunday', 'Monday', 'Tuesday', 'Wednesday'];
    public $description;
    public $planType = 'hifz'; // 'hifz', 'hifz_review', 'review'

    public $planDays = []; 
    public $allSurahs = [];
    public $fillDirection = 'reverse'; // تصاعدي / تنازلي
    public $fillTarget = 'hifz'; // 'hifz' | 'review'
    public $bulkStartSurah;
    public $bulkStartVerse;
    public $selectAll = false;

    public function mount()
    {
        $this->startDate = now()->format('Y-m-d');
        $this->allSurahs = Surah::orderBy('id')->get();
        $this->bulkStartSurah = 114;
        $this->bulkStartVerse = 1;
        $this->updatedPlanType();
    }

    public function updatedBulkStartSurah()
    {
        $this->bulkStartVerse = 1;
    }

    public function updatedPlanType()
    {
        if ($this->planType === 'review') {
            $this->fillTarget = 'review';
        } else {
            $this->fillTarget = 'hifz';
        }
    }

    public function updatedPlanDays($value, $key)
    {
        // Catch if they change surah_id manually
        if (str_ends_with($key, 'from_surah_id') || str_ends_with($key, 'to_surah_id') || 
            str_ends_with($key, 'review_from_surah_id') || str_ends_with($key, 'review_to_surah_id')) {
            $parts = explode('.', $key);
            $index = $parts[0];
            $field = $parts[1]; 
            
            $verseField = str_replace('_surah_id', '_verse', $field);
            $this->planDays[$index][$verseField] = 1;
        }

        // Auto-correct 'to' fields if 'from' changes
        if (str_ends_with($key, 'from_surah_id') || str_ends_with($key, 'from_verse')) {
            $parts = explode('.', $key);
            $index = $parts[0];
            $field = $parts[1]; 

            $prefix = str_replace(['from_surah_id', 'from_verse'], '', $field); // either '' or 'review_'
            
            $fromSurahId = $this->planDays[$index][$prefix . 'from_surah_id'];
            $fromVerse = $this->planDays[$index][$prefix . 'from_verse'];
            $toSurahId = $this->planDays[$index][$prefix . 'to_surah_id'];
            $toVerse = $this->planDays[$index][$prefix . 'to_verse'];

            $service = app(QuranPlanService::class);
            $fromAyah = Ayah::where('surah_id', $fromSurahId)->where('verse_number', $fromVerse)->first();
            $toAyah = Ayah::where('surah_id', $toSurahId)->where('verse_number', $toVerse)->first();

            if ($fromAyah && $toAyah) {
                if ($service->isExceeding($fromAyah, $toAyah, $this->fillDirection) && $fromAyah->id !== $toAyah->id) {
                    // It means To is chronologically before From! We set To to be the very next verse logical step.
                    $nextVerseNum = $fromAyah->verse_number + 1;
                    $nextSurahId = $fromSurahId;
                    
                    $checkNext = Ayah::where('surah_id', $nextSurahId)->where('verse_number', $nextVerseNum)->first();
                    if (!$checkNext) {
                        $nextSurahId = ($this->fillDirection === 'forward') ? $fromSurahId + 1 : $fromSurahId - 1;
                        if ($nextSurahId < 1) $nextSurahId = 114;
                        if ($nextSurahId > 114) $nextSurahId = 1;
                        $nextVerseNum = 1;
                    }

                    $this->planDays[$index][$prefix . 'to_surah_id'] = $nextSurahId;
                    $this->planDays[$index][$prefix . 'to_verse'] = $nextVerseNum;
                }
            }
        }
    }

    public function updatedSelectAll($value)
    {
        foreach ($this->planDays as &$day) {
            $day['selected'] = $value;
        }
    }

    public function with()
    {
        $teacher = Auth::guard('teacher')->user();
        $circleIds = $teacher->circles->pluck('id');
        $students = Student::whereIn('circle_id', $circleIds)->orderBy('name')->get();

        return [
            'students' => $students,
        ];
    }

    public function generateDays()
    {
        $this->validate([
            'studentId' => 'required',
            'startDate' => 'required|date',
            'daysCount' => 'required|integer|min:1|max:100',
            'activeDays' => 'required|array|min:1',
        ]);

        $this->planDays = [];
        $currentDate = Carbon::parse($this->startDate);
        $count = 0;

        $ayah = Ayah::where('surah_id', $this->bulkStartSurah)
                    ->where('verse_number', $this->bulkStartVerse)
                    ->first() ?: Ayah::first();
        
        $surahId = $ayah->surah_id ?? 114;
        $verseNum = $ayah->verse_number ?? 1;

        while ($count < $this->daysCount) {
            $dayOfWeek = $currentDate->format('l');
            if (in_array($dayOfWeek, $this->activeDays)) {
                
                $this->planDays[] = [
                    'date' => $currentDate->toDateString(),
                    'hijri' => $this->getHijriLabel($currentDate),
                    'day_name_ar' => $this->translateDay($dayOfWeek),
                    'from_surah_id' => $surahId,
                    'from_verse' => $verseNum,
                    'to_surah_id' => $surahId,
                    'to_verse' => $verseNum,
                    'review_from_surah_id' => $surahId,
                    'review_from_verse' => $verseNum,
                    'review_to_surah_id' => $surahId,
                    'review_to_verse' => $verseNum,
                    'selected' => false,
                ];
                $count++;
            }
            $currentDate->addDay();
        }
    }

    public function fillSelected($type)
    {
        $service = app(QuranPlanService::class);
        $lastDayStart = null;
        $lastDayEnd = null;
        $fixedReviewStart = null;

        $fromSurahKey = $this->fillTarget === 'review' ? 'review_from_surah_id' : 'from_surah_id';
        $fromVerseKey = $this->fillTarget === 'review' ? 'review_from_verse' : 'from_verse';
        $toSurahKey = $this->fillTarget === 'review' ? 'review_to_surah_id' : 'to_surah_id';
        $toVerseKey = $this->fillTarget === 'review' ? 'review_to_verse' : 'to_verse';

        if ($this->fillTarget === 'review' && $this->planType === 'hifz_review') {
            foreach ($this->planDays as $day) {
                if ($day['selected']) {
                    $fixedReviewStart = Ayah::where('surah_id', $day['review_from_surah_id'])
                        ->where('verse_number', $day['review_from_verse'])
                        ->first();
                    break;
                }
            }
        }

        $resetNextReview = false;

        foreach ($this->planDays as &$day) {
            if (!$day['selected']) {
                $lastDayStart = Ayah::where('surah_id', $day[$fromSurahKey])
                    ->where('verse_number', $day[$fromVerseKey])
                    ->first();
                $lastDayEnd = Ayah::where('surah_id', $day[$toSurahKey])
                    ->where('verse_number', $day[$toVerseKey])
                    ->first();
                continue;
            }

            if ($this->fillTarget === 'review' && $this->planType === 'hifz_review') {
                $hifzStartAyah = Ayah::where('surah_id', $day['from_surah_id'])
                                     ->where('verse_number', $day['from_verse'])
                                     ->first();
                                     
                if (!$hifzStartAyah || !$fixedReviewStart) continue;

                $maxPossibleEnd = $service->getAyahBefore($hifzStartAyah, $this->fillDirection);

                // 1. Determine the Start of this day's review
                if ($type === 'all_previous') {
                    $actualStart = $fixedReviewStart;
                    $targetReviewEnd = $maxPossibleEnd;
                } else {
                    if ($resetNextReview) {
                        $actualStart = $fixedReviewStart;
                        $resetNextReview = false;
                    } else if ($lastDayEnd) {
                        $actualStart = $service->getNextStartAyah($lastDayStart, $lastDayEnd, $type, $this->fillDirection);
                    } else {
                        $actualStart = $fixedReviewStart;
                    }
                    
                    if (!$actualStart) $actualStart = $fixedReviewStart;

                    // 4. Ensure Start is not already beyond Hifz
                    if ($service->isExceeding($actualStart, $maxPossibleEnd, $this->fillDirection)) {
                        $actualStart = $maxPossibleEnd;
                        $resetNextReview = true;
                    }

                    // 2. Determine the End of this day's review based on volume
                    $targetReviewEnd = $service->getEndAyah($actualStart, $type, $this->fillDirection);

                    // 3. Cap the End so it doesn't overlap Hifz
                    if ($service->isExceeding($targetReviewEnd, $maxPossibleEnd, $this->fillDirection)) {
                        $targetReviewEnd = $maxPossibleEnd;
                        $resetNextReview = true;
                    }
                }

                $day['review_from_surah_id'] = $actualStart->surah_id;
                $day['review_from_verse'] = $actualStart->verse_number;
                $day['review_to_surah_id'] = $targetReviewEnd->surah_id;
                $day['review_to_verse'] = $targetReviewEnd->verse_number;
                
                $lastDayStart = $actualStart;
                $lastDayEnd = $targetReviewEnd;
                continue;
            }

            if ($lastDayStart && $lastDayEnd) {
                $start = $service->getNextStartAyah($lastDayStart, $lastDayEnd, $type, $this->fillDirection);
                if ($start) {
                    $day[$fromSurahKey] = $start->surah_id;
                    $day[$fromVerseKey] = $start->verse_number;
                }
            }

            $currentStart = Ayah::where('surah_id', $day[$fromSurahKey])
                ->where('verse_number', $day[$fromVerseKey])
                ->first();

            if ($currentStart) {
                $hifzStartAyah = null;
                if ($this->fillTarget === 'review') {
                    $hifzStartAyah = Ayah::where('surah_id', $day['from_surah_id'])
                                         ->where('verse_number', $day['from_verse'])
                                         ->first();
                }

                $end = $service->getEndAyah($currentStart, $type, $this->fillDirection, $hifzStartAyah);
                
                $day[$toSurahKey] = $end->surah_id;
                $day[$toVerseKey] = $end->verse_number;
                
                $lastDayStart = $currentStart;
                $lastDayEnd = $end;
            }
        }
    }

    public function save()
    {
        $this->validate([
            'studentId' => 'required',
            'planDays' => 'required|array|min:1',
        ]);

        $plan = StudentPlan::create([
            'student_id' => $this->studentId,
            'teacher_id' => Auth::guard('teacher')->id(),
            'start_date' => $this->startDate,
            'days_count' => $this->daysCount,
            'active_days' => $this->activeDays,
            'description' => $this->description,
            'plan_type' => $this->planType,
            'status' => 'active', 
        ]);

        foreach ($this->planDays as $dayData) {
            $from = null;
            $to = null;
            $revFrom = null;
            $revTo = null;

            if (in_array($this->planType, ['hifz', 'hifz_review'])) {
                $from = Ayah::where('surah_id', $dayData['from_surah_id'])->where('verse_number', $dayData['from_verse'])->first();
                $to = Ayah::where('surah_id', $dayData['to_surah_id'])->where('verse_number', $dayData['to_verse'])->first();
            }

            if (in_array($this->planType, ['review', 'hifz_review'])) {
                $revFrom = Ayah::where('surah_id', $dayData['review_from_surah_id'])->where('verse_number', $dayData['review_from_verse'])->first();
                $revTo = Ayah::where('surah_id', $dayData['review_to_surah_id'])->where('verse_number', $dayData['review_to_verse'])->first();
            }

            $plan->days()->create([
                'date' => $dayData['date'],
                'day_name' => $dayData['day_name_ar'],
                'from_ayah_id' => $from?->id,
                'to_ayah_id' => $to?->id,
                'review_from_ayah_id' => $revFrom?->id,
                'review_to_ayah_id' => $revTo?->id,
            ]);
        }

        return redirect()->route('teacher.dashboard')->with('success', 'تم حفظ الخطة بنجاح');
    }

    protected function getHijriLabel(Carbon $date)
    {
        $formatter = new \IntlDateFormatter(
            'ar_SA@calendar=islamic-umalqura',
            \IntlDateFormatter::FULL,
            \IntlDateFormatter::NONE,
            'Asia/Riyadh',
            \IntlDateFormatter::TRADITIONAL,
            'd MMMM yyyy'
        );
        return $formatter->format($date->timestamp);
    }

    protected function translateDay($day)
    {
        $days = [
            'Sunday' => 'الأحد',
            'Monday' => 'الاثنين',
            'Tuesday' => 'الثلاثاء',
            'Wednesday' => 'الأربعاء',
            'Thursday' => 'الخميس',
            'Friday' => 'الجمعة',
            'Saturday' => 'السبت',
        ];
        return $days[$day] ?? $day;
    }
};
?>

<div class="space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <flux:heading size="xl" level="1">{{ __('إعداد الخطة الدراسية') }}</flux:heading>
            <flux:subheading>{{ __('قم بتخصيص المهام اليومية للطالب') }}</flux:subheading>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-4 gap-6">
        <!-- الإعدادات -->
        <flux:card class="lg:col-span-1 space-y-4">
            <div class="p-1 bg-zinc-100 dark:bg-zinc-800 rounded-lg flex flex-col gap-1">
                <button wire:click="$set('planType', 'hifz')" 
                    class="w-full py-1.5 text-xs font-medium rounded-md transition-colors {{ $planType === 'hifz' ? 'bg-white dark:bg-zinc-700 shadow-sm text-indigo-600 dark:text-indigo-400' : 'text-zinc-500' }}">
                    {{ __('حفظ فقط') }}
                </button>
                <button wire:click="$set('planType', 'hifz_review')" 
                    class="w-full py-1.5 text-xs font-medium rounded-md transition-colors {{ $planType === 'hifz_review' ? 'bg-white dark:bg-zinc-700 shadow-sm text-indigo-600 dark:text-indigo-400' : 'text-zinc-500' }}">
                    {{ __('حفظ ومراجعة') }}
                </button>
                <button wire:click="$set('planType', 'review')" 
                    class="w-full py-1.5 text-xs font-medium rounded-md transition-colors {{ $planType === 'review' ? 'bg-white dark:bg-zinc-700 shadow-sm text-indigo-600 dark:text-indigo-400' : 'text-zinc-500' }}">
                    {{ __('مراجعة فقط') }}
                </button>
            </div>

            <flux:select label="{{ __('الطالب') }}" wire:model="studentId">
                @foreach($students as $student)
                    <flux:select.option value="{{ $student->id }}">{{ $student->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <div class="space-y-1">
                <flux:label>{{ __('البداية') }}</flux:label>
                <livewire:teacher.hijri-datepicker wire:model="startDate" />
            </div>

            <flux:input type="number" label="{{ __('عدد الأيام') }}" wire:model="daysCount" />

            <div class="space-y-2">
                <flux:label>{{ __('الأيام النشطة') }}</flux:label>
                <div class="grid grid-cols-2 gap-x-2 gap-y-1">
                    @foreach(['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'] as $d)
                        <div class="flex items-center gap-2">
                            <flux:checkbox wire:model="activeDays" value="{{ $d }}" id="day-{{ $d }}" />
                            <flux:label for="day-{{ $d }}" class="text-xs">{{ $this->translateDay($d) }}</flux:label>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="p-3 bg-zinc-50 dark:bg-zinc-800/50 rounded-lg space-y-3">
                <flux:heading size="sm">{{ __('نقطة البداية الافتراضية') }}</flux:heading>
                <flux:select wire:model.live="bulkStartSurah" size="sm">
                    @foreach($allSurahs as $surah)
                        <flux:select.option value="{{ $surah->id }}">{{ $surah->name_arabic }}</flux:select.option>
                    @endforeach
                </flux:select>
                
                <select wire:model="bulkStartVerse" class="w-full text-xs p-1 border border-zinc-200 rounded dark:bg-zinc-800 dark:border-zinc-700">
                    @php
                        $startSurah = $allSurahs->find($bulkStartSurah);
                        $startCount = $startSurah?->verses_count ?? 1;
                    @endphp
                    @for($i=1; $i<=$startCount; $i++)
                        <option value="{{ $i }}">{{ __('آية') }} {{ $i }}</option>
                    @endfor
                </select>
            </div>

            <flux:button variant="primary" class="w-full" wire:click="generateDays">
                {{ __('توليد الجدول') }}
            </flux:button>
        </flux:card>

        <!-- المهام -->
        <div class="lg:col-span-3 space-y-4">
            @if(count($planDays) > 0)
                <flux:card class="p-0 overflow-hidden relative">
                    <div class="p-4 border-b border-zinc-100 dark:border-zinc-800 bg-zinc-50 dark:bg-zinc-800/50 flex flex-col xl:flex-row xl:items-center justify-between gap-4 sticky top-0 z-20">
                        <div class="flex flex-col sm:flex-row sm:items-center gap-4">
                            <flux:heading size="sm">{{ __('التحديد التلقائي') }}</flux:heading>
                            <flux:radio.group wire:model="fillDirection" variant="segmented" size="sm">
                                <flux:radio value="forward" label="{{ __('تصاعدي') }}" />
                                <flux:radio value="reverse" label="{{ __('تنازلي') }}" />
                            </flux:radio.group>
                            
                            @if($planType === 'hifz_review')
                            <flux:radio.group wire:model="fillTarget" variant="segmented" size="sm">
                                <flux:radio value="hifz" label="{{ __('تحديد للحفظ') }}" />
                                <flux:radio value="review" label="{{ __('تحديد للمراجعة') }}" />
                            </flux:radio.group>
                            @endif
                        </div>
                        
                        <div class="flex flex-wrap gap-1 items-center bg-white dark:bg-zinc-900 px-2 py-1.5 rounded border border-zinc-200 dark:border-zinc-700">
                            @if($fillTarget === 'review')
                                @if($planType === 'hifz_review')
                                    <flux:button size="xs"  class="bg-indigo-600" wire:click="fillSelected('all_previous')">{{ __('جميع ما سبق') }}</flux:button>
                                @endif
                                <flux:button size="xs"  class="bg-indigo-600" wire:click="fillSelected('juz')">{{ __('جزء') }}</flux:button>
                                <flux:button size="xs"  class="bg-indigo-600" wire:click="fillSelected('half_juz')">{{ __('نصف جزء') }}</flux:button>
                                <flux:button size="xs"  class="bg-indigo-600" wire:click="fillSelected('5_pages')">{{ __('5 صفحات') }}</flux:button>
                                <flux:button size="xs"  class="bg-indigo-600" wire:click="fillSelected('3_surahs')">{{ __('3 سور') }}</flux:button>
                            @else
                                <flux:button size="xs"  class="bg-indigo-600" wire:click="fillSelected('surah')">{{ __('سورة') }}</flux:button>
                                <flux:button size="xs" variant="ghost" wire:click="fillSelected('page')">{{ __('صفحات') }}</flux:button>
                                <flux:button size="xs" variant="ghost" wire:click="fillSelected('half')">{{ __('1/2 صفحة') }}</flux:button>
                                <flux:button size="xs" variant="ghost" wire:click="fillSelected('third')">{{ __('1/3 صفحة') }}</flux:button>
                            @endif
                        </div>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-sm text-right align-middle whitespace-nowrap">
                            <thead class="bg-zinc-50/50 dark:bg-zinc-800/50 border-b border-zinc-200 dark:border-zinc-700">
                                <tr>
                                    <th class="p-3 w-8"><flux:checkbox wire:model.live="selectAll" /></th>
                                    <th class="p-3 w-32 font-medium text-zinc-500">{{ __('التاريخ') }}</th>
                                    @if(in_array($planType, ['hifz', 'hifz_review']))
                                    <th class="p-3 min-w-[300px] border-r border-zinc-200 dark:border-zinc-700">
                                        <span class="text-indigo-600 dark:text-indigo-400 font-bold ml-2">{{ __('الحفظ') }}</span>
                                        <div class="grid grid-cols-2 text-xs text-zinc-500 mt-1">
                                            <span>من</span><span>إلى</span>
                                        </div>
                                    </th>
                                    @endif
                                    @if(in_array($planType, ['review', 'hifz_review']))
                                    <th class="p-3 min-w-[300px] border-r border-zinc-200 dark:border-zinc-700">
                                        <span class="text-emerald-600 dark:text-emerald-400 font-bold ml-2">{{ __('المراجعة') }}</span>
                                        <div class="grid grid-cols-2 text-xs text-zinc-500 mt-1">
                                            <span>من</span><span>إلى</span>
                                        </div>
                                    </th>
                                    @endif
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                                @foreach($planDays as $index => $day)
                                    <tr wire:key="row-{{ $index }}">
                                        <td class="p-3"><flux:checkbox wire:model="planDays.{{ $index }}.selected" /></td>
                                        <td class="p-3">
                                            <div class="flex flex-col">
                                                <span class="text-xs font-bold">{{ $day['day_name_ar'] }}</span>
                                                <span class="text-[10px] text-zinc-400">{{ $day['hijri'] }}</span>
                                            </div>
                                        </td>

                                        @if(in_array($planType, ['hifz', 'hifz_review']))
                                        <td class="p-3 border-r border-zinc-200 dark:border-zinc-700">
                                            <div class="grid grid-cols-2 gap-2">
                                                <div class="flex items-center gap-1">
                                                    <select wire:model.live="planDays.{{ $index }}.from_surah_id" class="text-[11px] p-1.5 border border-zinc-200 rounded dark:bg-zinc-800 dark:border-zinc-700 w-full max-w-[100px]">
                                                        @foreach($allSurahs as $surah)
                                                            <option value="{{ $surah->id }}">{{ $surah->name_arabic }}</option>
                                                        @endforeach
                                                    </select>
                                                    <select wire:model="planDays.{{ $index }}.from_verse" class="text-[11px] p-1.5 border border-zinc-200 rounded dark:bg-zinc-800 dark:border-zinc-700 w-16">
                                                        @php
                                                            $fSurah = $allSurahs->find($day['from_surah_id']);
                                                            $fCount = $fSurah?->verses_count ?? 1;
                                                        @endphp
                                                        @for($i=1; $i<=$fCount; $i++)
                                                            <option value="{{ $i }}">{{ $i }}</option>
                                                        @endfor
                                                    </select>
                                                </div>
                                                <div class="flex items-center gap-1">
                                                    <select wire:model.live="planDays.{{ $index }}.to_surah_id" class="text-[11px] p-1.5 border border-zinc-200 rounded dark:bg-zinc-800 dark:border-zinc-700 w-full max-w-[100px]">
                                                        @foreach($allSurahs as $surah)
                                                            @if($fillDirection === 'forward' && $surah->id < $day['from_surah_id']) @continue @endif
                                                            @if($fillDirection === 'reverse' && $surah->id > $day['from_surah_id']) @continue @endif
                                                            <option value="{{ $surah->id }}">{{ $surah->name_arabic }}</option>
                                                        @endforeach
                                                    </select>
                                                    <select wire:model="planDays.{{ $index }}.to_verse" class="text-[11px] p-1.5 border border-zinc-200 rounded dark:bg-zinc-800 dark:border-zinc-700 w-16">
                                                        @php
                                                            $tSurah = $allSurahs->find($day['to_surah_id']);
                                                            $tCount = $tSurah?->verses_count ?? 1;
                                                        @endphp
                                                        @for($i=1; $i<=$tCount; $i++)
                                                            @if($day['to_surah_id'] == $day['from_surah_id'] && $i < $day['from_verse']) @continue @endif
                                                            <option value="{{ $i }}">{{ $i }}</option>
                                                        @endfor
                                                    </select>
                                                </div>
                                            </div>
                                        </td>
                                        @endif

                                        @if(in_array($planType, ['review', 'hifz_review']))
                                        <td class="p-3 border-r border-zinc-200 dark:border-zinc-700">
                                            <div class="grid grid-cols-2 gap-2">
                                                <div class="flex items-center gap-1">
                                                    <select wire:model.live="planDays.{{ $index }}.review_from_surah_id" class="text-[11px] p-1.5 border border-zinc-200 rounded dark:bg-zinc-800 dark:border-zinc-700 w-full max-w-[100px]">
                                                        @foreach($allSurahs as $surah)
                                                            <option value="{{ $surah->id }}">{{ $surah->name_arabic }}</option>
                                                        @endforeach
                                                    </select>
                                                    <select wire:model="planDays.{{ $index }}.review_from_verse" class="text-[11px] p-1.5 border border-zinc-200 rounded dark:bg-zinc-800 dark:border-zinc-700 w-16">
                                                        @php
                                                            $rfSurah = $allSurahs->find($day['review_from_surah_id']);
                                                            $rfCount = $rfSurah?->verses_count ?? 1;
                                                        @endphp
                                                        @for($i=1; $i<=$rfCount; $i++)
                                                            <option value="{{ $i }}">{{ $i }}</option>
                                                        @endfor
                                                    </select>
                                                </div>
                                                <div class="flex items-center gap-1">
                                                    <select wire:model.live="planDays.{{ $index }}.review_to_surah_id" class="text-[11px] p-1.5 border border-zinc-200 rounded dark:bg-zinc-800 dark:border-zinc-700 w-full max-w-[100px]">
                                                        @foreach($allSurahs as $surah)
                                                            @if($fillDirection === 'forward' && $surah->id < $day['review_from_surah_id']) @continue @endif
                                                            @if($fillDirection === 'reverse' && $surah->id > $day['review_from_surah_id']) @continue @endif
                                                            <option value="{{ $surah->id }}">{{ $surah->name_arabic }}</option>
                                                        @endforeach
                                                    </select>
                                                    <select wire:model="planDays.{{ $index }}.review_to_verse" class="text-[11px] p-1.5 border border-zinc-200 rounded dark:bg-zinc-800 dark:border-zinc-700 w-16">
                                                        @php
                                                            $rtSurah = $allSurahs->find($day['review_to_surah_id']);
                                                            $rtCount = $rtSurah?->verses_count ?? 1;
                                                        @endphp
                                                        @for($i=1; $i<=$rtCount; $i++)
                                                            @if($day['review_to_surah_id'] == $day['review_from_surah_id'] && $i < $day['review_from_verse']) @continue @endif
                                                            <option value="{{ $i }}">{{ $i }}</option>
                                                        @endfor
                                                    </select>
                                                </div>
                                            </div>
                                        </td>
                                        @endif
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="p-3 bg-zinc-50 dark:bg-zinc-800/50 flex justify-end">
                        <flux:button variant="primary" wire:click="save">{{ __('اعتماد الخطة') }}</flux:button>
                    </div>
                </flux:card>
            @else
                <div class="h-64 flex flex-col items-center justify-center border-2 border-dashed border-zinc-100 dark:border-zinc-800 rounded-2xl text-zinc-400">
                    <flux:icon icon="pencil-square" size="xl" class="mb-2 opacity-50 text-indigo-500" />
                    <p class="text-sm">{{ __('اضغط توليد الجدول للبدء') }}</p>
                </div>
            @endif
        </div>
    </div>
</div>