<?php

use Livewire\Component;
use App\Models\Student;
use App\Models\StudentPlanDay;
use Illuminate\Support\Facades\Auth;

new class extends Component
{
    public $date;
    public $pairs = [];
    public $oneWayPairs = [];
    public $unpaired = [];
    public $hasGenerated = false;

    public function mount()
    {
        $this->date = now()->format('Y-m-d');
    }

    public function generatePairs()
    {
        $teacher = Auth::guard('teacher')->user();
        $circleIds = $teacher->circles()->pluck('circles.id');

        // 1. Get present/late students
        $students = Student::whereIn('circle_id', $circleIds)
            ->whereHas('attendances', function($q) {
                $q->whereDate('date', $this->date)
                  ->whereIn('status', ['present', 'late']);
            })->get();

        // 2. For each student, find their pending review range, and their memorized limit
        $studentData = [];
        foreach ($students as $student) {
            $memorizedRange = $student->getMemorizedRange();
            if (!$memorizedRange) {
                // Cannot be paired if they don't have any memorized limit
                continue;
            }

            // Find the pending review
            $planDay = StudentPlanDay::whereHas('plan', function($q) use ($student) {
                $q->where('student_id', $student->id)
                  ->where('is_approved', true)
                  ->where('status', 'active');
            })
            ->whereNotNull('review_from_ayah_id')
            ->whereNotNull('review_to_ayah_id')
            // A «لم يسمع» (0) was not recited, so that review is still pending.
            ->where(fn ($q) => $q->whereNull('review_achievement')->orWhere('review_achievement', 0))
            ->orderBy('date', 'asc')
            ->first();

            if ($planDay) {
                $hasReview = true;
                $reviewMin = min($planDay->review_from_ayah_id, $planDay->review_to_ayah_id);
                $reviewMax = max($planDay->review_from_ayah_id, $planDay->review_to_ayah_id);
                $reviewText = $planDay->formatRange('review');
            } else {
                $hasReview = false;
                // If they have no pending review, consider their entire memorization (for display only)
                $reviewMin = $memorizedRange['min'];
                $reviewMax = $memorizedRange['max'];
                
                $minSurah = \App\Models\Ayah::find($reviewMin)->surah->name_arabic ?? '';
                $maxSurah = \App\Models\Ayah::find($reviewMax)->surah->name_arabic ?? '';
                $reviewText = "محفوظه بالكامل (" . $minSurah . " - " . $maxSurah . ")";
            }

            $memMinAyah = \App\Models\Ayah::find($memorizedRange['min']);
            $memMaxAyah = \App\Models\Ayah::find($memorizedRange['max']);
            $memMinText = $memMinAyah ? $memMinAyah->surah->name_arabic . ' (' . $memMinAyah->verse_number . ')' : '';
            $memMaxText = $memMaxAyah ? $memMaxAyah->surah->name_arabic . ' (' . $memMaxAyah->verse_number . ')' : '';
            $memText = "المحفوظ: " . $memMinText . " ➔ " . $memMaxText;

            $effectiveMemMin = $memorizedRange['min'];
            $effectiveMemMax = $memorizedRange['max'];

            if ($hasReview) {
                $effectiveMemMin = min($effectiveMemMin, $reviewMin);
                $effectiveMemMax = max($effectiveMemMax, $reviewMax);
            }

            $studentData[$student->id] = [
                'student' => $student,
                'has_review' => $hasReview,
                'review_min' => $reviewMin,
                'review_max' => $reviewMax,
                'mem_min' => $memorizedRange['min'],
                'mem_max' => $memorizedRange['max'],
                'effective_mem_min' => $effectiveMemMin,
                'effective_mem_max' => $effectiveMemMax,
                'mem_text' => $memText,
                'review_text' => $reviewText,
            ];
        }

        // 3. Build compatibility graph
        $edges = [];
        $degrees = [];
        $canReciteTo = [];
        $studentIds = array_keys($studentData);

        foreach ($studentIds as $id) {
            $degrees[$id] = 0;
            $edges[$id] = [];
            $canReciteTo[$id] = [];
        }

        for ($i = 0; $i < count($studentIds); $i++) {
            for ($j = $i + 1; $j < count($studentIds); $j++) {
                $idA = $studentIds[$i];
                $idB = $studentIds[$j];

                $a = $studentData[$idA];
                $b = $studentData[$idB];

                // A recites to B: A's review is within B's mem (only if A actually has a review)
                $aToB = !$a['has_review'] || ($a['review_min'] >= $b['effective_mem_min'] && $a['review_max'] <= $b['effective_mem_max']);
                
                // B recites to A: B's review is within A's mem (only if B actually has a review)
                $bToA = !$b['has_review'] || ($b['review_min'] >= $a['effective_mem_min'] && $b['review_max'] <= $a['effective_mem_max']);

                // However, they must not BOTH be without a review, otherwise they have nothing to do!
                if (!$a['has_review'] && !$b['has_review']) {
                    $aToB = false; // Prevents them from pairing uselessly
                    $bToA = false;
                }

                if ($aToB && $a['has_review']) {
                    $canReciteTo[$idA][] = $b['student']->name;
                }
                
                if ($bToA && $b['has_review']) {
                    $canReciteTo[$idB][] = $a['student']->name;
                }

                if ($aToB && $bToA) {
                    $edges[$idA][] = $idB;
                    $edges[$idB][] = $idA;
                    $degrees[$idA]++;
                    $degrees[$idB]++;
                }
            }
        }

        // 4. Greedy matching
        $matched = [];
        $pairs = [];

        // Sort ids by degree ascending
        uasort($degrees, function($a, $b) {
            return $a <=> $b;
        });

        foreach ($degrees as $id => $degree) {
            if (in_array($id, $matched) || $degree === 0) continue;

            // Find available partner with the lowest degree
            $bestPartner = null;
            $bestPartnerDegree = PHP_INT_MAX;

            foreach ($edges[$id] as $partnerId) {
                if (!in_array($partnerId, $matched)) {
                    if ($degrees[$partnerId] < $bestPartnerDegree) {
                        $bestPartnerDegree = $degrees[$partnerId];
                        $bestPartner = $partnerId;
                    }
                }
            }

            if ($bestPartner) {
                $matched[] = $id;
                $matched[] = $bestPartner;
                $pairs[] = [
                    'student1' => $studentData[$id],
                    'student2' => $studentData[$bestPartner],
                ];
            }
        }

        // 4.5 One-Way matching (Reciter and Listener)
        $oneWayPairs = [];
        $unmatchedIds = array_diff($studentIds, $matched);

        // Sort unmatched students by memorization amount (highest first) to give them priority
        usort($unmatchedIds, function($idA, $idB) use ($studentData) {
            $memCountA = $studentData[$idA]['effective_mem_max'] - $studentData[$idA]['effective_mem_min'];
            $memCountB = $studentData[$idB]['effective_mem_max'] - $studentData[$idB]['effective_mem_min'];
            return $memCountB <=> $memCountA;
        });

        foreach ($unmatchedIds as $idA) {
            if (in_array($idA, $matched)) continue;

            foreach ($unmatchedIds as $idB) {
                if ($idA === $idB) continue;
                if (in_array($idB, $matched)) continue;

                $a = $studentData[$idA];
                $b = $studentData[$idB];

                // For one-way, the reciter MUST have a review
                $aToB = $a['has_review'] && ($a['review_min'] >= $b['effective_mem_min'] && $a['review_max'] <= $b['effective_mem_max']);
                $bToA = $b['has_review'] && ($b['review_min'] >= $a['effective_mem_min'] && $b['review_max'] <= $a['effective_mem_max']);

                if ($aToB) {
                    $matched[] = $idA;
                    $matched[] = $idB;
                    $oneWayPairs[] = [
                        'reciter' => $a,
                        'listener' => $b,
                    ];
                    break;
                } elseif ($bToA) {
                    $matched[] = $idA;
                    $matched[] = $idB;
                    $oneWayPairs[] = [
                        'reciter' => $b,
                        'listener' => $a,
                    ];
                    break;
                }
            }
        }

        $unpaired = [];
        foreach ($students as $student) {
            if (!in_array($student->id, $matched)) {
                $reason = "لا يوجد توافق مع بقية الطلاب";
                $memText = null;
                $reviewText = null;
                $listeners = [];
                
                if (!isset($studentData[$student->id])) {
                    $reason = "ليس لديه أي سجل حفظ معتمد في النظام";
                } else {
                    $memText = $studentData[$student->id]['mem_text'];
                    $reviewText = $studentData[$student->id]['review_text'];
                    $listeners = $canReciteTo[$student->id] ?? [];
                }
                
                $unpaired[] = [
                    'student' => $student,
                    'reason' => $reason,
                    'mem_text' => $memText,
                    'review_text' => $reviewText,
                    'listeners' => $listeners,
                ];
            }
        }

        $this->pairs = $pairs;
        $this->oneWayPairs = $oneWayPairs;
        $this->unpaired = $unpaired;
        $this->hasGenerated = true;
    }
};
?>

