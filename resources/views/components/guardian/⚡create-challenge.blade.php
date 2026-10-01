<?php

use App\Models\Challenge;
use App\Models\ChallengeItem;
use App\Models\Student;
use App\Models\StudentPlan;
use App\Models\StudentPlanDay;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component {
    #[Locked]
    public $studentId;
    public $student;
    public $studentPlans;

    // Unachieved plan days for selection (loaded per selected plan)
    public $planDays = [];

    // Selected day IDs as range [firstId, lastId]
    #[Locked]
    public $selectedDayIds = [];

    // Candidate next exams based on relationships
    public $candidateExams = [];

    public function mount($studentId)
    {
        $this->studentId = $studentId;
        $this->student = Student::where('id', $studentId)
            ->where('guardian_id', Auth::guard('guardian')->id())
            ->firstOrFail();

        $this->studentPlans = StudentPlan::where('student_id', $this->studentId)
            ->orderBy('created_at', 'desc')
            ->get();

        $this->loadCandidateExams();
    }

    public function loadCandidateExams()
    {
        $passedLevelIds = \App\Models\StudentExam::where('student_id', $this->studentId)
            ->where('status', 'passed')
            ->pluck('exam_level_id')
            ->toArray();

        $allLevels = \App\Models\ExamLevel::all();
        $candidates = [];

        foreach ($allLevels as $level) {
            // Condition 1: Student has not passed this level
            if (!in_array($level->id, $passedLevelIds)) {
                // Condition 2: This level has no previous, or the previous IS passed
                if (is_null($level->previous_level_id) || in_array($level->previous_level_id, $passedLevelIds)) {
                    $candidates[] = [
                        'id' => $level->id,
                        'name' => $level->name,
                        'direction' => $level->direction,
                    ];
                }
            }
        }

        $this->candidateExams = $candidates;
    }

    public function loadPlanDays($planId)
    {
        // Load unachieved days (hifz_achievement is null) for this plan
        $this->planDays = StudentPlanDay::where('student_plan_id', $planId)
            ->whereHas('plan', fn ($plan) => $plan->where('student_id', $this->studentId))
            ->whereNull('hifz_achievement')
            ->orderBy('date')
            ->get()
            ->map(fn($d) => [
                'id' => $d->id,
                'date' => $d->date->format('Y-m-d'),
                'hijri' => $this->toHijri($d->date),
                'day_name' => $d->day_name,
            ])
            ->values()
            ->toArray();

        $this->selectedDayIds = [];
    }

    public function selectDayRange($fromIndex, $toIndex)
    {
        $min = min($fromIndex, $toIndex);
        $max = max($fromIndex, $toIndex);
        $this->selectedDayIds = array_map(
            fn($i) => $this->planDays[$i]['id'],
            range($min, $max)
        );
    }

    protected function toHijri(\DateTimeInterface|string $date): string
    {
        $ts = is_string($date) ? strtotime($date) : $date->getTimestamp();
        return \App\Support\HijriDate::format($ts, 'd MMM yyyy');
    }

    public function saveChallenge($data)
    {
        $challenge = Challenge::create([
            'guardian_id' => Auth::guard('guardian')->id(),
            'student_id' => $this->studentId,
            'start_date' => now()->toDateString(),
            'end_date' => null, // No explicit end date anymore
            'prize_type' => $data['prizeType'],
            'prize_description' => $data['prizeType'] === 'financial'
                ? ($data['prizeAmount'] . ' ريال سعودي')
                : $data['prizeDescription'],
            'status' => 'active',
        ]);

        if ($data['rewardType'] === 'attendance') {
            ChallengeItem::create([
                'challenge_id' => $challenge->id,
                'type' => 'attendance',
                'target_value' => $data['attendanceDays'] ?? 0,
                'metadata' => [
                    'mode' => 'consecutive',
                    'no_lateness' => $data['attendanceNoLateness'] ?? false,
                ],
            ]);
        } elseif ($data['rewardType'] === 'recitation' && !empty($this->selectedDayIds)) {
            ChallengeItem::create([
                'challenge_id' => $challenge->id,
                'type' => 'recitation_days',
                'target_value' => count($this->selectedDayIds),
                'metadata' => [
                    'plan_id' => $data['recitationPlanId'],
                    'day_ids' => $this->selectedDayIds,
                    'quality_required' => $data['recitationQualityEnabled'],
                    'quality_req' => $data['recitationQualityRequirement'],
                ],
            ]);
        } elseif ($data['rewardType'] === 'exam') {
            ChallengeItem::create([
                'challenge_id' => $challenge->id,
                'type' => 'exam_passed',
                'target_value' => $data['examPercentage'] ?? 0,
                'metadata' => [
                    'min_percentage' => $data['examPercentage'] ?? 0,
                    'exam_level_id' => $data['examLevelId'] ?? null,
                ],
            ]);
        }

        session()->flash('status', 'تم إنشاء المكافأة التحفيزية بنجاح!');

        return redirect()->route('guardian.dashboard');
    }
};
?>

<div>
    <div x-data="challengeWizard()" class="max-w-2xl mx-auto space-y-8">

        {{-- Header --}}
        <div class="text-center">
            <flux:heading size="xl">{{ __('إنشاء مكافأة تحفيزية جديدة') }}</flux:heading>
            <flux:subheading>{{ __('شجع ابنك بوضع مكافآت تحفيزية للوصول لأهدافه القرانية') }}</flux:subheading>
        </div>

        {{-- Progress Bar --}}
        <div class="relative">
            <div class="overflow-hidden h-2 mb-4 rounded-full bg-zinc-100 dark:bg-zinc-800">
                <div :style="`width: ${(step / 4) * 100}%`" class="h-full rounded-full bg-indigo-500   duration-500">
                </div>
            </div>
            <div class="flex justify-between text-xs font-medium text-zinc-400">
                <span :class="{ 'text-indigo-600 dark:text-indigo-400 font-bold': step >= 1 }">البداية</span>
                <span :class="{ 'text-indigo-600 dark:text-indigo-400 font-bold': step >= 2 }">نوع المكافأة</span>
                <span :class="{ 'text-indigo-600 dark:text-indigo-400 font-bold': step >= 3 }">الهدف</span>
                <span :class="{ 'text-indigo-600 dark:text-indigo-400 font-bold': step >= 4 }">الجائزة</span>
            </div>
        </div>

        {{-- STEP 1: Tip + Student --}}
        <div x-show="step === 1" x-transition:enter="ease-out duration-300"
            x-transition:enter-start="opacity-0 translate-y-4" x-transition:enter-end="opacity-100 translate-y-0"
            class="space-y-6 bg-white dark:bg-zinc-900 p-6 rounded-2xl border border-zinc-200 dark:border-zinc-800 shadow-sm">

            <div
                class="flex items-start gap-4 p-4 bg-indigo-50 dark:bg-indigo-500/10 rounded-xl border border-indigo-100 dark:border-indigo-500/20">
                <div
                    class="bg-indigo-100 dark:bg-indigo-500/20 p-2 rounded-full text-indigo-600 dark:text-indigo-400 shrink-0">
                    <flux:icon icon="light-bulb" variant="solid" />
                </div>
                <div>
                    <flux:heading size="sm" class="text-indigo-900 dark:text-indigo-300 mb-1">نصيحة هامة</flux:heading>
                    <p class="text-sm text-indigo-800 dark:text-indigo-400">
                        كي تضمن النتيجة الأفضل، اجعل شروط المكافأة متدرجة وواقعية،
                        واحرص على أن تكون المكافأة محفّزة للاستمرار في مسيرته.
                    </p>
                </div>
            </div>

            <div>
                <flux:heading size="sm" class="mb-2 text-zinc-600 dark:text-zinc-400">الابن المستهدف</flux:heading>
                <div class="p-3 bg-zinc-50 dark:bg-zinc-800/50 rounded-xl flex items-center gap-3">
                    <div
                        class="size-10 rounded-full bg-indigo-100 dark:bg-indigo-900/30 flex items-center justify-center text-indigo-600">
                        <flux:icon icon="user" />
                    </div>
                    <div class="font-semibold text-zinc-900 dark:text-zinc-100">{{ $student->name }}</div>
                </div>
            </div>
        </div>

        {{-- STEP 2: Challenge Type --}}
        <div x-show="step === 2" style="display: none;" x-transition:enter="ease-out duration-300"
            x-transition:enter-start="opacity-0 translate-y-4" x-transition:enter-end="opacity-100 translate-y-0"
            class="space-y-6 bg-white dark:bg-zinc-900 p-6 rounded-2xl border border-zinc-200 dark:border-zinc-800 shadow-sm">

            <flux:heading size="lg">اختر نوع المكافأة</flux:heading>

            <flux:radio.group x-model="formData.rewardType" variant="cards"
                class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <flux:radio value="attendance" label="الحضور والانضباط" icon="calendar" />
                <flux:radio value="recitation" label="الإنجاز القرآني" icon="book-open" />
                <flux:radio value="exam" label="اختبار مستوى الجمعية" icon="academic-cap" />
            </flux:radio.group>
        </div>

        {{-- STEP 3: Setup Target --}}
        <div x-show="step === 3" style="display: none;" x-transition:enter="ease-out duration-300"
            x-transition:enter-start="opacity-0 translate-y-4" x-transition:enter-end="opacity-100 translate-y-0"
            class="space-y-5 bg-white dark:bg-zinc-900 p-6 rounded-2xl border border-zinc-200 dark:border-zinc-800 shadow-sm">

            <flux:heading size="lg">تحديد الهدف المطلوب</flux:heading>

            {{-- Attendance --}}
            <div x-show="formData.rewardType === 'attendance'" class="space-y-4">
                <flux:input type="number" min="1" x-model="formData.attendanceDays"
                    label="عدد الأيام المتتالية المطلوبة (بلا غياب)" />

                <flux:checkbox x-model="formData.attendanceNoLateness" label="تفعيل شرط عدم التأخر مطلقاً" />
            </div>

            {{-- Recitation --}}
            <div x-show="formData.rewardType === 'recitation'" class="space-y-4">
                {{-- Plan selector --}}
                <div>
                    <flux:label class="mb-1">اختر الجدول الدراسي</flux:label>
                    <div class="space-y-2 p-3 bg-zinc-50 dark:bg-zinc-800/50 rounded-xl">
                        @forelse($studentPlans as $plan)
                            <label class="flex items-center gap-3 cursor-pointer group">
                                <input type="radio" x-model="formData.recitationPlanId" value="{{ $plan->id }}"
                                    @change="$wire.loadPlanDays({{ $plan->id }})" class="rounded-full accent-indigo-600" />
                                <span class="text-sm font-medium group-hover:text-indigo-600   s">
                                    @if($plan->plan_type === 'hifz_review') حفظ ومراجعة
                                    @elseif($plan->plan_type === 'hifz') حفظ
                                    @else مراجعة @endif
                                    <span
                                        class="text-zinc-400 font-normal">(<x-hijri-date :date="$plan->start_date" />)</span>
                                </span>
                            </label>
                        @empty
                            <p class="text-sm text-zinc-500">لا يوجد جداول دراسية لهذا الطالب.</p>
                        @endforelse
                    </div>
                </div>

                {{-- Unachieved days as cards (range selection) --}}
                @if(count($planDays) > 0)
                    <div>
                        <flux:label class="mb-1">
                            اختر الأيام المستهدفة
                            <span class="text-zinc-400 font-normal text-xs mr-1">(اضغط على يوم للبداية، ثم يوم
                                للنهاية)</span>
                        </flux:label>
                        <div class="max-h-64 overflow-y-auto space-y-1 p-2 bg-zinc-50 dark:bg-zinc-800/50 rounded-xl"
                            x-data="{ rangeStart: null }" @click.away="rangeStart = null">
                            @foreach($planDays as $idx => $day)
                                <button type="button" x-on:click="
                                                                if (rangeStart === null) {
                                                                    rangeStart = {{ $idx }};
                                                                } else {
                                                                    $wire.selectDayRange(rangeStart, {{ $idx }});
                                                                    rangeStart = null;
                                                                }
                                                            " class="w-full text-right px-3 py-2 rounded-lg text-sm border  "
                                    :class="$wire.selectedDayIds.includes({{ $day['id'] }})
                                                                ? 'bg-indigo-500 text-white border-indigo-500'
                                                                : 'bg-white dark:bg-zinc-800 border-zinc-200 dark:border-zinc-700 hover:border-indigo-300'">
                                    <span class="font-medium">{{ $day['day_name'] }}</span>
                                    <span class="text-xs opacity-75 mr-2">{{ $day['hijri'] }}</span>
                                </button>
                            @endforeach
                        </div>
                        <p class="text-xs text-zinc-500 mt-1">
                            تم تحديد <span class="font-bold text-indigo-600" x-text="$wire.selectedDayIds.length"></span>
                            يوم
                        </p>
                    </div>
                @elseif(count($planDays) === 0 && !empty($studentPlans))
                    <div x-show="formData.recitationPlanId !== ''"
                        class="text-sm text-zinc-400 italic p-3 bg-zinc-50 dark:bg-zinc-800/50 rounded-xl">
                        اختر جدولاً لعرض الأيام غير المنجزة
                    </div>
                @endif

                {{-- Quality option --}}
                <div
                    class="p-3 bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 space-y-3">
                    <flux:checkbox x-model="formData.recitationQualityEnabled"
                        label="اشتراط جودة معينة لاعتبار اليوم منجزاً" />
                    <div x-show="formData.recitationQualityEnabled" class="ps-6">
                        <flux:select x-model="formData.recitationQualityRequirement" label="التقدير المطلوب">
                            <flux:select.option value="excellent">ممتاز فقط</flux:select.option>
                            <flux:select.option value="good_or_better">ممتاز وجيد</flux:select.option>
                        </flux:select>
                    </div>
                </div>
            </div>

            {{-- Exam passing --}}
            <div x-show="formData.rewardType === 'exam'" class="space-y-4">
                @if(count($candidateExams) > 0)
                    <div
                        class="p-4 bg-indigo-50 dark:bg-indigo-500/10 rounded-xl border border-indigo-100 dark:border-indigo-500/20">
                        <flux:heading size="sm" class="mb-2 text-indigo-900 dark:text-indigo-300">الاختبار المستهدف (بناءً
                            على مسار الطالب):</flux:heading>
                        @if(count($candidateExams) === 1)
                            <div class="font-bold text-lg text-indigo-700 dark:text-indigo-400">
                                {{ $candidateExams[0]['name'] }}
                            </div>
                        @else
                            <flux:select x-model="formData.examLevelId">
                                @foreach($candidateExams as $exam)
                                    <flux:select.option value="{{ $exam['id'] }}">{{ $exam['name'] }}</flux:select.option>
                                @endforeach
                            </flux:select>
                        @endif
                    </div>
                @else
                    <div
                        class="p-4 bg-amber-50 dark:bg-amber-500/10 rounded-xl border border-amber-100 dark:border-amber-500/20 text-amber-800 dark:text-amber-400 text-sm font-medium">
                        لقد أتم الطالب جميع الاختبارات المتاحة أو لا توجد اختبارات مسجلة!
                    </div>
                @endif

                <flux:field>
                    <flux:label>النسبة المطلوبة لتجاوز الاختبار</flux:label>
                    <div class="flex items-center gap-2 mt-1">
                        <flux:input type="number" min="1" max="100" x-model="formData.examPercentage"
                            placeholder="مثال: 90" class="flex-1" />
                        <span class="text-sm font-semibold text-zinc-600 dark:text-zinc-400 whitespace-nowrap">%</span>
                    </div>
                </flux:field>
            </div>
        </div>

        {{-- STEP 4: Prize --}}
        <div x-show="step === 4" style="display: none;" x-transition:enter="ease-out duration-300"
            x-transition:enter-start="opacity-0 translate-y-4" x-transition:enter-end="opacity-100 translate-y-0"
            class="space-y-6 bg-white dark:bg-zinc-900 p-6 rounded-2xl border border-zinc-200 dark:border-zinc-800 shadow-sm">

            <flux:heading size="lg">تحديد المكافأة</flux:heading>

            {{-- Eye-catching tip --}}
            <div
                class="relative overflow-hidden rounded-2xl p-4 bg-gradient-to-br from-amber-400 to-orange-500 text-white shadow-lg">
                <div class="absolute -top-4 -left-4 size-20 rounded-full bg-white/10"></div>
                <div class="absolute -bottom-6 -right-2 size-24 rounded-full bg-white/10"></div>
                <div class="relative flex items-start gap-3">
                    <div class="text-3xl shrink-0">🏆</div>
                    <div>
                        <p class="font-bold text-base">سر الاستمرار في الإنجاز</p>
                        <p class="text-sm text-white/90 mt-0.5">
                            المكافأة البسيطة والقابلة للتحقق تُشعل الحماس أكثر من المكافأة الكبيرة البعيدة. اجعلها محددة
                            وواضحة!
                        </p>
                    </div>
                </div>
            </div>

            <flux:radio.group x-model="formData.prizeType" variant="cards" class="grid grid-cols-2 gap-4">
                <flux:radio value="financial" label="مكافأة مالية" icon="banknotes" />
                <flux:radio value="material" label="مكافأة عينية" icon="gift" />
            </flux:radio.group>

            {{-- Financial: number input in SAR --}}
            <div x-show="formData.prizeType === 'financial'">
                <flux:field>
                    <flux:label>قيمة المكافأة</flux:label>
                    <div class="flex items-center gap-2 mt-1">
                        <flux:input type="number" min="1" x-model="formData.prizeAmount" placeholder="مثال: 50"
                            class="flex-1" />
                        <span class="text-sm font-semibold text-zinc-600 dark:text-zinc-400 whitespace-nowrap">ريال
                            سعودي</span>
                    </div>
                </flux:field>
            </div>

            {{-- Material: text description --}}
            <div x-show="formData.prizeType === 'material'">
                <flux:textarea x-model="formData.prizeDescription" label="وصف المكافأة"
                    placeholder="مثال: رحلة للمسبح، لعبة إلكترونية..." rows="3" />
            </div>
        </div>

        {{-- Navigation --}}
        <div class="flex justify-between items-center pt-2">
            <flux:button x-show="step > 1" @click="step--" variant="ghost" icon="chevron-right">
                السابق
            </flux:button>
            <div class="mr-auto">
                <flux:button x-show="step < 4" @click="nextStep" variant="primary" icon-trailing="chevron-left"
                    ::disabled="!canProceed()">
                    التالي
                </flux:button>
                <flux:button x-show="step === 4" @click="submit" variant="primary" icon="check"
                    class="bg-green-600 hover:bg-green-700 text-white" ::disabled="isSubmitting || !canProceed()">
                    <span x-show="!isSubmitting">اعتماد المكافأة</span>
                    <span x-show="isSubmitting">جاري الحفظ...</span>
                </flux:button>
            </div>
        </div>

    </div>

    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('challengeWizard', () => ({
                step: 1,
                isSubmitting: false,
                formData: {
                    rewardType: 'recitation', // Default value

                    // Attendance
                    attendanceDays: 7,
                    attendanceNoLateness: false,

                    // Recitation
                    recitationPlanId: '',
                    recitationQualityEnabled: false,
                    recitationQualityRequirement: 'excellent',

                    // Exam
                    examLevelId: '{{ count($candidateExams) > 0 ? $candidateExams[0]['id'] : '' }}',
                    examPercentage: 90,

                    // Prize
                    prizeType: 'material',
                    prizeAmount: '',
                    prizeDescription: '',
                },

                nextStep() {
                    if (this.canProceed()) this.step++;
                },

                canProceed() {
                    if (this.step === 2) {
                        if (!this.formData.rewardType) return false;
                    }
                    if (this.step === 3) {
                        if (this.formData.rewardType === 'attendance' && !this.formData.attendanceDays) return false;
                        if (this.formData.rewardType === 'recitation' && !this.formData.recitationPlanId) return false;
                        if (this.formData.rewardType === 'recitation' && this.$wire.selectedDayIds.length === 0) return false;
                        if (this.formData.rewardType === 'exam') {
                            if (!this.formData.examLevelId) return false;
                            if (!this.formData.examPercentage) return false;
                        }
                    }
                    if (this.step === 4) {
                        if (this.formData.prizeType === 'financial' && !this.formData.prizeAmount) return false;
                        if (this.formData.prizeType === 'material' && !this.formData.prizeDescription.trim()) return false;
                    }
                    return true;
                },

                submit() {
                    if (!this.canProceed()) return;
                    this.isSubmitting = true;
                    this.$wire.saveChallenge(this.formData);
                }
            }))
        })
    </script>
</div>