<div>
    <div class="mb-6 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <flux:heading size="xl">{{ __('نظام الثنائيات (التسميع المتبادل)') }}</flux:heading>
            <flux:subheading>{{ __('قم بتوزيع الطلاب الحاضرين في ثنائيات بحيث يسمع كل طالب مراجعته لزميله الذي يحفظها سلفاً.') }}</flux:subheading>
        </div>
        
        <div class="flex items-center gap-2">
            <div class="w-40">
                <livewire:shared.hijri-datepicker wire:model.live="date" label="" />
            </div>
            <flux:button wire:click="generatePairs" variant="primary" icon="sparkles">
                {{ __('توليد الثنائيات') }}
            </flux:button>
        </div>
    </div>

    @if($hasGenerated)
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            
            <div class="lg:col-span-2 space-y-4">
                <flux:heading size="lg" class="mb-2">{{ __('الثنائيات المتطابقة') }} ({{ count($pairs) }} {{ __('ثنائي') }})</flux:heading>
                
                @forelse($pairs as $index => $pair)
                    <div class="p-5 bg-white dark:bg-zinc-900 border border-indigo-200 dark:border-indigo-500/20 rounded-2xl shadow-sm relative overflow-hidden">
                        <div class="absolute top-0 left-0 w-1.5 h-full bg-indigo-500"></div>
                        
                        <div class="flex items-center justify-between gap-4">
                            
                            {{-- Student 1 --}}
                            <div class="flex-1 text-right">
                                <div class="text-xs text-emerald-600 dark:text-emerald-400 font-medium mb-1">{{ $pair['student1']['mem_text'] }}</div>
                                <div class="font-bold text-lg text-zinc-900 dark:text-zinc-100">{{ $pair['student1']['student']->name }}</div>
                                <div class="text-sm text-zinc-500 mt-1">يُسمِّع لزميله:</div>
                                <div class="mt-2 text-sm font-medium px-3 py-1.5 bg-indigo-50 dark:bg-indigo-500/10 text-indigo-700 dark:text-indigo-400 rounded-lg inline-block">
                                    {{ $pair['student1']['review_text'] }}
                                </div>
                            </div>

                            {{-- Exchange Icon --}}
                            <div class="shrink-0 flex flex-col items-center justify-center p-3 bg-zinc-50 dark:bg-zinc-800 rounded-full text-zinc-400">
                                <flux:icon icon="arrows-right-left" class="size-6" />
                            </div>

                            {{-- Student 2 --}}
                            <div class="flex-1 text-left">
                                <div class="text-xs text-emerald-600 dark:text-emerald-400 font-medium mb-1">{{ $pair['student2']['mem_text'] }}</div>
                                <div class="font-bold text-lg text-zinc-900 dark:text-zinc-100">{{ $pair['student2']['student']->name }}</div>
                                <div class="text-sm text-zinc-500 mt-1">يُسمِّع لزميله:</div>
                                <div class="mt-2 text-sm font-medium px-3 py-1.5 bg-indigo-50 dark:bg-indigo-500/10 text-indigo-700 dark:text-indigo-400 rounded-lg inline-block">
                                    {{ $pair['student2']['review_text'] }}
                                </div>
                            </div>

                        </div>
                    </div>
                @empty
                    <div class="p-8 text-center bg-zinc-50 dark:bg-zinc-800/50 rounded-2xl border border-dashed border-zinc-200 dark:border-zinc-700">
                        <flux:icon icon="face-frown" class="size-8 text-zinc-400 mx-auto mb-3" />
                        <div class="text-zinc-500">{{ __('لم نتمكن من إيجاد أي ثنائيات متطابقة لليوم المحدد.') }}</div>
                    </div>
                @endforelse

                @if(count($oneWayPairs) > 0)
                    <flux:heading size="lg" class="mt-8 mb-2">{{ __('الثنائيات من طرف واحد (مُسَمِّع ومُستَمِع)') }} ({{ count($oneWayPairs) }} {{ __('ثنائي') }})</flux:heading>
                    
                    @foreach($oneWayPairs as $index => $pair)
                        <div class="p-5 bg-white dark:bg-zinc-900 border border-emerald-200 dark:border-emerald-500/20 rounded-2xl shadow-sm relative overflow-hidden">
                            <div class="absolute top-0 left-0 w-1.5 h-full bg-emerald-500"></div>
                            
                            <div class="flex items-center justify-between gap-4">
                                
                                {{-- Reciter --}}
                                <div class="flex-1 text-right">
                                    <div class="text-xs text-emerald-600 dark:text-emerald-400 font-medium mb-1">{{ $pair['reciter']['mem_text'] }}</div>
                                    <div class="font-bold text-lg text-zinc-900 dark:text-zinc-100">{{ $pair['reciter']['student']->name }}</div>
                                    <div class="text-sm text-zinc-500 mt-1">يُسمِّع لزميله:</div>
                                    <div class="mt-2 text-sm font-medium px-3 py-1.5 bg-emerald-50 dark:bg-emerald-500/10 text-emerald-700 dark:text-emerald-400 rounded-lg inline-block">
                                        {{ $pair['reciter']['review_text'] }}
                                    </div>
                                </div>

                                {{-- Direction Icon --}}
                                <div class="shrink-0 flex flex-col items-center justify-center p-3 bg-zinc-50 dark:bg-zinc-800 rounded-full text-zinc-400">
                                    <flux:icon icon="arrow-left" class="size-6" />
                                </div>

                                {{-- Listener --}}
                                <div class="flex-1 text-left">
                                    <div class="text-xs text-emerald-600 dark:text-emerald-400 font-medium mb-1">{{ $pair['listener']['mem_text'] }}</div>
                                    <div class="font-bold text-lg text-zinc-900 dark:text-zinc-100">{{ $pair['listener']['student']->name }}</div>
                                    <div class="text-sm text-zinc-500 mt-1">دور هذا الطالب:</div>
                                    <div class="mt-2 text-sm font-medium px-3 py-1.5 bg-zinc-100 dark:bg-zinc-800 text-zinc-700 dark:text-zinc-300 rounded-lg inline-block">
                                        يستمع لزميله ويصحح له
                                    </div>
                                </div>

                            </div>
                        </div>
                    @endforeach
                @endif
            </div>

            <div>
                <flux:heading size="lg" class="mb-4 text-red-600 dark:text-red-400">{{ __('طلاب بدون ثنائي') }} ({{ count($unpaired) }})</flux:heading>
                
                <div class="space-y-3">
                    @forelse($unpaired as $item)
                        <div class="p-4 bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-xl shadow-sm">
                            <div class="text-xs text-emerald-600 dark:text-emerald-400 font-medium mb-1">
                                {{ $item['mem_text'] ?? 'لا يوجد سجل محفوظ' }}
                            </div>
                            <div class="font-bold text-zinc-900 dark:text-zinc-100">{{ $item['student']->name }}</div>
                            @if($item['review_text'])
                                <div class="text-xs text-indigo-600 dark:text-indigo-400 mt-1">
                                    <span class="font-semibold">يراجع:</span> {{ $item['review_text'] }}
                                </div>
                            @endif
                            @if(count($item['listeners']) > 0)
                                <div class="mt-3 p-2.5 bg-indigo-50 dark:bg-indigo-500/10 rounded-lg border border-indigo-100 dark:border-indigo-500/20">
                                    <div class="text-xs font-bold text-indigo-700 dark:text-indigo-400 mb-1">
                                        يمكنه التسميع لـ:
                                    </div>
                                    <div class="text-sm font-medium text-zinc-800 dark:text-zinc-200 leading-relaxed">
                                        {{ implode('، ', $item['listeners']) }}
                                    </div>
                                </div>
                            @endif
                            <div class="text-xs text-red-500 mt-2 flex items-center gap-1">
                                <flux:icon icon="exclamation-circle" class="size-4" />
                                {{ $item['reason'] }}
                            </div>
                        </div>
                    @empty
                        <div class="p-4 text-center bg-zinc-50 dark:bg-zinc-800/50 rounded-xl text-sm text-zinc-500">
                            {{ __('لا يوجد طلاب متبقين. توزيع مثالي!') }}
                        </div>
                    @endforelse
                </div>
                
                <div class="mt-6 p-4 bg-amber-50 dark:bg-amber-500/10 border border-amber-200 dark:border-amber-500/20 rounded-xl">
                    <div class="text-sm text-amber-800 dark:text-amber-400 font-medium mb-1">ماذا تفعل بهؤلاء؟</div>
                    <div class="text-xs text-amber-700 dark:text-amber-500">
                        الطلاب غير الموزعين يجب أن يقوموا بتسميع المراجعة للمعلم مباشرة.
                    </div>
                </div>
            </div>

        </div>
    @else
        <div class="flex flex-col items-center justify-center p-12 bg-white dark:bg-zinc-900 rounded-3xl border border-zinc-100 dark:border-zinc-800 shadow-sm text-center">
            <div class="w-20 h-20 bg-indigo-50 dark:bg-indigo-500/10 text-indigo-500 rounded-full flex items-center justify-center mb-6">
                <flux:icon icon="users" class="size-10" />
            </div>
            <flux:heading size="xl" class="mb-2">{{ __('توزيع الثنائيات الذكي') }}</flux:heading>
            <p class="text-zinc-500 max-w-md mx-auto mb-6">
                {{ __('قم باختيار تاريخ اليوم واضغط على زر التوليد. سيقوم النظام بتحليل خطط جميع الطلاب الحاضرين ومطابقة مراجعاتهم مع محفوظات بعضهم البعض لاستخراج أكبر عدد ممكن من الثنائيات.') }}
            </p>
        </div>
    @endif
</div>