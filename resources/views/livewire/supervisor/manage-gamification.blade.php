<div class="space-y-6">
    {{-- Back navigation and theme banner --}}
    <div class="relative overflow-hidden rounded-2xl border border-white/10 p-6 shadow-sm transition-all duration-300 text-white"
        style="background: linear-gradient(to right, {{ $theme['color'] }}, #1e293b);">
        <div class="absolute -right-10 -top-10 size-40 rounded-full opacity-10 blur-3xl bg-white"></div>
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 relative z-10">
            <div class="space-y-2">
                <a href="{{ route('supervisor.competitions') }}" class="inline-flex items-center gap-1 text-sm text-zinc-300 hover:text-white transition-colors" wire:navigate>
                    <flux:icon icon="arrow-right" class="size-4" />
                    <span>العودة للمسابقات</span>
                </a>
                <div class="flex items-center gap-3">
                    <div class="p-2 rounded-xl bg-white/10 backdrop-blur-md">
                        @if(str_contains($theme['team_emoji'], '/') || str_ends_with($theme['team_emoji'], '.webp'))
                            <img src="{{ Storage::url($theme['team_emoji']) }}" class="size-6 object-contain" />
                        @else
                            <span class="text-2xl leading-none">{{ $theme['team_emoji'] }}</span>
                        @endif
                    </div>
                    <div>
                        <flux:heading size="xl" class="font-bold text-white">{{ $competition->title }}</flux:heading>
                        <flux:subheading class="text-zinc-300">
                            طابع المسابقة: {{ $theme['name'] }} | عملة المتجر: {{ $theme['currency_name'] }}
                        </flux:subheading>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Tabs Menu --}}
    <div class="flex border-b border-zinc-200 dark:border-zinc-700 overflow-x-auto gap-2">
        <button wire:click="$set('activeTab', 'levels')" class="py-2.5 px-4 font-medium text-sm border-b-2 transition-all shrink-0 {{ $activeTab === 'levels' ? 'border-purple-600 text-purple-600 dark:text-purple-400' : 'border-transparent text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300' }}">
            المستويات
        </button>
        <button wire:click="$set('activeTab', 'criteria')" class="py-2.5 px-4 font-medium text-sm border-b-2 transition-all shrink-0 {{ $activeTab === 'criteria' ? 'border-purple-600 text-purple-600 dark:text-purple-400' : 'border-transparent text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300' }}">
            بنود التقييم
        </button>
        <button wire:click="$set('activeTab', 'badges')" class="py-2.5 px-4 font-medium text-sm border-b-2 transition-all shrink-0 {{ $activeTab === 'badges' ? 'border-purple-600 text-purple-600 dark:text-purple-400' : 'border-transparent text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300' }}">
            الأوسمة
        </button>
        <button wire:click="$set('activeTab', 'streaks')" class="py-2.5 px-4 font-medium text-sm border-b-2 transition-all shrink-0 {{ $activeTab === 'streaks' ? 'border-purple-600 text-purple-600 dark:text-purple-400' : 'border-transparent text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300' }}">
             الحماسة
        </button>
        <button wire:click="$set('activeTab', 'teams')" class="py-2.5 px-4 font-medium text-sm border-b-2 transition-all shrink-0 {{ $activeTab === 'teams' ? 'border-purple-600 text-purple-600 dark:text-purple-400' : 'border-transparent text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300' }}">
           المجموعات
        </button>
        <button wire:click="$set('activeTab', 'tracks')" class="py-2.5 px-4 font-medium text-sm border-b-2 transition-all shrink-0 {{ $activeTab === 'tracks' ? 'border-purple-600 text-purple-600 dark:text-purple-400' : 'border-transparent text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300' }}">
           المسارات
        </button>
        <button wire:click="$set('activeTab', 'standings')" class="py-2.5 px-4 font-medium text-sm border-b-2 transition-all shrink-0 {{ $activeTab === 'standings' ? 'border-purple-600 text-purple-600 dark:text-purple-400' : 'border-transparent text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300' }}">
           مراكز الطلاب
        </button>
        <button wire:click="$set('activeTab', 'store')" class="py-2.5 px-4 font-medium text-sm border-b-2 transition-all shrink-0 {{ $activeTab === 'store' ? 'border-purple-600 text-purple-600 dark:text-purple-400' : 'border-transparent text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300' }}">
            المتجر
        </button>
        <button wire:click="$set('activeTab', 'redemption')" class="py-2.5 px-4 font-medium text-sm border-b-2 transition-all shrink-0 {{ $activeTab === 'redemption' ? 'border-purple-600 text-purple-600 dark:text-purple-400' : 'border-transparent text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300' }}">
            روابط الصرف
        </button>
        <flux:button wire:click="$set('activeTab', 'team_tasks')" variant="ghost" class="py-2.5 px-4 font-medium text-sm border-b-2 transition-all shrink-0 rounded-none border-b-2 {{ $activeTab === 'team_tasks' ? 'border-purple-600 text-purple-600 dark:text-purple-400' : 'border-transparent text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300' }}">
            مهام المجموعات
        </flux:button>
        <flux:button wire:click="$set('activeTab', 'activities')" variant="ghost" class="py-2.5 px-4 font-medium text-sm border-b-2 transition-all shrink-0 rounded-none border-b-2 {{ $activeTab === 'activities' ? 'border-purple-600 text-purple-600 dark:text-purple-400' : 'border-transparent text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300' }}">
            الفعاليات والأنشطة
        </flux:button>
        <flux:button wire:click="$set('activeTab', 'adjustments')" variant="ghost" class="py-2.5 px-4 font-medium text-sm border-b-2 transition-all shrink-0 rounded-none border-b-2 {{ $activeTab === 'adjustments' ? 'border-purple-600 text-purple-600 dark:text-purple-400' : 'border-transparent text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300' }}">
            التسويات
        </flux:button>
    </div>

    {{-- Tab Content --}}
    <div class="bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-2xl p-6 shadow-sm">
        
        {{-- TAB: LEVELS --}}
        @if($activeTab === 'levels')
            <div class="space-y-6">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                    <div>
                        <flux:heading size="lg">مستويات التلعيب للطلاب</flux:heading>
                        <flux:subheading>حدد أسماء المستويات المتدرجة ونقاط طاقة الخبرة (XP) المطلوبة للوصول لكل مستوى.</flux:subheading>
                    </div>
                    <flux:button wire:click="addLevel" variant="ghost" icon="plus" class="text-purple-600 bg-purple-50 hover:bg-purple-100 dark:text-purple-400 dark:bg-purple-900/30">
                        إضافة مستوى جديد
                    </flux:button>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    @foreach($levels as $index => $level)
                        <div class="p-5 rounded-2xl border border-zinc-200 dark:border-zinc-800 space-y-4 bg-zinc-50/50 dark:bg-zinc-800/20 relative group shadow-sm">
                            <div class="flex items-center justify-between">
                                <flux:badge size="sm" color="purple">المستوى {{ $level['level_number'] }}</flux:badge>
                                <div class="flex items-center gap-2">
                                    <flux:icon :icon="$level['icon'] ?? 'sparkles'" class="size-6 text-purple-500" />
                                    @if(count($levels) > 1)
                                        <button type="button" wire:click="removeLevel({{ $index }})" class="text-zinc-400 hover:text-rose-500 dark:hover:text-rose-400 transition-colors" title="حذف هذا المستوى">
                                            <flux:icon icon="trash" class="size-4" />
                                        </button>
                                    @endif
                                </div>
                            </div>
                            <div class="grid grid-cols-2 gap-3">
                                <flux:input wire:model="levels.{{ $index }}.name" label="اسم المستوى" placeholder="مثال: نجم الفضاء" />
                                <flux:input type="number" wire:model="levels.{{ $index }}.xp_required" label="طاقة الخبرة (XP) المطلوبة" placeholder="نقاط" />
                            </div>

                            <div class="mt-3">
                                <flux:input type="number" min="0" wire:model="levels.{{ $index }}.settings.reward_coins" label="مكافأة العملات عند بلوغ هذا المستوى" placeholder="0 = بدون مكافأة" />
                                <flux:text size="sm" class="mt-1 text-zinc-400">تُمنح مرة واحدة، وتظهر للطالب في قائمة المكافآت بانتظار الاستلام.</flux:text>
                            </div>

                            <div class="border-t border-zinc-200/60 dark:border-zinc-800/80 my-2"></div>
                            
                            <div class="space-y-3">
                                <span class="text-xs font-bold text-zinc-500 dark:text-zinc-400 uppercase tracking-wider block">خصائص وميزات المستوى:</span>
                                
                                <!-- Individual Multiplier -->
                                <div class="p-3 rounded-xl bg-white dark:bg-zinc-900 border border-zinc-150 dark:border-zinc-800/60 space-y-2.5">
                                    <div class="flex items-center justify-between">
                                        <div class="flex items-center gap-2">
                                            <svg class="size-4 text-purple-600 dark:text-purple-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                                            </svg>
                                            <span class="text-xs font-bold text-zinc-700 dark:text-zinc-300">مضاعف نقاط فردي (2x)</span>
                                        </div>
                                        <flux:switch wire:model="levels.{{ $index }}.settings.has_individual_multiplier" size="sm" />
                                    </div>
                                    @if($level['settings']['has_individual_multiplier'] ?? true)
                                        <div class="pt-1">
                                            <flux:input type="number" wire:model="levels.{{ $index }}.settings.individual_multiplier_price" label="سعر الشراء بالعملات" size="sm" />
                                        </div>
                                    @endif
                                </div>

                                <!-- Team Multiplier -->
                                <div class="p-3 rounded-xl bg-white dark:bg-zinc-900 border border-zinc-150 dark:border-zinc-800/60 space-y-2.5">
                                    <div class="flex items-center justify-between">
                                        <div class="flex items-center gap-2">
                                            <svg class="size-4 text-purple-600 dark:text-purple-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 10V5l-5 7h4v5l5-7h-4z" opacity="0.75"></path>
                                            </svg>
                                            <span class="text-xs font-bold text-zinc-700 dark:text-zinc-300">مضاعف نقاط جماعي (2x)</span>
                                        </div>
                                        <flux:switch wire:model="levels.{{ $index }}.settings.has_team_multiplier" size="sm" />
                                    </div>
                                    @if($level['settings']['has_team_multiplier'] ?? true)
                                        <div class="pt-1">
                                            <flux:input type="number" wire:model="levels.{{ $index }}.settings.team_multiplier_price" label="سعر الشراء بالعملات" size="sm" />
                                        </div>
                                    @endif
                                </div>

                                <!-- Enthusiasm Freeze -->
                                <div class="p-3 rounded-xl bg-white dark:bg-zinc-900 border border-zinc-150 dark:border-zinc-800/60 space-y-2.5">
                                    <div class="flex items-center justify-between">
                                        <div class="flex items-center gap-2">
                                            <svg class="size-4 text-purple-600 dark:text-purple-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v18M3 12h18m-3-6L6 18M6 6l12 12"></path>
                                            </svg>
                                            <span class="text-xs font-bold text-zinc-700 dark:text-zinc-300">تجميد أيام الحماسة</span>
                                        </div>
                                        <flux:switch wire:model="levels.{{ $index }}.settings.has_freeze" size="sm" />
                                    </div>
                                    @if($level['settings']['has_freeze'] ?? true)
                                        <div class="grid grid-cols-2 gap-2 pt-1">
                                            <flux:input type="number" wire:model="levels.{{ $index }}.settings.freeze_price" label="سعر التجميد" size="sm" />
                                            <flux:input type="number" wire:model="levels.{{ $index }}.settings.freeze_max_days" label="أيام التجميد للخلف" size="sm" />
                                        </div>
                                    @endif
                                </div>

                                <!-- Team Donation -->
                                <div class="p-3 rounded-xl bg-white dark:bg-zinc-900 border border-zinc-150 dark:border-zinc-800/60 space-y-2.5">
                                    <div class="flex items-center justify-between">
                                        <div class="flex items-center gap-2">
                                            <svg class="size-4 text-purple-600 dark:text-purple-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                            </svg>
                                            <span class="text-xs font-bold text-zinc-700 dark:text-zinc-300">التبرع للمجموعة</span>
                                        </div>
                                        <flux:switch wire:model="levels.{{ $index }}.settings.has_donation" size="sm" />
                                    </div>
                                    @if($level['settings']['has_donation'] ?? true)
                                        <div class="pt-1">
                                            <flux:input type="number" wire:model="levels.{{ $index }}.settings.donation_max_limit" label="الحد اليومي (% من رصيد عملات الطالب في بداية اليوم)" size="sm" />
                                        </div>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>

                <div class="flex justify-end pt-4">
                    <flux:button wire:click="saveLevels" variant="primary" class="bg-purple-600 hover:bg-purple-700 border-none text-white">حفظ المستويات</flux:button>
                </div>
            </div>
        @endif

        {{-- TAB: CRITERIA --}}
        @if($activeTab === 'criteria')
            <div class="space-y-6">
                {{-- Manual reward claim toggle --}}
                <div class="flex items-center justify-between gap-4 p-5 rounded-2xl border border-amber-200 dark:border-amber-900/50 bg-amber-50/40 dark:bg-amber-950/10">
                    <div class="flex items-start gap-3">
                        <div class="p-1.5 rounded-lg bg-amber-100 dark:bg-amber-900/30 text-amber-600 dark:text-amber-400 shrink-0">
                            <flux:icon icon="gift" class="size-5" />
                        </div>
                        <div>
                            <span class="font-bold text-zinc-900 dark:text-white">استلام المكافآت يدوياً</span>
                            <p class="text-sm text-zinc-500 dark:text-zinc-400 mt-0.5">عند التفعيل لا تُحتسب نقاط الطالب وعملاته حتى يستلمها بنفسه من لوحته.</p>
                        </div>
                    </div>
                    <flux:switch wire:model="manual_claim_enabled" />
                </div>

                {{-- Automatic Evaluation Items Section --}}
                <div class="space-y-4 border-b border-zinc-200 dark:border-zinc-800 pb-8">
                    <div>
                        <flux:heading size="lg">بنود التقييم التلقائية</flux:heading>
                        <flux:subheading>حدد إعدادات الحفظ والمراجعة والحضور: نقاط طاقة الخبرة (XP)، والعملات المقابلة، وتأثيرها على شعلة الحماسة.</flux:subheading>
                    </div>

                    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                        <!-- Hifz (Memorization) -->
                        <div class="p-5 rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-zinc-50/50 dark:bg-zinc-800/20 space-y-4">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-2">
                                    <div class="p-1.5 rounded-lg bg-purple-50 dark:bg-purple-900/30 text-purple-600 dark:text-purple-400">
                                        <flux:icon icon="book-open" class="size-5" />
                                    </div>
                                    <span class="font-bold text-zinc-900 dark:text-white">نقاط الحفظ</span>
                                </div>
                                <flux:switch wire:model.live="hifz_enabled" />
                            </div>

                            @if($hifz_enabled)
                                <div class="space-y-3 pt-2 border-t border-zinc-200/60 dark:border-zinc-800/80">
                                    <div class="grid grid-cols-2 gap-2 text-xs font-semibold text-zinc-500 dark:text-zinc-400">
                                        <span>طاقة الخبرة (XP)</span>
                                        <span>العملات ({{ $theme['currency_name'] }})</span>
                                    </div>
                                    
                                    <div class="space-y-2">
                                        <div class="grid grid-cols-2 gap-2 items-center">
                                            <flux:input type="number" wire:model="hifz_excellent_xp" label="ممتاز (XP)" size="sm" />
                                            <flux:input type="number" wire:model="hifz_excellent_coins" label="ممتاز (عملة)" size="sm" />
                                        </div>
                                        <div class="grid grid-cols-2 gap-2 items-center">
                                            <flux:input type="number" wire:model="hifz_good_xp" label="جيد (XP)" size="sm" />
                                            <flux:input type="number" wire:model="hifz_good_coins" label="جيد (عملة)" size="sm" />
                                        </div>
                                        <div class="grid grid-cols-2 gap-2 items-center">
                                            <flux:input type="number" wire:model="hifz_acceptable_xp" label="مقبول (XP)" size="sm" />
                                            <flux:input type="number" wire:model="hifz_acceptable_coins" label="مقبول (عملة)" size="sm" />
                                        </div>
                                    </div>

                                    <div class="pt-2 border-t border-zinc-200/40 dark:border-zinc-800/40">
                                        <flux:switch wire:model="hifz_enthusiasm_trigger" label="مغذي لشعلة الحماسة 🔥" />
                                    </div>
                                </div>
                            @endif
                        </div>

                        <!-- Review (Quran Review) -->
                        <div class="p-5 rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-zinc-50/50 dark:bg-zinc-800/20 space-y-4">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-2">
                                    <div class="p-1.5 rounded-lg bg-indigo-50 dark:bg-indigo-900/30 text-indigo-600 dark:text-indigo-400">
                                        <flux:icon icon="arrow-path" class="size-5" />
                                    </div>
                                    <span class="font-bold text-zinc-900 dark:text-white">نقاط المراجعة</span>
                                </div>
                                <flux:switch wire:model.live="review_enabled" />
                            </div>

                            @if($review_enabled)
                                <div class="space-y-3 pt-2 border-t border-zinc-200/60 dark:border-zinc-800/80">
                                    <div class="grid grid-cols-2 gap-2 text-xs font-semibold text-zinc-500 dark:text-zinc-400">
                                        <span>طاقة الخبرة (XP)</span>
                                        <span>العملات ({{ $theme['currency_name'] }})</span>
                                    </div>
                                    
                                    <div class="space-y-2">
                                        <div class="grid grid-cols-2 gap-2 items-center">
                                            <flux:input type="number" wire:model="review_excellent_xp" label="ممتاز (XP)" size="sm" />
                                            <flux:input type="number" wire:model="review_excellent_coins" label="ممتاز (عملة)" size="sm" />
                                        </div>
                                        <div class="grid grid-cols-2 gap-2 items-center">
                                            <flux:input type="number" wire:model="review_good_xp" label="جيد ومقبول (XP)" size="sm" />
                                            <flux:input type="number" wire:model="review_good_coins" label="جيد ومقبول (عملة)" size="sm" />
                                        </div>
                                    </div>

                                    <div class="pt-2 border-t border-zinc-200/40 dark:border-zinc-800/40">
                                        <flux:switch wire:model="review_enthusiasm_trigger" label="مغذي لشعلة الحماسة 🔥" />
                                    </div>
                                </div>
                            @endif
                        </div>

                        <!-- Attendance -->
                        <div class="p-5 rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-zinc-50/50 dark:bg-zinc-800/20 space-y-4">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-2">
                                    <div class="p-1.5 rounded-lg bg-emerald-50 dark:bg-emerald-900/30 text-emerald-600 dark:text-emerald-400">
                                        <flux:icon icon="user-group" class="size-5" />
                                    </div>
                                    <span class="font-bold text-zinc-900 dark:text-white">نقاط الحضور</span>
                                </div>
                                <flux:switch wire:model.live="attendance_enabled" />
                            </div>

                            @if($attendance_enabled)
                                <div class="space-y-3 pt-2 border-t border-zinc-200/60 dark:border-zinc-800/80">
                                    <div class="grid grid-cols-2 gap-2 text-xs font-semibold text-zinc-500 dark:text-zinc-400">
                                        <span>طاقة الخبرة (XP)</span>
                                        <span>العملات ({{ $theme['currency_name'] }})</span>
                                    </div>

                                    <div class="space-y-2">
                                        <div class="grid grid-cols-2 gap-2 items-center">
                                            <flux:input type="number" wire:model="attendance_present_xp" label="حاضر بوقت (XP)" size="sm" />
                                            <flux:input type="number" wire:model="attendance_present_coins" label="حاضر بوقت (عملة)" size="sm" />
                                        </div>
                                        <div class="grid grid-cols-2 gap-2 items-center">
                                            <flux:input type="number" wire:model="attendance_late_xp" label="متأخر (XP)" size="sm" />
                                            <flux:input type="number" wire:model="attendance_late_coins" label="متأخر (عملة)" size="sm" />
                                        </div>
                                    </div>

                                    <div class="pt-2 border-t border-zinc-200/40 dark:border-zinc-800/40">
                                        <flux:switch wire:model="attendance_enthusiasm_trigger" label="مغذي لشعلة الحماسة 🔥" />
                                    </div>
                                </div>
                            @endif
                        </div>
                    </div>

                    {{-- Ode & Hadith automatic settings --}}
                    <div class="grid grid-cols-1 lg:grid-cols-2 xl:grid-cols-4 gap-6 mt-6">
                        <!-- Ode Hifz -->
                        <div class="p-5 rounded-2xl border border-violet-200 dark:border-violet-900/50 bg-violet-50/30 dark:bg-violet-950/10 space-y-4">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-2">
                                    <div class="p-1.5 rounded-lg bg-violet-50 dark:bg-violet-900/30 text-violet-600 dark:text-violet-400">
                                        <flux:icon icon="musical-note" class="size-5" />
                                    </div>
                                    <span class="font-bold text-zinc-900 dark:text-white">حفظ المنظومة</span>
                                </div>
                                <flux:switch wire:model.live="ode_hifz_enabled" />
                            </div>

                            @if($ode_hifz_enabled)
                                <div class="space-y-3 pt-2 border-t border-violet-200/60 dark:border-violet-800/80">
                                    <div class="grid grid-cols-2 gap-2 text-xs font-semibold text-zinc-500 dark:text-zinc-400">
                                        <span>طاقة الخبرة (XP)</span>
                                        <span>العملات ({{ $theme['currency_name'] }})</span>
                                    </div>
                                    <div class="space-y-2">
                                        <div class="grid grid-cols-2 gap-2 items-center">
                                            <flux:input type="number" wire:model="ode_hifz_excellent_xp" label="ممتاز (XP)" size="sm" />
                                            <flux:input type="number" wire:model="ode_hifz_excellent_coins" label="ممتاز (عملة)" size="sm" />
                                        </div>
                                        <div class="grid grid-cols-2 gap-2 items-center">
                                            <flux:input type="number" wire:model="ode_hifz_good_xp" label="جيد (XP)" size="sm" />
                                            <flux:input type="number" wire:model="ode_hifz_good_coins" label="جيد (عملة)" size="sm" />
                                        </div>
                                        <div class="grid grid-cols-2 gap-2 items-center">
                                            <flux:input type="number" wire:model="ode_hifz_acceptable_xp" label="مقبول (XP)" size="sm" />
                                            <flux:input type="number" wire:model="ode_hifz_acceptable_coins" label="مقبول (عملة)" size="sm" />
                                        </div>
                                    </div>
                                    <div class="pt-2 border-t border-violet-200/40 dark:border-violet-800/40">
                                        <flux:switch wire:model="ode_hifz_enthusiasm_trigger" label="مغذي لشعلة الحماسة 🔥" />
                                    </div>
                                </div>
                            @endif
                        </div>

                        <!-- Ode Review -->
                        <div class="p-5 rounded-2xl border border-violet-200 dark:border-violet-900/50 bg-violet-50/30 dark:bg-violet-950/10 space-y-4">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-2">
                                    <div class="p-1.5 rounded-lg bg-violet-50 dark:bg-violet-900/30 text-violet-600 dark:text-violet-400">
                                        <flux:icon icon="arrow-path" class="size-5" />
                                    </div>
                                    <span class="font-bold text-zinc-900 dark:text-white">مراجعة المنظومة</span>
                                </div>
                                <flux:switch wire:model.live="ode_review_enabled" />
                            </div>

                            @if($ode_review_enabled)
                                <div class="space-y-3 pt-2 border-t border-violet-200/60 dark:border-violet-800/80">
                                    <div class="grid grid-cols-2 gap-2 text-xs font-semibold text-zinc-500 dark:text-zinc-400">
                                        <span>طاقة الخبرة (XP)</span>
                                        <span>العملات ({{ $theme['currency_name'] }})</span>
                                    </div>
                                    <div class="space-y-2">
                                        <div class="grid grid-cols-2 gap-2 items-center">
                                            <flux:input type="number" wire:model="ode_review_excellent_xp" label="ممتاز (XP)" size="sm" />
                                            <flux:input type="number" wire:model="ode_review_excellent_coins" label="ممتاز (عملة)" size="sm" />
                                        </div>
                                        <div class="grid grid-cols-2 gap-2 items-center">
                                            <flux:input type="number" wire:model="ode_review_good_xp" label="جيد ومقبول (XP)" size="sm" />
                                            <flux:input type="number" wire:model="ode_review_good_coins" label="جيد ومقبول (عملة)" size="sm" />
                                        </div>
                                    </div>
                                    <div class="pt-2 border-t border-violet-200/40 dark:border-violet-800/40">
                                        <flux:switch wire:model="ode_review_enthusiasm_trigger" label="مغذي لشعلة الحماسة 🔥" />
                                    </div>
                                </div>
                            @endif
                        </div>

                        <!-- Hadith Hifz -->
                        <div class="p-5 rounded-2xl border border-rose-200 dark:border-rose-900/50 bg-rose-50/30 dark:bg-rose-950/10 space-y-4">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-2">
                                    <div class="p-1.5 rounded-lg bg-rose-50 dark:bg-rose-900/30 text-rose-600 dark:text-rose-400">
                                        <flux:icon icon="document-text" class="size-5" />
                                    </div>
                                    <span class="font-bold text-zinc-900 dark:text-white">حفظ الحديث</span>
                                </div>
                                <flux:switch wire:model.live="hadith_hifz_enabled" />
                            </div>

                            @if($hadith_hifz_enabled)
                                <div class="space-y-3 pt-2 border-t border-rose-200/60 dark:border-rose-800/80">
                                    <div class="grid grid-cols-2 gap-2 text-xs font-semibold text-zinc-500 dark:text-zinc-400">
                                        <span>طاقة الخبرة (XP)</span>
                                        <span>العملات ({{ $theme['currency_name'] }})</span>
                                    </div>
                                    <div class="space-y-2">
                                        <div class="grid grid-cols-2 gap-2 items-center">
                                            <flux:input type="number" wire:model="hadith_hifz_excellent_xp" label="ممتاز (XP)" size="sm" />
                                            <flux:input type="number" wire:model="hadith_hifz_excellent_coins" label="ممتاز (عملة)" size="sm" />
                                        </div>
                                        <div class="grid grid-cols-2 gap-2 items-center">
                                            <flux:input type="number" wire:model="hadith_hifz_good_xp" label="جيد (XP)" size="sm" />
                                            <flux:input type="number" wire:model="hadith_hifz_good_coins" label="جيد (عملة)" size="sm" />
                                        </div>
                                        <div class="grid grid-cols-2 gap-2 items-center">
                                            <flux:input type="number" wire:model="hadith_hifz_acceptable_xp" label="مقبول (XP)" size="sm" />
                                            <flux:input type="number" wire:model="hadith_hifz_acceptable_coins" label="مقبول (عملة)" size="sm" />
                                        </div>
                                    </div>
                                    <div class="pt-2 border-t border-rose-200/40 dark:border-rose-800/40">
                                        <flux:switch wire:model="hadith_hifz_enthusiasm_trigger" label="مغذي لشعلة الحماسة 🔥" />
                                    </div>
                                </div>
                            @endif
                        </div>

                        <!-- Hadith Review -->
                        <div class="p-5 rounded-2xl border border-rose-200 dark:border-rose-900/50 bg-rose-50/30 dark:bg-rose-950/10 space-y-4">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-2">
                                    <div class="p-1.5 rounded-lg bg-rose-50 dark:bg-rose-900/30 text-rose-600 dark:text-rose-400">
                                        <flux:icon icon="arrow-path" class="size-5" />
                                    </div>
                                    <span class="font-bold text-zinc-900 dark:text-white">مراجعة الحديث</span>
                                </div>
                                <flux:switch wire:model.live="hadith_review_enabled" />
                            </div>

                            @if($hadith_review_enabled)
                                <div class="space-y-3 pt-2 border-t border-rose-200/60 dark:border-rose-800/80">
                                    <div class="grid grid-cols-2 gap-2 text-xs font-semibold text-zinc-500 dark:text-zinc-400">
                                        <span>طاقة الخبرة (XP)</span>
                                        <span>العملات ({{ $theme['currency_name'] }})</span>
                                    </div>
                                    <div class="space-y-2">
                                        <div class="grid grid-cols-2 gap-2 items-center">
                                            <flux:input type="number" wire:model="hadith_review_excellent_xp" label="ممتاز (XP)" size="sm" />
                                            <flux:input type="number" wire:model="hadith_review_excellent_coins" label="ممتاز (عملة)" size="sm" />
                                        </div>
                                        <div class="grid grid-cols-2 gap-2 items-center">
                                            <flux:input type="number" wire:model="hadith_review_good_xp" label="جيد ومقبول (XP)" size="sm" />
                                            <flux:input type="number" wire:model="hadith_review_good_coins" label="جيد ومقبول (عملة)" size="sm" />
                                        </div>
                                    </div>
                                    <div class="pt-2 border-t border-rose-200/40 dark:border-rose-800/40">
                                        <flux:switch wire:model="hadith_review_enthusiasm_trigger" label="مغذي لشعلة الحماسة 🔥" />
                                    </div>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>

                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pt-4">
                    <div>
                        <flux:heading size="lg">بنود التقييم اليدوية والمخصصة</flux:heading>
                        <flux:subheading>أضف بنود التقييم المخصصة للمسابقة، وحدد نقاط الخبرة والعملات الممنوحة بالإضافة إلى كونها مغذية لشعلة الحماسة.</flux:subheading>
                    </div>
                    <flux:button wire:click="addTodoCriterion" variant="ghost" icon="plus" class="text-purple-600 bg-purple-50 hover:bg-purple-100 dark:text-purple-400 dark:bg-purple-900/30">
                        إضافة بند تقييم
                    </flux:button>
                </div>

                <div class="space-y-4">
                    @foreach($criteria as $index => $c)
                        <div class="p-4 rounded-xl border border-zinc-200 dark:border-zinc-800 bg-zinc-50/50 dark:bg-zinc-800/20 flex flex-col md:flex-row md:items-center justify-between gap-4" wire:key="criterion-{{ $index }}">
                            <div class="flex flex-col md:flex-row md:items-center gap-4 flex-1">
                                <div class="flex-2 min-w-[200px]">
                                    <flux:input wire:model="criteria.{{ $index }}.name" label="اسم البند" placeholder="مثال: الانضباط والهدوء، الحضور المبكر" required />
                                </div>
                                <div class="w-full md:w-32">
                                    <flux:input type="number" wire:model="criteria.{{ $index }}.points" label="النقاط الافتراضية" placeholder="مثال: 5" required />
                                </div>
                                <div class="w-full md:w-32">
                                    <flux:input type="number" wire:model="criteria.{{ $index }}.coins" label="العملات الافتراضية" placeholder="مثال: 5" required />
                                </div>
                                <div class="flex items-center pt-2 md:pt-6">
                                    <flux:switch wire:model="criteria.{{ $index }}.is_enthusiasm_trigger" label="مغذي لشعلة الحماسة" />
                                </div>
                            </div>
                            <div class="flex items-center justify-end pt-2 md:pt-6">
                                <flux:button wire:click="removeTodoCriterion({{ $index }})" wire:confirm="هل أنت متأكد من حذف هذا البند؟" variant="ghost" icon="trash" class="text-rose-500 hover:text-rose-600" title="حذف هذا البند" />
                            </div>
                        </div>
                    @endforeach

                    @if(count($criteria) === 0)
                        <div class="text-center py-12 text-zinc-500 border border-dashed border-zinc-200 dark:border-zinc-800 rounded-xl">
                            لا توجد بنود تقييم مخصصة حالياً. أضف أول بند الآن!
                        </div>
                    @endif
                </div>

                {{--
                    Points are written when a teacher grades, against the criteria
                    enabled at that moment — so a criterion switched on here leaves
                    every earlier grading unscored until this replays them.
                --}}
                <div class="mt-6 p-4 rounded-xl bg-amber-50 dark:bg-amber-950/30 border border-amber-100 dark:border-amber-900/50">
                    <div class="flex flex-col sm:flex-row sm:items-center gap-3">
                        <flux:icon.exclamation-triangle class="size-5 text-amber-600 dark:text-amber-400 shrink-0" />
                        <div class="flex-1">
                            <p class="text-sm font-bold text-amber-900 dark:text-amber-200">
                                فعّلت بنداً بعد أن قيّم المعلمون؟
                            </p>
                            <p class="text-xs text-amber-800/80 dark:text-amber-200/70 mt-0.5">
                                النقاط تُسجَّل لحظة التقييم حسب البنود المفعّلة وقتها، فالتقييمات الأقدم من التفعيل تبقى بلا نقاط.
                                أعِد الاحتساب ليُعاد المرور على كل تقييمات فترة المسابقة. آمن للتكرار ولا يضاعف النقاط.
                            </p>
                        </div>
                        @if ($recalcCursor)
                            {{-- Each poll advances one batch; thousands of gradings cannot fit in one request. --}}
                            <div class="shrink-0 flex items-center gap-2 text-sm font-bold text-amber-900 dark:text-amber-200"
                                wire:poll.750ms="recalculateStep">
                                <flux:icon.arrow-path class="size-4 animate-spin" />
                                <span>
                                    {{ __('جارٍ الاحتساب') }}: {{ $this->recalcStageLabel() }}
                                    ({{ array_sum($recalcCursor['counts']) }})
                                </span>
                            </div>
                        @else
                            <flux:button wire:click="startRecalculation" icon="arrow-path" variant="filled"
                                class="shrink-0"
                                wire:confirm="سيُعاد احتساب نقاط كل طلاب المسابقة من تقييماتهم، وقد يتغيّر الترتيب. قد تستغرق العملية دقيقة أو أكثر — لا تغلق الصفحة. هل تريد المتابعة؟">
                                إعادة احتساب النقاط
                            </flux:button>
                        @endif
                    </div>
                </div>

                <div class="flex justify-end pt-4">
                    <flux:button wire:click="saveCriteria" variant="primary" class="bg-purple-600 hover:bg-purple-700 border-none text-white">حفظ التغييرات</flux:button>
                </div>
            </div>
        @endif

        {{-- TAB: BADGES --}}
        @if($activeTab === 'badges')
            <div class="space-y-6">
                <div class="flex justify-between items-center">
                    <div>
                        <flux:heading size="lg">معرض الأوسمة والشارات</flux:heading>
                        <flux:subheading>أنشئ أوسمة تشجيعية تمنح للطلاب تلقائياً عند تحقيق شروط محددة أو يدوياً.</flux:subheading>
                    </div>
                    <flux:button wire:click="createBadge" variant="primary" icon="plus" class="bg-purple-600 hover:bg-purple-700 border-none text-white">إضافة وسام جديد</flux:button>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    @forelse($dbBadges as $badge)
                        <flux:card class="flex flex-col justify-between border border-zinc-200 dark:border-zinc-800">
                            <div class="space-y-3">
                                <div class="flex items-center justify-between">
                                    <div class="p-1 rounded-xl bg-purple-50 dark:bg-purple-900/30 text-purple-500 flex items-center justify-center size-12 overflow-hidden border">
                                        @if(str_contains($badge->icon, '/') || str_contains($badge->icon, '.'))
                                            <img src="{{ asset('storage/' . $badge->icon) }}" class="w-full h-full object-contain" alt="{{ $badge->name }}">
                                        @else
                                            <flux:icon :icon="$badge->icon" class="size-6" />
                                        @endif
                                    </div>
                                    <flux:badge size="sm" color="indigo">
                                        @if(str_starts_with($badge->badge_type, 'streak_'))
                                            متتالي (Streak)
                                        @elseif(str_starts_with($badge->badge_type, 'count_'))
                                            تراكمي (Count)
                                        @elseif($badge->badge_type === 'hifz_streak')
                                            متتالية حفظ
                                        @elseif($badge->badge_type === 'attendance_streak')
                                            متتالية حضور
                                        @else
                                            يدوي / مخصص
                                        @endif
                                    </flux:badge>
                                </div>
                                <div>
                                    <h4 class="font-bold text-zinc-900 dark:text-zinc-100">{{ $badge->name }}</h4>
                                    <p class="text-sm text-zinc-500 mt-1">{{ $badge->description ?: 'بدون وصف' }}</p>
                                </div>
                                <div class="text-xs text-zinc-400 pt-2 border-t border-zinc-100 dark:border-zinc-800">
                                    المتطلبات:
                                    @if($badge->badge_type === 'streak_hifz' || $badge->badge_type === 'hifz_streak')
                                        متتالية حفظ {{ $badge->requirement_value }} يوم
                                    @elseif($badge->badge_type === 'streak_review')
                                        متتالية مراجعة {{ $badge->requirement_value }} يوم
                                    @elseif($badge->badge_type === 'streak_attendance' || $badge->badge_type === 'attendance_streak')
                                        متتالية حضور {{ $badge->requirement_value }} يوم
                                    @elseif($badge->badge_type === 'streak_criterion')
                                        متتالية بند ({{ $badge->leaderboardCriterion?->name ?? 'مخصص' }}) لعدد {{ $badge->requirement_value }} يوم متتالي
                                    @elseif($badge->badge_type === 'count_hifz')
                                        تراكمي حفظ {{ $badge->requirement_value }} يوم
                                    @elseif($badge->badge_type === 'count_review')
                                        تراكمي مراجعة {{ $badge->requirement_value }} يوم
                                    @elseif($badge->badge_type === 'count_attendance')
                                        تراكمي حضور {{ $badge->requirement_value }} يوم
                                    @elseif($badge->badge_type === 'count_criterion' || ($badge->badge_type === 'custom' && $badge->leaderboard_criterion_id))
                                        تراكمي بند ({{ $badge->leaderboardCriterion?->name ?? 'مخصص' }}) لعدد {{ $badge->requirement_value }} مرة
                                    @else
                                        توزيع يدوي / مخصص
                                    @endif
                                </div>
                            </div>
                            <div class="flex justify-end gap-2 mt-4 pt-3 border-t border-zinc-100 dark:border-zinc-800">
                                {{-- A manual badge has no progress to measure, so it is handed out by name. --}}
                                @if(in_array($badge->badge_type, ['manual', 'custom'], true) && ! $badge->leaderboard_criterion_id)
                                    <flux:button wire:click="openGrantBadge({{ $badge->id }})" size="xs" variant="filled" icon="user-plus">توزيع</flux:button>
                                @endif
                                <flux:button wire:click="editBadge({{ $badge->id }})" size="xs" variant="ghost" icon="pencil-square">تعديل</flux:button>
                                <flux:button wire:click="deleteBadge({{ $badge->id }})" wire:confirm="هل أنت متأكد من حذف هذا الوسام؟" size="xs" variant="ghost" icon="trash" class="text-rose-500 hover:text-rose-600" />
                            </div>
                        </flux:card>
                    @empty
                        <div class="col-span-3 text-center py-12 text-zinc-500 border border-dashed border-zinc-200 dark:border-zinc-800 rounded-xl">
                            لا توجد أوسمة حالية. أضف أول وسام الآن!
                        </div>
                    @endforelse
                </div>
            </div>
        @endif

        {{-- TAB: STREAKS --}}
        @if($activeTab === 'streaks')
            <div class="space-y-6">
                <div class="flex justify-between items-center">
                    <div>
                        <flux:heading size="lg">مكافآت أيام الحماسة (Streak Milestones)</flux:heading>
                        <flux:subheading>كافئ الطلاب عند التزامهم بعدد أيام متتالية محدد من شعلة الحماسة.</flux:subheading>
                    </div>
                    <flux:button wire:click="createMilestone" variant="primary" icon="plus" class="bg-purple-600 hover:bg-purple-700 border-none text-white">إضافة مكافأة متتالية</flux:button>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-right text-sm text-zinc-500 dark:text-zinc-400">
                        <thead class="text-xs text-zinc-700 uppercase bg-zinc-50 dark:bg-zinc-800/40 dark:text-zinc-300">
                            <tr>
                                <th scope="col" class="px-6 py-3">الأيام المتتالية المطلوبة</th>
                                <th scope="col" class="px-6 py-3">هدية نقاط طاقة الخبرة (XP)</th>
                                <th scope="col" class="px-6 py-3">هدية العملات ({{ $theme['currency_name'] }})</th>
                                <th scope="col" class="px-6 py-3">وسام التكريم المرتبط</th>
                                <th scope="col" class="px-6 py-3">الوصف</th>
                                <th scope="col" class="px-6 py-3">التحكم</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($milestones as $milestone)
                                <tr class="bg-white border-b dark:bg-zinc-900 dark:border-zinc-800">
                                    <td class="px-6 py-4 font-bold text-zinc-900 dark:text-white flex items-center gap-1.5">
                                        <flux:icon icon="fire" class="size-4 text-orange-500 animate-pulse" />
                                        <span>{{ $milestone->days_required }} يوم متتالي</span>
                                    </td>
                                    <td class="px-6 py-4">+{{ $milestone->reward_xp }} XP</td>
                                    <td class="px-6 py-4">+{{ $milestone->reward_coins }} عملة</td>
                                    <td class="px-6 py-4">
                                        @if($milestone->reward_badge_id)
                                            @php $b = $dbBadges->firstWhere('id', $milestone->reward_badge_id); @endphp
                                            @if($b)
                                                <span class="inline-flex items-center gap-1 bg-purple-50 dark:bg-purple-900/30 text-purple-600 dark:text-purple-400 px-2 py-1 rounded-lg text-xs">
                                                    @if(str_contains($b->icon, '/') || str_contains($b->icon, '.'))
                                                        <img src="{{ asset('storage/' . $b->icon) }}" class="size-4 object-contain" alt="{{ $b->name }}">
                                                    @else
                                                        <flux:icon :icon="$b->icon" class="size-3.5" />
                                                    @endif
                                                    <span>{{ $b->name }}</span>
                                                </span>
                                            @else
                                                <span class="text-zinc-400">-</span>
                                            @endif
                                        @else
                                            <span class="text-zinc-400">-</span>
                                        @endif
                                    </td>
                                    <td class="px-6 py-4">{{ $milestone->description ?: 'بدون وصف' }}</td>
                                    <td class="px-6 py-4 flex items-center gap-2">
                                        <flux:button wire:click="editMilestone({{ $milestone->id }})" size="xs" variant="ghost" icon="pencil-square" />
                                        <flux:button wire:click="deleteMilestone({{ $milestone->id }})" wire:confirm="هل تريد حذف مكافأة هذه المتتالية؟" size="xs" variant="ghost" icon="trash" class="text-rose-500 hover:text-rose-600" />
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="px-6 py-8 text-center text-zinc-500">
                                        لا توجد مكافآت حماسة مخصصة حالياً.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        {{-- TAB: TEAMS --}}
        @if($activeTab === 'teams')
            <div class="space-y-6">
                <div class="flex justify-between items-center">
                    <div>
                        <flux:heading size="lg">إدارة وتوزيع فرق التحدي (الأسر)</flux:heading>
                        <flux:subheading>قسّم طلاب الحلقات المشاركة إلى فرق (أسر) وعيّن القادة والمساعدين والألوان والشعارات المخصصة لكل أسرة.</flux:subheading>
                    </div>
                    <flux:button wire:click="createTeam" variant="primary" icon="plus" class="bg-purple-600 hover:bg-purple-700 border-none text-white">إنشاء أسرة جديدة</flux:button>
                </div>

                {{-- Teams Grid --}}
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    @forelse($dbTeams as $team)
                        @php
                            $leader = $team->students()->wherePivot('role', 'leader')->first();
                            $assistant = $team->students()->wherePivot('role', 'assistant')->first();
                            $membersCount = $team->students()->wherePivot('role', 'member')->count();
                        @endphp
                        <flux:card class="flex flex-col justify-between border-2 relative overflow-hidden bg-zinc-50/20 dark:bg-zinc-800/10" style="border-color: {{ $team->color ?? '#e4e4e7' }}">
                            <div class="space-y-4">
                                <div class="flex items-center gap-3">
                                    <div class="size-12 rounded-xl flex items-center justify-center border shadow-inner bg-zinc-50 dark:bg-zinc-900 overflow-hidden" style="border-color: {{ $team->color ?? '#e4e4e7' }}">
                                        @if($team->logo_path)
                                            <img src="{{ asset('storage/' . $team->logo_path) }}" class="size-10 object-contain" />
                                        @else
                                            <flux:icon icon="users" class="size-6" style="color: {{ $team->color ?? '#6366f1' }}" />
                                        @endif
                                    </div>
                                    <div>
                                        <h4 class="font-bold text-zinc-900 dark:text-zinc-100 text-lg flex items-center gap-2">
                                            <span>{{ $team->name }}</span>
                                        </h4>
                                        @if($team->slogan)
                                            <p class="text-xs italic text-zinc-500 mt-0.5">"{{ $team->slogan }}"</p>
                                        @endif
                                    </div>
                                </div>

                                <flux:separator />

                                <div class="space-y-2 text-sm text-zinc-600 dark:text-zinc-400">
                                    <div class="flex justify-between">
                                        <span>القائد:</span>
                                        <span class="font-semibold text-zinc-900 dark:text-zinc-100">{{ $leader ? $leader->name : 'غير محدد' }}</span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span>النائب:</span>
                                        <span class="font-semibold text-zinc-900 dark:text-zinc-100">{{ $assistant ? $assistant->name : 'غير محدد' }}</span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span>عدد الأعضاء:</span>
                                        <span class="font-semibold text-zinc-900 dark:text-zinc-100">{{ $membersCount }} طلاب</span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span>خزينة الأسرة:</span>
                                        <span class="font-semibold text-amber-500">{{ $team->coins }} عملة</span>
                                    </div>
                                </div>
                            </div>

                            <div class="flex justify-end gap-2 pt-4 border-t border-zinc-100 dark:border-zinc-800/80 mt-4">
                                <flux:button wire:click="editTeam({{ $team->id }})" size="sm" variant="ghost" icon="pencil-square">تعديل</flux:button>
                                <flux:button wire:click="deleteTeam({{ $team->id }})" wire:confirm="هل تريد حذف هذا الفريق بالكامل؟" size="sm" variant="ghost" icon="trash" class="text-rose-500 hover:text-rose-600">حذف</flux:button>
                            </div>
                        </flux:card>
                    @empty
                        <div class="col-span-full text-center py-12 text-zinc-500 bg-zinc-50 dark:bg-zinc-800/10 rounded-2xl border border-dashed border-zinc-300 dark:border-zinc-700">
                            لا توجد أسر حالياً في هذه المسابقة. ابدأ بإنشاء أسرة جديدة!
                        </div>
                    @endforelse
                </div>

                {{-- Students Assignment Status --}}
                <div class="mt-8">
                    <flux:heading size="lg" class="mb-2">حالة توزيع الطلاب بالحلقات</flux:heading>
                    <flux:subheading class="mb-4">استعراض لجميع طلاب الحلقات المشاركة وتوزيعهم الحالي على الأسر.</flux:subheading>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        @php
                                            $studentsGrouped = $students->groupBy(fn($std) => $std->circle->name ?? 'بدون حلقة');
                        @endphp
                        @foreach($studentsGrouped as $circleName => $circleStudents)
                            <div class="p-6 rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 shadow-sm space-y-4">
                                <h3 class="font-black text-indigo-600 dark:text-indigo-400 text-md flex items-center gap-2">
                                    <span>🏫 {{ $circleName }}</span>
                                    <flux:badge size="sm" color="zinc">{{ $circleStudents->count() }} طالب</flux:badge>
                                </h3>
                                
                                <div class="divide-y divide-zinc-100 dark:divide-zinc-800 max-h-[300px] overflow-y-auto pr-2">
                                    @forelse($circleStudents as $std)
                                        @php
                                            $stdTeamId = $studentTeams[$std->id] ?? null;
                                            $stdTeam = $dbTeams->firstWhere('id', $stdTeamId);
                                            $stdRole = $studentRoles[$std->id] ?? 'member';
                                            $roleText = $stdRole === 'leader' ? 'قائد' : ($stdRole === 'assistant' ? 'نائب' : 'عضو');
                                        @endphp
                                        <div class="py-2.5 flex justify-between items-center text-sm">
                                            <span class="font-medium text-zinc-900 dark:text-zinc-100">{{ $std->name }}</span>
                                            <div class="flex items-center gap-2">
                                                @if($stdTeam)
                                                    <flux:badge size="sm" style="background-color: {{ $stdTeam->color }}15; color: {{ $stdTeam->color }}; border-color: {{ $stdTeam->color }}30" class="border">
                                                        {{ $stdTeam->name }} ({{ $roleText }})
                                                    </flux:badge>
                                                @else
                                                    <flux:badge size="sm" color="red" class="animate-pulse">
                                                        غير موزع ⚠️
                                                    </flux:badge>
                                                @endif
                                            </div>
                                        </div>
                                    @empty
                                        <div class="text-zinc-500 text-xs py-4 text-center">لا يوجد طلاب في هذه الحلقة.</div>
                                    @endforelse
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        @endif

        {{-- TAB: TRACKS (ranking divisions) --}}
        @if($activeTab === 'tracks')
            <div class="space-y-6">
                <div class="flex items-center justify-between gap-4">
                    <div>
                        <flux:heading size="lg">مسارات الترتيب</flux:heading>
                        <flux:subheading>قسّم المتصدرين إلى مسارات، لكل مسار ترتيب صدارة مستقل. الطلاب غير المُسكّنين يظهرون في مسار "عام".</flux:subheading>
                    </div>
                    <flux:button wire:click="createTrack" variant="primary" icon="plus" class="bg-purple-600 hover:bg-purple-700 border-none text-white shrink-0">مسار جديد</flux:button>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    @forelse($dbTracks as $track)
                        <div class="p-5 rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 shadow-sm flex flex-col gap-3">
                            <div class="flex items-start justify-between gap-2">
                                <div class="flex items-center gap-2 min-w-0">
                                    <div class="p-1.5 rounded-lg bg-purple-50 dark:bg-purple-900/30 text-purple-600 dark:text-purple-400 shrink-0"><flux:icon icon="flag" class="size-5" /></div>
                                    <span class="font-bold text-zinc-900 dark:text-white truncate">{{ $track->name }}</span>
                                </div>
                                <flux:dropdown position="bottom" align="end">
                                    <flux:button variant="ghost" size="sm" icon="ellipsis-horizontal" class="shrink-0 text-zinc-400" />
                                    <flux:menu>
                                        <flux:menu.item wire:click="editTrack({{ $track->id }})" icon="pencil-square">تعديل</flux:menu.item>
                                        <flux:menu.separator />
                                        <flux:menu.item wire:click="deleteTrack({{ $track->id }})" wire:confirm="هل أنت متأكد من حذف هذا المسار؟" icon="trash" class="text-rose-500 hover:text-rose-600">حذف</flux:menu.item>
                                    </flux:menu>
                                </flux:dropdown>
                            </div>
                            @if($track->description)
                                <p class="text-sm text-zinc-500 dark:text-zinc-400 line-clamp-2">{{ $track->description }}</p>
                            @endif
                            <div class="mt-auto pt-1">
                                <flux:badge size="sm" color="zinc" icon="users">{{ $track->students_count }} طالب</flux:badge>
                            </div>
                        </div>
                    @empty
                        <div class="col-span-full text-center py-12 text-zinc-500 border border-dashed border-zinc-200 dark:border-zinc-800 rounded-xl">
                            لا توجد مسارات بعد. أنشئ أول مسار لتقسيم المتصدرين.
                        </div>
                    @endforelse
                </div>
            </div>

            {{-- Track editor modal --}}
            <flux:modal wire:model="showTrackModal" class="md:w-[700px] w-full">
                <div class="space-y-5">
                    <flux:heading size="lg">{{ $editingTrackId ? 'تعديل المسار' : 'مسار جديد' }}</flux:heading>

                    <flux:input wire:model="track_name" label="اسم المسار" placeholder="مثال: المتقدمون" required />
                    <flux:textarea wire:model="track_description" label="الوصف (اختياري)" placeholder="وصف موجز يميّز هذا المسار" rows="2" />

                    <div>
                        <flux:heading size="sm" class="mb-2">تسكين الطلاب</flux:heading>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-1.5 max-h-72 overflow-y-auto p-2 border border-zinc-200 dark:border-zinc-700 rounded-lg">
                            @php $trackGrouped = $students->groupBy(fn($s) => $s->circle->name ?? 'بدون حلقة'); @endphp
                            @forelse($trackGrouped as $circleName => $circleStudents)
                                <div class="sm:col-span-2 text-xs font-bold text-zinc-400 mt-1">{{ $circleName }}</div>
                                @foreach($circleStudents as $std)
                                    <label class="flex items-center gap-2 p-1.5 rounded hover:bg-zinc-50 dark:hover:bg-zinc-800 cursor-pointer">
                                        <flux:checkbox wire:model="track_student_ids" value="{{ $std->id }}" />
                                        <span class="text-sm text-zinc-800 dark:text-zinc-200">{{ $std->name }}</span>
                                    </label>
                                @endforeach
                            @empty
                                <span class="text-xs text-zinc-400 col-span-2 text-center py-3">لا يوجد طلاب في الحلقات المشاركة.</span>
                            @endforelse
                        </div>
                    </div>

                    <div class="flex justify-end gap-2">
                        <flux:button wire:click="$set('showTrackModal', false)" variant="ghost">إلغاء</flux:button>
                        <flux:button wire:click="saveTrack" variant="primary" class="bg-purple-600 hover:bg-purple-700 border-none text-white">حفظ المسار</flux:button>
                    </div>
                </div>
            </flux:modal>
        @endif

        {{-- TAB: STUDENT STANDINGS --}}
        @if($activeTab === 'standings')
            <div class="space-y-6">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                    <div>
                        <flux:heading size="lg">مراكز الطلاب</flux:heading>
                        <flux:subheading>ترتيب الطلاب ضمن كل مسار (إن وُجدت المسارات)، مع ما أنجزه كل طالب في اليوم المحدد. اضغط على أي طالب لعرض إنجازاته حسب الأيام.</flux:subheading>
                    </div>
                    <div class="flex items-end gap-2 shrink-0">
                        @php
                            $displayUrl = \Illuminate\Support\Facades\URL::signedRoute('results.display', ['leaderboard' => $competition->id]);
                        @endphp
                        <div x-data>
                            <flux:button variant="primary" icon="tv"
                                class="bg-purple-600 hover:bg-purple-700 border-none text-white"
                                href="{{ $displayUrl }}" target="_blank"
                                @click.prevent="navigator.clipboard.writeText(@js($displayUrl)); window.open(@js($displayUrl), '_blank'); $dispatch('toast', { message: 'تم فتح شاشة العرض ونسخ رابطها', variant: 'success' })">
                                شاشة العرض (بروجكتر)
                            </flux:button>
                        </div>
                        <div class="sm:w-64">
                            <flux:label class="mb-1">ترتيب خلال</flux:label>
                            <x-competition-week-select :weeks="$standingsWeeks" wire:model.live="standingsWeek" />
                        </div>
                        <div class="sm:w-56"><livewire:shared.hijri-datepicker wire:model.live="standingsDate" label="اليوم (هجري)" /></div>
                    </div>
                </div>

                @forelse($standingsGroups as $group)
                    <div class="space-y-3">
                        <div class="flex items-center gap-2">
                            <div class="p-1.5 rounded-lg bg-purple-50 dark:bg-purple-900/30 text-purple-600 dark:text-purple-400 shrink-0"><flux:icon icon="flag" class="size-5" /></div>
                            <div class="min-w-0">
                                <span class="font-bold text-zinc-900 dark:text-white">{{ $group['name'] }}</span>
                                @if(!empty($group['description']))
                                    <p class="text-xs text-zinc-500 dark:text-zinc-400 line-clamp-1">{{ $group['description'] }}</p>
                                @endif
                            </div>
                        </div>

                        <div class="overflow-x-auto border border-zinc-200 dark:border-zinc-800 rounded-xl">
                            <table class="w-full text-sm">
                                <thead class="bg-zinc-50 dark:bg-zinc-800/50 text-zinc-500 dark:text-zinc-400">
                                    <tr>
                                        <th class="py-2.5 px-3 text-center font-medium w-16">المركز</th>
                                        <th class="py-2.5 px-3 text-right font-medium">الطالب</th>
                                        <th class="py-2.5 px-3 text-center font-medium w-28">إجمالي النقاط</th>
                                        <th class="py-2.5 px-3 text-right font-medium">إنجاز اليوم</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                                    @forelse($group['standings'] as $row)
                                        <tr wire:click="viewStudentAchievements({{ $row['student']->id }})" class="hover:bg-purple-50/40 dark:hover:bg-purple-900/10 cursor-pointer transition-colors">
                                            <td class="py-2.5 px-3 text-center">
                                                @php $rank = $row['track_rank']; @endphp
                                                <span @class([
                                                    'inline-flex items-center justify-center size-7 rounded-full text-xs font-bold',
                                                    'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300' => $rank === 1,
                                                    'bg-zinc-200 text-zinc-700 dark:bg-zinc-700 dark:text-zinc-200' => $rank === 2,
                                                    'bg-orange-100 text-orange-700 dark:bg-orange-900/40 dark:text-orange-300' => $rank === 3,
                                                    'bg-zinc-100 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400' => $rank > 3,
                                                ])>{{ $rank }}</span>
                                            </td>
                                            <td class="py-2.5 px-3">
                                                <span class="font-medium text-zinc-800 dark:text-zinc-100">{{ $row['student']->name }}</span>
                                                @if($row['student']->circle?->name)
                                                    <span class="block text-xs text-zinc-400">{{ $row['student']->circle->name }}</span>
                                                @endif
                                            </td>
                                            <td class="py-2.5 px-3 text-center">
                                                <flux:badge size="sm" color="purple">{{ $row['score'] }}</flux:badge>
                                            </td>
                                            <td class="py-2.5 px-3">
                                                @if(!empty($row['today_achievements']))
                                                    <div class="flex flex-wrap items-center gap-1.5">
                                                        @foreach($row['today_achievements'] as $ach)
                                                            <span class="inline-flex items-center gap-1 rounded-full bg-zinc-100 dark:bg-zinc-800 px-2 py-0.5 text-xs text-zinc-700 dark:text-zinc-300">
                                                                {{ $ach['description'] }}
                                                                @if($ach['xp'] > 0)<span class="font-bold text-purple-600 dark:text-purple-400">+{{ $ach['xp'] }}</span>@endif
                                                            </span>
                                                        @endforeach
                                                        @if($row['today_points'] > 0)
                                                            <span class="text-xs font-bold text-emerald-600 dark:text-emerald-400">= {{ $row['today_points'] }} نقطة</span>
                                                        @endif
                                                    </div>
                                                @else
                                                    <span class="text-xs text-zinc-400">لا يوجد إنجاز في هذا اليوم</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="4" class="py-6 text-center text-zinc-400">لا يوجد طلاب في هذا المسار.</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                @empty
                    <div class="text-center py-12 text-zinc-500 border border-dashed border-zinc-200 dark:border-zinc-800 rounded-xl">
                        لا يوجد طلاب في هذه المسابقة بعد.
                    </div>
                @endforelse
            </div>

            {{-- Achievements-by-day modal --}}
            <flux:modal wire:model="showAchievementsModal" class="md:w-[640px] w-full">
                <div class="space-y-5">
                    <flux:heading size="lg">إنجازات {{ $achievementsStudentName ?? 'الطالب' }}</flux:heading>

                    <div class="space-y-4 max-h-[60vh] overflow-y-auto pr-1">
                        @forelse($achievementsByDay as $day => $items)
                            <div class="space-y-2">
                                <div class="flex items-center justify-between gap-2">
                                    <span class="font-bold text-zinc-800 dark:text-zinc-100"><x-hijri-date :date="\Carbon\Carbon::parse($day)" style="weekday" /></span>
                                    <flux:badge size="sm" color="emerald">{{ collect($items)->sum('xp') }} نقطة</flux:badge>
                                </div>
                                <ul class="space-y-1.5">
                                    @foreach($items as $ach)
                                        <li class="flex items-center justify-between gap-2 rounded-lg bg-zinc-50 dark:bg-zinc-800/50 px-3 py-2 text-sm">
                                            <span class="text-zinc-700 dark:text-zinc-300 flex items-center gap-1.5">
                                                {{ $ach['description'] }}
                                                @if($ach['pending'])<flux:badge size="sm" color="amber">قيد الاستلام</flux:badge>@endif
                                            </span>
                                            <span class="shrink-0 font-bold {{ $ach['xp'] > 0 ? 'text-purple-600 dark:text-purple-400' : 'text-zinc-400' }}">
                                                @if($ach['xp'] > 0)
                                                    +{{ $ach['xp'] }} XP
                                                @elseif($ach['coins'] > 0)
                                                    +{{ $ach['coins'] }} عملة
                                                @else
                                                    —
                                                @endif
                                            </span>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @empty
                            <p class="text-center text-zinc-400 py-6">لا توجد إنجازات مسجلة لهذا الطالب.</p>
                        @endforelse
                    </div>

                    <div class="flex justify-end">
                        <flux:button wire:click="$set('showAchievementsModal', false)" variant="ghost">إغلاق</flux:button>
                    </div>
                </div>
            </flux:modal>
        @endif

        {{-- TAB: TEAM TASKS --}}
        @if($activeTab === 'team_tasks')
            <div class="space-y-8">
                <!-- Section 1: Task Definitions -->
                <div class="space-y-4">
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                        <div>
                            <flux:heading size="lg" class="font-bold text-zinc-950 dark:text-white">قائمة مهام المجموعات (التعريفات)</flux:heading>
                            <flux:subheading class="text-zinc-500">حدد المهام العامة للمجموعات (مثل النظافة، القيادة، إلخ) وقيمتها الافتراضية للجوائز.</flux:subheading>
                        </div>
                        <flux:button wire:click="createTeamTask" variant="primary" icon="plus" class="bg-purple-600 hover:bg-purple-700 border-none text-white font-medium">
                            تعريف مهمة جديدة
                        </flux:button>
                    </div>

                    <div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-950 shadow-sm">
                        <table class="w-full text-right text-sm text-zinc-500 dark:text-zinc-400">
                            <thead class="text-xs text-zinc-700 dark:text-zinc-300 bg-zinc-50 dark:bg-zinc-900 border-b border-zinc-200 dark:border-zinc-800">
                                <tr>
                                    <th scope="col" class="px-6 py-3.5 font-bold">اسم المهمة</th>
                                    <th scope="col" class="px-6 py-3.5 font-bold">الوصف</th>
                                    <th scope="col" class="px-6 py-3.5 font-bold">معايير التقييم</th>
                                    <th scope="col" class="px-6 py-3.5 font-bold">مكافأة الخبرة (XP)</th>
                                    <th scope="col" class="px-6 py-3.5 font-bold">مكافأة العملات</th>
                                    <th scope="col" class="px-6 py-3.5 font-bold">التحكم</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                                @forelse($dbTeamTasks as $task)
                                    <tr class="hover:bg-zinc-50/50 dark:hover:bg-zinc-900/30 transition-colors">
                                        <td class="px-6 py-4 font-bold text-zinc-900 dark:text-white">
                                            {{ $task->name }}
                                        </td>
                                        <td class="px-6 py-4 max-w-xs truncate text-zinc-600 dark:text-zinc-400">
                                            {{ $task->description ?: '-' }}
                                        </td>
                                        <td class="px-6 py-4 max-w-xs truncate text-zinc-600 dark:text-zinc-400">
                                            {{ $task->evaluation_criteria ?: '-' }}
                                        </td>
                                        <td class="px-6 py-4 font-medium">
                                            +{{ $task->xp_reward }} XP
                                        </td>
                                        <td class="px-6 py-4 font-medium">
                                            +{{ $task->coins_reward }} عملة
                                        </td>
                                        <td class="px-6 py-4">
                                            <div class="flex items-center gap-2">
                                                <flux:button wire:click="editTeamTask({{ $task->id }})" size="xs" variant="ghost" icon="pencil-square" title="تعديل المهمة" />
                                                <flux:button wire:click="deleteTeamTask({{ $task->id }})" wire:confirm="تنبيه: سيؤدي حذف هذا التعريف إلى حذف جميع التكليفات والتقييمات المرتبطة به وسحب العملات الممنوحة من الفرق. هل أنت متأكد؟" size="xs" variant="ghost" icon="trash" class="text-rose-500 hover:text-rose-600" />
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="px-6 py-10 text-center text-zinc-500 dark:text-zinc-400">
                                            لا توجد مهام معرفة حالياً. أضف أول مهمة لبدء تكليف المجموعات.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="border-t border-zinc-200 dark:border-zinc-800 my-6"></div>

                <!-- Section 2: Task Assignments -->
                <div class="space-y-4">
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                        <div>
                            <flux:heading size="lg" class="font-bold text-zinc-950 dark:text-white">تكليفات وتقييمات المجموعات</flux:heading>
                            <flux:subheading class="text-zinc-500">كلف الأسر والمجموعات بالمهام المعرفة أعلاه خلال فترة زمنية محددة وقيم إنجازهم.</flux:subheading>
                        </div>
                        <flux:button wire:click="createAssignment" variant="primary" icon="plus" class="bg-purple-600 hover:bg-purple-700 border-none text-white font-medium" :disabled="$dbTeamTasks->isEmpty()">
                            تكليف جديد لمجموعة
                        </flux:button>
                    </div>

                    <div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-950 shadow-sm">
                        <table class="w-full text-right text-sm text-zinc-500 dark:text-zinc-400">
                            <thead class="text-xs text-zinc-700 dark:text-zinc-300 bg-zinc-50 dark:bg-zinc-900 border-b border-zinc-200 dark:border-zinc-800">
                                <tr>
                                    <th scope="col" class="px-6 py-3.5 font-bold">المهمة</th>
                                    <th scope="col" class="px-6 py-3.5 font-bold">المجموعة المكلفة</th>
                                    <th scope="col" class="px-6 py-3.5 font-bold">الفترة الزمنية</th>
                                    <th scope="col" class="px-6 py-3.5 font-bold">أقصى مكافأة للتعريف</th>
                                    <th scope="col" class="px-6 py-3.5 font-bold">الدرجة الممنوحة</th>
                                    <th scope="col" class="px-6 py-3.5 font-bold">الحالة</th>
                                    <th scope="col" class="px-6 py-3.5 font-bold">التحكم</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                                @forelse($dbAssignments as $assignment)
                                    <tr class="hover:bg-zinc-50/50 dark:hover:bg-zinc-900/30 transition-colors">
                                        <td class="px-6 py-4 font-bold text-zinc-900 dark:text-white">
                                            {{ $assignment->task->name }}
                                        </td>
                                        <td class="px-6 py-4">
                                            <span class="inline-flex items-center gap-1.5">
                                                <span class="size-3.5 rounded-full inline-block shrink-0" style="background-color: {{ $assignment->team->color ?? '#cbd5e1' }}"></span>
                                                <span class="font-medium text-zinc-800 dark:text-zinc-200">{{ $assignment->team->name }}</span>
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 text-xs font-semibold text-zinc-600 dark:text-zinc-400">
                                            <div>من: <x-hijri-date :date="$assignment->start_date" /></div>
                                            <div class="mt-0.5">إلى: <x-hijri-date :date="$assignment->end_date" /></div>
                                        </td>
                                        <td class="px-6 py-4 text-xs">
                                            <div>+{{ $assignment->task->xp_reward }} XP</div>
                                            <div class="mt-0.5">+{{ $assignment->task->coins_reward }} عملة</div>
                                        </td>
                                        <td class="px-6 py-4 font-bold">
                                            @if($assignment->grade !== null)
                                                <span class="text-indigo-600 dark:text-indigo-400">{{ $assignment->grade }} / 100</span>
                                                @php
                                                    $awardedCoins = (int) round(($assignment->grade / 100) * $assignment->task->coins_reward);
                                                    $awardedXp = (int) round(($assignment->grade / 100) * $assignment->task->xp_reward);
                                                @endphp
                                                <span class="text-[10px] text-zinc-400 dark:text-zinc-500 block font-normal mt-1">
                                                    (كسب الفريق: {{ $awardedXp }} XP، {{ $awardedCoins }} عملة)
                                                </span>
                                            @else
                                                <span class="text-zinc-400 font-normal">-</span>
                                            @endif
                                        </td>
                                        <td class="px-6 py-4">
                                            @if($assignment->status === 'completed')
                                                <flux:badge color="emerald" size="sm" icon="check">تم التقييم</flux:badge>
                                            @else
                                                <flux:badge color="amber" size="sm" icon="clock">قيد التنفيذ</flux:badge>
                                            @endif
                                        </td>
                                        <td class="px-6 py-4">
                                            <div class="flex items-center gap-2">
                                                <flux:button wire:click="editAssignment({{ $assignment->id }})" size="xs" variant="ghost" icon="pencil-square" title="تعديل وتقييم التكليف" />
                                                <flux:button wire:click="deleteAssignment({{ $assignment->id }})" wire:confirm="هل تريد حذف هذا التكليف وإلغاء الجوائز والعملات الممنوحة للأسرة؟" size="xs" variant="ghost" icon="trash" class="text-rose-500 hover:text-rose-600" />
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="7" class="px-6 py-10 text-center text-zinc-500 dark:text-zinc-400">
                                            لا توجد تكليفات مجموعات حالياً. اضغط على "تكليف جديد لمجموعة" للبدء.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        @endif

        {{-- TAB: ACTIVITIES --}}
        @if($activeTab === 'activities')
            <div class="space-y-8">
                <!-- Activities Definition Card -->
                <div class="space-y-6">
                    <div class="flex justify-between items-center">
                        <div>
                            <flux:heading size="lg">إدارة الفعاليات والأنشطة</flux:heading>
                            <flux:subheading>حدد الفعاليات الرياضية، الثقافية، والحركية، والمراكز المخصصة لها وجوائزها.</flux:subheading>
                        </div>
                        <flux:button wire:click="createActivity" variant="primary" icon="plus" class="bg-purple-600 hover:bg-purple-700 border-none text-white">تعريف فعالية جديدة</flux:button>
                    </div>

                    <div class="bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-2xl overflow-hidden shadow-sm">
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm text-right text-zinc-500 dark:text-zinc-400">
                                <thead class="text-xs text-zinc-700 dark:text-zinc-300 bg-zinc-50 dark:bg-zinc-800/50 border-b border-zinc-200 dark:border-zinc-800">
                                    <tr>
                                        <th scope="col" class="px-6 py-4">اسم الفعالية</th>
                                        <th scope="col" class="px-6 py-4">الوصف</th>
                                        <th scope="col" class="px-6 py-4">المراكز والجوائز المعرفة</th>
                                        <th scope="col" class="px-6 py-4">الجولات والتواريخ المجدولة</th>
                                        <th scope="col" class="px-6 py-4">الإجراءات</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                                    @forelse($dbActivities as $activity)
                                        <tr class="hover:bg-zinc-50/50 dark:hover:bg-zinc-800/10">
                                            <td class="px-6 py-4 font-bold text-zinc-900 dark:text-zinc-100">{{ $activity->name }}</td>
                                            <td class="px-6 py-4 text-xs">{{ $activity->description ?: 'بدون وصف' }}</td>
                                            <td class="px-6 py-4">
                                                <div class="flex flex-wrap gap-2">
                                                    @foreach($activity->ranks as $rank)
                                                        <flux:badge size="sm" color="zinc" class="flex items-center gap-1">
                                                            <span class="font-bold">{{ $rank->name }}:</span>
                                                            <span class="text-[10px] text-zinc-500">
                                                                (المجموعة: +{{ $rank->team_xp }}XP / +{{ $rank->team_coins }}{{ $theme['currency_name'] }})
                                                                (الأعضاء: +{{ $rank->member_xp }}XP / +{{ $rank->member_coins }}{{ $theme['currency_name'] }})
                                                            </span>
                                                        </flux:badge>
                                                    @endforeach
                                                </div>
                                            </td>
                                            <td class="px-6 py-4">
                                                <div class="flex flex-wrap gap-2">
                                                    @forelse($activity->rounds as $round)
                                                        <flux:badge size="sm" color="purple" class="flex items-center gap-1">
                                                            <span class="font-bold">{{ $round->name }}:</span>
                                                            <span class="text-[10px] text-purple-700 dark:text-purple-300">(<x-hijri-date :date="$round->round_date" />)</span>
                                                        </flux:badge>
                                                    @empty
                                                        <span class="text-xs text-zinc-400">لا توجد جولات مجدولة</span>
                                                    @endforelse
                                                </div>
                                            </td>
                                            <td class="px-6 py-4">
                                                <div class="flex items-center gap-2">
                                                    <flux:button wire:click="editActivity({{ $activity->id }})" size="xs" variant="ghost" icon="pencil-square" title="تعديل الفعالية والمراكز" />
                                                    <flux:button wire:click="deleteActivity({{ $activity->id }})" wire:confirm="هل تريد حذف هذه الفعالية وإلغاء كل مكافآت رصد النتائج المرتبطة بها للفائزين؟" size="xs" variant="ghost" icon="trash" class="text-rose-500 hover:text-rose-600" />
                                                </div>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="5" class="px-6 py-10 text-center text-zinc-500 dark:text-zinc-400">
                                                لا توجد فعاليات معرفة حالياً. اضغط على "تعريف فعالية جديدة" للبدء.
                                            </td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <hr class="border-zinc-200 dark:border-zinc-800" />

                <!-- Scheduled Rounds Grid / Records -->
                <div class="space-y-6">
                    <div class="flex justify-between items-center">
                        <div>
                            <flux:heading size="lg">سجل نتائج وجولات الفعالية</flux:heading>
                            <flux:subheading>رصد وتعديل المجموعات الفائزة بكل جولة/مباراة تم جدولتها في الفعاليات أعلاه.</flux:subheading>
                        </div>
                    </div>

                    <div class="space-y-8">
                        @php
                            $groupedRounds = $dbRounds->groupBy(fn($r) => $r->activity->name);
                        @endphp
                        @forelse($groupedRounds as $activityName => $rounds)
                            <div class="space-y-4">
                                <h4 class="text-sm font-bold text-zinc-950 dark:text-white flex items-center gap-2 bg-zinc-50 dark:bg-zinc-900/50 px-4 py-2 rounded-xl border border-zinc-200/50 dark:border-zinc-800/50">
                                    <flux:icon icon="puzzle-piece" class="size-4 text-purple-600 dark:text-purple-400" />
                                    <span>فعالية: {{ $activityName }}</span>
                                    <flux:badge size="sm" color="purple" class="font-bold">{{ $rounds->count() }} جولة</flux:badge>
                                </h4>

                                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                                    @foreach($rounds as $round)
                                        <flux:card class="flex flex-col justify-between border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-950 rounded-2xl p-5 shadow-sm hover:shadow-md transition-shadow relative overflow-hidden">
                                            <div class="space-y-4 flex-1 flex flex-col justify-between">
                                                <div class="space-y-2.5">
                                                    <!-- Header Badges -->
                                                    <div class="flex items-center justify-between gap-2">
                                                        @if($round->winners->isEmpty())
                                                            <span class="inline-flex items-center bg-amber-50 dark:bg-amber-950/20 text-amber-700 dark:text-amber-400 text-xs px-2 py-0.5 rounded-md font-bold">
                                                                معلق ⏳
                                                            </span>
                                                        @else
                                                            <span class="inline-flex items-center bg-emerald-50 dark:bg-emerald-950/20 text-emerald-700 dark:text-emerald-400 text-xs px-2 py-0.5 rounded-md font-bold">
                                                                تم الرصد ✅
                                                            </span>
                                                        @endif
                                                        <span class="inline-flex items-center gap-1 text-zinc-500 dark:text-zinc-400 text-xs font-semibold">
                                                            <flux:icon icon="calendar" class="size-3.5 text-zinc-400" />
                                                            <x-hijri-date :date="$round->round_date" />
                                                        </span>
                                                    </div>

                                                    <!-- Title -->
                                                    <h4 class="font-black text-zinc-900 dark:text-zinc-100 text-md pt-1 flex items-center gap-1.5">
                                                        <flux:icon icon="flag" class="size-4 text-purple-500 shrink-0" />
                                                        <span>{{ $round->name }}</span>
                                                    </h4>
                                                </div>

                                                <div class="border-t border-dashed border-zinc-100 dark:border-zinc-800/80 my-3"></div>

                                                <!-- Status / Winners -->
                                                <div class="space-y-2 flex-1">
                                                    @if($round->winners->isEmpty())
                                                        <div class="flex flex-col items-center justify-center py-6 text-center bg-zinc-50/50 dark:bg-zinc-900/40 rounded-xl border border-dashed border-zinc-200 dark:border-zinc-800">
                                                            <flux:icon icon="clock" class="size-6 text-zinc-400 animate-pulse mb-1.5" />
                                                            <span class="text-xs font-bold text-zinc-500 dark:text-zinc-400">بانتظار رصد الفائزين ⏳</span>
                                                        </div>
                                                    @else
                                                        <div class="space-y-2">
                                                            <div class="text-[10px] uppercase tracking-wider text-zinc-400 dark:text-zinc-500 font-bold mb-1">النتائج المحققة:</div>
                                                            @foreach($round->winners as $winner)
                                                                @php
                                                                    $rankColor = 'bg-zinc-100 text-zinc-800 dark:bg-zinc-800 dark:text-zinc-200';
                                                                    $rankIcon = 'trophy';
                                                                    if (str_contains($winner->rank->name, 'أول') || str_contains($winner->rank->name, 'الاول')) {
                                                                        $rankColor = 'bg-amber-50 dark:bg-amber-950/30 text-amber-700 dark:text-amber-400 border border-amber-200/50 dark:border-amber-900/30';
                                                                        $rankIcon = 'trophy';
                                                                    } elseif (str_contains($winner->rank->name, 'ثاني') || str_contains($winner->rank->name, 'الثاني')) {
                                                                        $rankColor = 'bg-slate-50 dark:bg-slate-900/30 text-slate-700 dark:text-slate-400 border border-slate-200/50 dark:border-slate-800/30';
                                                                        $rankIcon = 'trophy';
                                                                    } elseif (str_contains($winner->rank->name, 'ثالث') || str_contains($winner->rank->name, 'الثالث')) {
                                                                        $rankColor = 'bg-amber-100/50 dark:bg-amber-900/10 text-amber-800/90 dark:text-amber-500/90 border border-amber-200/20';
                                                                        $rankIcon = 'trophy';
                                                                    }
                                                                @endphp
                                                                <div class="flex items-center justify-between p-2 rounded-xl text-xs {{ $rankColor }}">
                                                                    <span class="font-bold flex items-center gap-1">
                                                                        <flux:icon :icon="$rankIcon" class="size-3.5 shrink-0" />
                                                                        {{ $winner->rank->name }}
                                                                    </span>
                                                                    <span class="font-black text-zinc-950 dark:text-white" style="color: {{ $winner->team->color }}">
                                                                        {{ $winner->team->name }}
                                                                    </span>
                                                                </div>
                                                            @endforeach
                                                        </div>
                                                    @endif
                                                </div>
                                            </div>

                                            <!-- Actions -->
                                            <div class="pt-4 border-t border-zinc-100 dark:border-zinc-800/60 mt-4 flex items-center justify-between gap-2">
                                                @if($round->winners->isEmpty())
                                                    <flux:button wire:click="editRoundWinners({{ $round->id }})" size="sm" variant="filled" class="bg-purple-600 hover:bg-purple-700 text-white font-bold border-none w-full py-1.5 rounded-xl shadow-sm">
                                                        رصد المراكز
                                                    </flux:button>
                                                @else
                                                    <flux:button wire:click="editRoundWinners({{ $round->id }})" size="sm" variant="ghost" class="text-purple-600 dark:text-purple-400 hover:bg-purple-50 dark:hover:bg-purple-900/20 font-bold w-full py-1.5 rounded-xl border border-purple-200/30 dark:border-purple-800/30">
                                                        تعديل المراكز
                                                    </flux:button>
                                                @endif
                                            </div>
                                        </flux:card>
                                    @endforeach
                                </div>
                            </div>
                        @empty
                            <div class="text-center py-12 text-zinc-500 bg-zinc-50 dark:bg-zinc-800/10 rounded-2xl border border-dashed border-zinc-300 dark:border-zinc-700">
                                لا توجد جولات مجدولة حالياً. الرجاء إضافة جولات داخل إعدادات الفعالية في الجدول أعلاه لتظهر هنا.
                            </div>
                        @endforelse
                    </div>
                </div>
            </div>
        @endif

        {{-- TAB: ADJUSTMENTS --}}
        @if($activeTab === 'adjustments')
            <div class="space-y-8">
                <div class="space-y-6 max-w-3xl">
                    <div>
                        <flux:heading size="lg">التسويات اليدوية (إضافة وخصم)</flux:heading>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <!-- Target Type Selector -->
                        <div class="space-y-2">
                            <label class="text-sm font-semibold text-zinc-800 dark:text-zinc-200">فئة المستهدف</label>
                            <div class="flex items-center gap-2">
                                <button type="button" wire:click="$set('adjTargetType', 'individual')" class="flex-1 py-2 px-4 rounded-xl border text-center transition-all text-sm font-semibold {{ $adjTargetType === 'individual' ? 'bg-purple-50 border-purple-300 text-purple-700 dark:bg-purple-950/30 dark:border-purple-800 dark:text-purple-400' : 'bg-white border-zinc-200 text-zinc-700 dark:bg-zinc-900 dark:border-zinc-800 dark:text-zinc-300' }}">
                                    الأفراد (الطلاب)
                                </button>
                                <button type="button" wire:click="$set('adjTargetType', 'team')" class="flex-1 py-2 px-4 rounded-xl border text-center transition-all text-sm font-semibold {{ $adjTargetType === 'team' ? 'bg-purple-50 border-purple-300 text-purple-700 dark:bg-purple-950/30 dark:border-purple-800 dark:text-purple-400' : 'bg-white border-zinc-200 text-zinc-700 dark:bg-zinc-900 dark:border-zinc-800 dark:text-zinc-300' }}">
                                    المجموعات (الأسر)
                                </button>
                            </div>
                        </div>

                        <!-- Action Type Selector -->
                        <div class="space-y-2">
                            <label class="text-sm font-semibold text-zinc-800 dark:text-zinc-200 font-bold">نوع العملية</label>
                            <div class="flex items-center gap-2">
                                <button type="button" wire:click="$set('adjActionType', 'add')" class="flex-1 py-2 px-4 rounded-xl border text-center transition-all text-sm font-semibold {{ $adjActionType === 'add' ? 'bg-emerald-50 border-emerald-300 text-emerald-700 dark:bg-emerald-950/30 dark:border-emerald-800 dark:text-emerald-400' : 'bg-white border-zinc-200 text-zinc-700 dark:bg-zinc-900 dark:border-zinc-800 dark:text-zinc-300' }}">
                                    إضافة
                                </button>
                                <button type="button" wire:click="$set('adjActionType', 'deduct')" class="flex-1 py-2 px-4 rounded-xl border text-center transition-all text-sm font-semibold {{ $adjActionType === 'deduct' ? 'bg-rose-50 border-rose-300 text-rose-700 dark:bg-rose-950/30 dark:border-rose-800 dark:text-rose-400' : 'bg-white border-zinc-200 text-zinc-700 dark:bg-zinc-900 dark:border-zinc-800 dark:text-zinc-300' }}">
                                    خصم
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Target Select -->
                    @if($adjTargetType === 'individual')
                        <div class="space-y-2">
                            <label class="text-sm font-semibold text-zinc-800 dark:text-zinc-200">الطالب المستهدف</label>
                            <select wire:model="adjStudentId" class="w-full rounded-xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 px-3 py-2.5 text-zinc-800 dark:text-zinc-200 shadow-sm focus:border-purple-500 focus:ring-purple-500 text-sm">
                                <option value="">اختر طالباً...</option>
                                @foreach($studentsGrouped as $circleName => $circleStudents)
                                    <optgroup label="حلقة: {{ $circleName }}">
                                        @foreach($circleStudents as $std)
                                            <option value="{{ $std->id }}">{{ $std->name }}</option>
                                        @endforeach
                                    </optgroup>
                                @endforeach
                            </select>
                            @error('adjStudentId')
                                <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span>
                            @enderror
                        </div>
                    @else
                        <div class="space-y-2">
                            <label class="text-sm font-semibold text-zinc-800 dark:text-zinc-200">المجموعة المستهدفة</label>
                            <select wire:model="adjTeamId" class="w-full rounded-xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 px-3 py-2.5 text-zinc-800 dark:text-zinc-200 shadow-sm focus:border-purple-500 focus:ring-purple-500 text-sm">
                                <option value="">اختر مجموعة/أسرة...</option>
                                @foreach($dbTeams as $team)
                                    <option value="{{ $team->id }}">{{ $team->name }}</option>
                                @endforeach
                            </select>
                            @error('adjTeamId')
                                <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span>
                            @enderror
                        </div>
                    @endif

                    <!-- Values inputs -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <!-- XP -->
                        <div class="p-4 rounded-xl border border-zinc-200 dark:border-zinc-800 bg-zinc-50/50 dark:bg-zinc-800/10 space-y-3">
                            <div class="flex items-center justify-between">
                                <span class="text-sm font-semibold text-zinc-800 dark:text-zinc-200">نقاط الخبرة (XP)</span>
                                <flux:switch wire:model.live="adjHasXp" />
                            </div>
                            @if($adjHasXp)
                                <flux:input type="number" min="1" wire:model="adjXpVal" placeholder="قيمة النقاط" required />
                            @endif
                        </div>

                        <!-- Coins -->
                        <div class="p-4 rounded-xl border border-zinc-200 dark:border-zinc-800 bg-zinc-50/50 dark:bg-zinc-800/10 space-y-3">
                            <div class="flex items-center justify-between">
                                <span class="text-sm font-semibold text-zinc-800 dark:text-zinc-200">العملات / العمولة</span>
                                <flux:switch wire:model.live="adjHasCoins" />
                            </div>
                            @if($adjHasCoins)
                                <flux:input type="number" min="1" wire:model="adjCoinsVal" placeholder="قيمة العملات" required />
                            @endif
                        </div>
                    </div>
                    @error('adjHasXp')
                        <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span>
                    @enderror

                    <!-- Description -->
                    <div class="space-y-2">
                        <flux:input label="السبب" wire:model="adjDescription" placeholder="أدخل سبب إجراء هذا التعديل اليدوي..." required />
                    </div>

                    <!-- Show in news (default off) -->
                    <div class="p-3 rounded-xl border border-zinc-200 dark:border-zinc-800 bg-zinc-50/50 dark:bg-zinc-800/20 space-y-3">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <span class="text-sm font-bold text-zinc-800 dark:text-zinc-200">إظهار التسوية في الأخبار</span>
                                <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-0.5">افتراضياً لا تظهر التسوية في موجز أخبار الطلاب.</p>
                            </div>
                            <flux:switch wire:model.live="adjShowInNews" />
                        </div>

                        @if($adjShowInNews)
                            <div class="flex items-center justify-between gap-3 pt-3 border-t border-zinc-200 dark:border-zinc-700">
                                <div>
                                    <span class="text-sm font-bold text-zinc-800 dark:text-zinc-200">إظهار اسم {{ $adjTargetType === 'individual' ? 'الطالب' : 'الأسرة' }} في الخبر</span>
                                    <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-0.5">عند الإخفاء يظهر الخبر باسم مجهول ({{ $adjTargetType === 'individual' ? 'أحد الطلاب' : 'إحدى الأسر' }}).</p>
                                </div>
                                <flux:switch wire:model="adjShowTargetName" />
                            </div>
                        @endif
                    </div>

                    <!-- Submit -->
                    <div class="flex justify-end">
                        <flux:button wire:click="applyAdjustment" variant="primary" class="bg-purple-600 hover:bg-purple-700 text-white border-none">
                            تطبيق التسوية
                        </flux:button>
                    </div>
                </div>

                <hr class="border-zinc-200 dark:border-zinc-800" />

                <!-- Adjustments History -->
                <div class="space-y-4">
                    <flux:heading size="md">سجل التسويات اليدوية السابقة</flux:heading>
                    <div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 shadow-sm">
                        <table class="w-full text-sm text-right text-zinc-500 dark:text-zinc-400">
                            <thead class="text-xs text-zinc-700 dark:text-zinc-300 bg-zinc-50 dark:bg-zinc-800/50 border-b border-zinc-200 dark:border-zinc-800">
                                <tr>
                                    <th class="px-6 py-3">المستهدف</th>
                                    <th class="px-6 py-3">نوع العملية</th>
                                    <th class="px-6 py-3">التعديلات</th>
                                    <th class="px-6 py-3">السبب</th>
                                    <th class="px-6 py-3">التاريخ</th>
                                    <th class="px-6 py-3 text-center">الإجراءات</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                                @forelse($dbAdjustments as $adj)
                                    <tr class="hover:bg-zinc-50/50 dark:hover:bg-zinc-800/10">
                                        <td class="px-6 py-4 font-bold text-zinc-900 dark:text-zinc-100">
                                            @if($adj->student)
                                                <div class="flex flex-col">
                                                    <span>{{ $adj->student->name }}</span>
                                                    <span class="text-[10px] text-zinc-400 font-medium">حلقة: {{ $adj->student->circle?->name ?? 'بدون حلقة' }}</span>
                                                </div>
                                            @elseif($adj->team)
                                                <div class="flex items-center gap-1.5">
                                                    <span class="size-2 rounded-full" style="background-color: {{ $adj->team->color }}"></span>
                                                    <span>{{ $adj->team->name }} (أسرة)</span>
                                                </div>
                                            @else
                                                <span class="text-zinc-400">-</span>
                                            @endif
                                        </td>
                                        <td class="px-6 py-4">
                                            @if(($adj->amount > 0) || ($adj->xp_amount > 0))
                                                <span class="inline-flex items-center bg-emerald-50 dark:bg-emerald-950/20 text-emerald-700 dark:text-emerald-400 text-xs px-2 py-0.5 rounded-md font-bold">
                                                    إضافة
                                                </span>
                                            @else
                                                <span class="inline-flex items-center bg-rose-50 dark:bg-rose-950/20 text-rose-700 dark:text-rose-400 text-xs px-2 py-0.5 rounded-md font-bold">
                                                    خصم
                                                </span>
                                            @endif
                                        </td>
                                        <td class="px-6 py-4 text-xs font-semibold">
                                            <div class="flex flex-col gap-1">
                                                @if($adj->xp_amount != 0)
                                                    <span class="{{ $adj->xp_amount > 0 ? 'text-emerald-600' : 'text-rose-600' }}">
                                                        {{ $adj->xp_amount > 0 ? '+' : '' }}{{ $adj->xp_amount }} XP
                                                    </span>
                                                @endif
                                                @if($adj->amount != 0)
                                                    <span class="{{ $adj->amount > 0 ? 'text-emerald-600' : 'text-rose-600' }}">
                                                        {{ $adj->amount > 0 ? '+' : '' }}{{ $adj->amount }} عملة
                                                    </span>
                                                @endif
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 text-xs">
                                            {{ str_replace(['تسوية يدوية (من المشرف): ', 'تسوية يدوية للأسرة (من المشرف): '], '', $adj->description) }}
                                        </td>
                                        <td class="px-6 py-4 text-xs text-zinc-400">
                                            <x-hijri-date :date="$adj->created_at" style="withTime" />
                                        </td>
                                        <td class="px-6 py-4 text-center">
                                            <flux:button wire:click="deleteAdjustment({{ $adj->id }})" wire:confirm="هل تريد حذف وتراجع هذه التسوية؟" size="xs" variant="ghost" class="text-rose-500 hover:text-rose-600">
                                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-4">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 15L3 9m0 0l6-6M3 9h12a6 6 0 010 12h-3" />
                                                </svg>
                                            </flux:button>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="px-6 py-8 text-center text-zinc-500 dark:text-zinc-400">
                                            لا توجد تسويات مسجلة حالياً.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        @endif

        {{-- TAB: COIN REDEMPTION LINKS --}}
        @if($activeTab === 'redemption')
            @php
                $redemptionLinks = $competition->circles->map(fn ($circle) => [
                    'circle' => $circle,
                    'url' => \Illuminate\Support\Facades\URL::signedRoute('redemption.circle', [
                        'leaderboard' => $competition->id,
                        'circle' => $circle->id,
                    ]),
                ]);

                $allRedemptionLinksText = "🪙 روابط صرف العملات — مسابقة {$competition->title}\n"
                    ."كل رابط أدناه خاص بحلقة واحدة: يفتح منه معلم الحلقة قائمة طلابه وأرصدتهم من العملات، ويصرف لهم عملاتهم مقابل الجوائز والعلامات الورقية.\n"
                    ."أرسِل لكل معلم رابط حلقته فقط.\n\n"
                    .$redemptionLinks->map(fn ($link) => "⦿ حلقة {$link['circle']->name}:\n{$link['url']}")->implode("\n\n")
                    ."\n\n⚠️ تنبيه مهم: هذه الروابط خاصة وتتيح الصرف من أرصدة الطلاب مباشرة، لذلك يجب عدم مشاركتها مع أي شخص غير معلم الحلقة المعني.";
            @endphp

            <div class="space-y-6">
                <div class="flex flex-col md:flex-row md:items-center justify-between gap-3">
                    <div>
                        <flux:heading size="lg">روابط صرف العملات</flux:heading>
                        <flux:subheading>
                            لكل حلقة رابط خاص أرسله لمعلمها؛ يفتح المعلم الرابط فيرى طلاب حلقته وأرصدتهم من العملات،
                            ويصرف لأي طالب عملاته مقابل جائزة أو علامات ورقية يسلّمها له.
                        </flux:subheading>
                    </div>
                    @if($redemptionLinks->isNotEmpty())
                        <div class="shrink-0" x-data>
                            <flux:button variant="primary" icon="clipboard-document-list"
                                class="bg-purple-600 hover:bg-purple-700 border-none text-white"
                                @click="navigator.clipboard.writeText(@js($allRedemptionLinksText)); $dispatch('toast', { message: 'تم نسخ جميع روابط الصرف في نص واحد', variant: 'success' })">
                                نسخ جميع الروابط
                            </flux:button>
                        </div>
                    @endif
                </div>

                <div class="space-y-3">
                    @forelse($redemptionLinks as $link)
                        @php
                            $circle = $link['circle'];
                            $redemptionUrl = $link['url'];
                        @endphp
                        <div class="flex flex-col md:flex-row md:items-center justify-between gap-3 p-4 border border-zinc-200 dark:border-zinc-700/50 rounded-xl bg-zinc-50 dark:bg-zinc-800/50"
                            wire:key="redemption-circle-{{ $circle->id }}">
                            <div class="min-w-0">
                                <div class="font-medium text-zinc-800 dark:text-white">{{ $circle->name }}</div>
                                <div class="text-xs text-zinc-500 mt-1">{{ $circle->students->count() }} طالباً</div>
                            </div>
                            <div class="flex items-center gap-2 shrink-0" x-data>
                                <flux:button size="sm" variant="primary" icon="link"
                                    class="bg-purple-600 hover:bg-purple-700 border-none text-white"
                                    @click="navigator.clipboard.writeText(@js($redemptionUrl)); $dispatch('toast', { message: 'تم نسخ رابط صرف حلقة {{ $circle->name }}', variant: 'success' })">
                                    نسخ الرابط
                                </flux:button>
                                <flux:button size="sm" variant="ghost" icon="arrow-top-right-on-square"
                                    href="{{ $redemptionUrl }}" target="_blank">
                                    فتح
                                </flux:button>
                            </div>
                        </div>
                    @empty
                        <div class="text-center py-10 text-sm text-zinc-500">
                            لا توجد حلقات مرتبطة بهذه المسابقة. اربط الحلقات بالمسابقة أولاً ثم عد إلى هنا.
                        </div>
                    @endforelse
                </div>
            </div>
        @endif

        {{-- TAB: STORE --}}
        @if($activeTab === 'store')
            <div class="space-y-6">
                <div class="flex justify-between items-center">
                    <div>
                        <flux:heading size="lg">متجر جوائز التحدي (Store Editor)</flux:heading>
                        <flux:subheading>أضف عناصر وجوائز المتجر التي يمكن للطلاب أو الفرق شراؤها بواسطة عملاتهم.</flux:subheading>
                    </div>
                    <flux:button wire:click="createItem" variant="primary" icon="plus" class="bg-purple-600 hover:bg-purple-700 border-none text-white">إضافة جائزة جديدة</flux:button>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    @forelse($dbStoreItems as $item)
                        <flux:card class="flex flex-col justify-between border border-zinc-200 dark:border-zinc-800 relative overflow-hidden">
                            <div class="space-y-3">
                                <div class="flex justify-between items-start">
                                    <div class="p-2 rounded-xl bg-purple-50 dark:bg-purple-900/30 text-purple-600 dark:text-purple-400">
                                        @if($item->item_type === 'multiplier')
                                            <flux:icon icon="bolt" class="size-6" />
                                        @elseif($item->item_type === 'shield')
                                            <flux:icon icon="shield-check" class="size-6" />
                                        @elseif($item->item_type === 'team_points')
                                            <flux:icon icon="plus-circle" class="size-6" />
                                        @else
                                            <flux:icon icon="gift" class="size-6" />
                                        @endif
                                    </div>
                                    <flux:badge size="sm" color="amber" class="font-bold">
                                        {{ $item->price }} {{ $theme['currency_name'] }}
                                    </flux:badge>
                                </div>
                                <div>
                                    <div class="flex items-center justify-between gap-2">
                                        <h4 class="font-bold text-zinc-900 dark:text-zinc-100">{{ $item->name }}</h4>
                                        @if(!$item->is_active)
                                            <flux:badge size="sm" color="zinc" icon="eye-slash" class="text-[10px]">معطل</flux:badge>
                                        @else
                                            <flux:badge size="sm" color="success" icon="eye" class="text-[10px]">نشط</flux:badge>
                                        @endif
                                    </div>
                                    <p class="text-xs text-purple-600 dark:text-purple-400 mt-0.5">
                                        نوع الميزة: 
                                        @if($item->item_type === 'freeze') تجميد (فردي) @elseif($item->item_type === 'team_attack') خصم على مجموعة (جماعي) @elseif($item->item_type === 'shield') درع حماية ليوم (جماعي) @elseif($item->item_type === 'multiplier') @if($item->is_team_product) مضاعفة النقاط ليوم (جماعي) @else مضاعفة النقاط ليوم (فردي) @endif @elseif($item->item_type === 'team_points') إضافة نقاط للفريق (جماعي) @else مخصصة @endif
                                    </p>
                                    <p class="text-sm text-zinc-500 mt-2">{{ $item->description ?: 'بدون وصف' }}</p>
                                </div>
                            </div>
                            <div class="flex justify-between items-center mt-4 pt-3 border-t border-zinc-100 dark:border-zinc-800">
                                <span class="text-xs text-zinc-400">
                                    @if(in_array($item->item_type, ['shield', 'multiplier']))
                                        @if($item->target_date)
                                            تاريخ اليوم: <x-hijri-date :date="$item->target_date" />
                                        @else
                                            تاريخ التفعيل: محدد عند الشراء
                                        @endif
                                    @elseif($item->item_type === 'team_attack')
                                        الخصم: {{ $item->value }} نقاط
                                    @elseif($item->item_type === 'team_points')
                                        الإضافة: {{ $item->value }} نقاط
                                    @else
                                        القيمة: {{ $item->value }}
                                    @endif
                                </span>
                                <div class="flex gap-2 items-center">
                                    @if($item->is_active)
                                        <flux:button wire:click="toggleProductStatus({{ $item->id }})" size="xs" variant="ghost" icon="eye-slash" class="text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300">تعطيل</flux:button>
                                    @else
                                        <flux:button wire:click="toggleProductStatus({{ $item->id }})" size="xs" variant="ghost" icon="eye" class="text-emerald-600 hover:text-emerald-700 dark:text-emerald-400 dark:hover:text-emerald-300">تفعيل</flux:button>
                                    @endif

                                    @if((($item->item_type === 'multiplier' || $item->item_type === 'freeze') && !$item->is_team_product))
                                        <span class="text-xs text-zinc-400 font-semibold flex items-center gap-1">
                                            <flux:icon icon="lock-closed" class="size-3" /> منتج ثابت
                                        </span>
                                        <flux:button wire:click="editItem({{ $item->id }})" size="xs" variant="ghost" icon="pencil-square">تعديل</flux:button>
                                    @else
                                        <flux:button wire:click="editItem({{ $item->id }})" size="xs" variant="ghost" icon="pencil-square">تعديل</flux:button>
                                        <flux:button wire:click="deleteItem({{ $item->id }})" wire:confirm="هل تريد حذف هذا العنصر من المتجر؟" size="xs" variant="ghost" icon="trash" class="text-rose-500 hover:text-rose-600" />
                                    @endif
                                </div>
                            </div>
                        </flux:card>
                    @empty
                        <div class="col-span-3 text-center py-12 text-zinc-500 border border-dashed border-zinc-200 dark:border-zinc-800 rounded-xl">
                            لا توجد جوائز حالية بالمتجر. أضف أول جائزة الآن!
                        </div>
                    @endforelse
                </div>

                {{-- Purchases log: cancel a made purchase and reverse its effect --}}
                <div class="pt-4 border-t border-zinc-200 dark:border-zinc-800 space-y-4">
                    <div>
                        <flux:heading size="lg">سجل المشتريات</flux:heading>
                        <flux:subheading>يمكنك إلغاء أي عملية شراء لاسترداد العملات وإلغاء تأثيرها (التجميد، المضاعفة الفردية أو الجماعية، الدرع، الهجوم، أو دعم الفريق) حتى لو تم استخدامها.</flux:subheading>
                    </div>

                    @php
                        $purchaseTypeLabel = function ($item) {
                            if ($item->is_streak_freeze || $item->item_type === 'freeze') return 'تجميد (فردي)';
                            if ($item->item_type === 'multiplier') return $item->is_team_product ? 'مضاعفة النقاط (جماعي)' : 'مضاعفة النقاط (فردي)';
                            if ($item->item_type === 'shield') return 'درع حماية (جماعي)';
                            if ($item->item_type === 'team_points') return 'دعم الفريق بالنقاط (جماعي)';
                            if ($item->item_type === 'team_attack') return 'هجوم خصم النقاط (جماعي)';
                            return 'مخصصة';
                        };
                    @endphp

                    @forelse($purchasesByProduct as $group)
                        @php
                            $item = $group['item'];
                            $purchases = $group['purchases'];
                            $activeCount = $purchases->whereIn('status', ['approved', 'pending_approval'])->count();
                        @endphp
                        <div class="rounded-xl border border-zinc-200 dark:border-zinc-800 overflow-hidden">
                            {{-- Product header --}}
                            <div class="flex items-center justify-between gap-3 p-4 bg-zinc-50 dark:bg-zinc-900/60 border-b border-zinc-200 dark:border-zinc-800">
                                <div class="flex items-center gap-2 flex-wrap min-w-0">
                                    <span class="font-bold text-zinc-900 dark:text-zinc-100 truncate">{{ $item->name }}</span>
                                    <flux:badge size="sm" color="purple">{{ $purchaseTypeLabel($item) }}</flux:badge>
                                </div>
                                <div class="flex items-center gap-2 shrink-0">
                                    <flux:badge size="sm" color="zinc">{{ $purchases->count() }} عملية</flux:badge>
                                    @if($activeCount > 0)
                                        <flux:badge size="sm" color="success">{{ $activeCount }} فعّالة</flux:badge>
                                    @endif
                                </div>
                            </div>

                            {{-- Purchases of this product, newest date first --}}
                            <div class="divide-y divide-zinc-100 dark:divide-zinc-800">
                                @foreach($purchases as $purchase)
                                    <div class="flex flex-col sm:flex-row sm:items-center gap-3 p-4 bg-white dark:bg-zinc-900/40">
                                        <div class="flex-1 min-w-0 space-y-1">
                                            <div class="flex items-center gap-2 flex-wrap">
                                                @if($purchase->status === 'pending_approval')
                                                    <flux:badge size="sm" color="amber">بانتظار التصويت</flux:badge>
                                                @elseif($purchase->status === 'cancelled')
                                                    <flux:badge size="sm" color="zinc" icon="x-mark">ملغاة</flux:badge>
                                                @elseif($purchase->status === 'rejected')
                                                    <flux:badge size="sm" color="rose">مرفوضة</flux:badge>
                                                @else
                                                    <flux:badge size="sm" color="success">معتمدة</flux:badge>
                                                @endif
                                                @if($purchase->target_date)
                                                    <span class="text-xs font-semibold text-zinc-700 dark:text-zinc-300 flex items-center gap-1">
                                                        <flux:icon icon="calendar" class="size-3.5" />
                                                        <x-hijri-date :date="\Carbon\Carbon::parse($purchase->target_date)" />
                                                    </span>
                                                @endif
                                            </div>
                                            <p class="text-xs text-zinc-500 flex items-center gap-x-3 gap-y-1 flex-wrap">
                                                @if($purchase->team)
                                                    <span>الفريق: {{ $purchase->team->name }}</span>
                                                @endif
                                                @if($purchase->student)
                                                    <span>المشتري: {{ $purchase->student->name }}</span>
                                                @endif
                                                @if($purchase->targetTeam)
                                                    <span>الفريق المستهدف: {{ $purchase->targetTeam->name }}</span>
                                                @endif
                                                <span class="text-amber-600 dark:text-amber-400 font-semibold">{{ $purchase->price_paid }} {{ $theme['currency_name'] }}</span>
                                                <span>{{ $purchase->created_at->diffForHumans() }}</span>
                                            </p>
                                        </div>
                                        <div class="shrink-0">
                                            @if(in_array($purchase->status, ['approved', 'pending_approval']))
                                                <flux:button
                                                    wire:click="cancelPurchase({{ $purchase->id }})"
                                                    wire:confirm="هل أنت متأكد من إلغاء هذه العملية؟ ستُعاد العملات المدفوعة ({{ $purchase->price_paid }}) ويُلغى تأثيرها بالكامل."
                                                    size="xs" variant="ghost" icon="x-circle"
                                                    class="text-rose-500 hover:text-rose-600">إلغاء وإعادة العملات</flux:button>
                                            @else
                                                <span class="text-xs text-zinc-400">—</span>
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @empty
                        <div class="text-center py-8 text-zinc-500 border border-dashed border-zinc-200 dark:border-zinc-800 rounded-xl">
                            لا توجد مشتريات بعد.
                        </div>
                    @endforelse
                </div>
            </div>
        @endif

    </div>

    {{-- MODAL: STREAK MILESTONE --}}
    <flux:modal wire:model="showMilestoneModal" class="md:w-[500px]">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ $editingMilestoneId ? 'تعديل مكافأة الحماسة' : 'إضافة مكافأة حماسة جديدة' }}</flux:heading>
                <flux:subheading>حدد عدد أيام المتتالية والمكافآت الممنوحة عند تحقيقها.</flux:subheading>
            </div>

            <div class="space-y-4">
                <flux:input type="number" wire:model="days_required" label="الأيام المتتالية المطلوبة" placeholder="مثل: 5, 10, 30" required />
                <flux:input type="number" wire:model="reward_xp" label="نقاط طاقة الخبرة الممنوحة (XP)" placeholder="مثال: 50" required />
                <flux:input type="number" wire:model="reward_coins" label="العملات الممنوحة" placeholder="مثال: 100" required />
                
                <flux:select label="وسام التكريم المقترن (اختياري)" wire:model="reward_badge_id" placeholder="اختر وساماً إذا وجد...">
                    <flux:select.option value="">بدون وسام</flux:select.option>
                    @foreach($dbBadges as $badge)
                        <flux:select.option value="{{ $badge->id }}">{{ $badge->name }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:input wire:model="milestone_description" label="الوصف" placeholder="مثال: جائزة الحماسة الفضية" />
            </div>

            <div class="flex justify-end gap-2">
                <flux:button wire:click="$set('showMilestoneModal', false)" variant="ghost">إلغاء</flux:button>
                <flux:button wire:click="saveMilestone" variant="primary" class="bg-purple-600 hover:bg-purple-700 border-none text-white">حفظ</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- MODAL: BADGE --}}
    <flux:modal wire:model="showBadgeModal" class="md:w-[500px]">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ $editingBadgeId ? 'تعديل الوسام' : 'إنشاء وسام جديد' }}</flux:heading>
                <flux:subheading>أدخل بيانات الوسام وشروطه ليتمكن الطلاب من التنافس لكسبه.</flux:subheading>
            </div>

            {{-- Step Indicator --}}
            <div class="flex items-center justify-between pb-4 border-b border-zinc-100 dark:border-zinc-800">
                <div class="flex items-center gap-2">
                    <span class="flex items-center justify-center size-6 rounded-full text-xs font-bold {{ $badgeStep >= 1 ? 'bg-purple-600 text-white' : 'bg-zinc-100 text-zinc-500' }}">1</span>
                    <span class="text-xs font-medium {{ $badgeStep === 1 ? 'text-zinc-900 dark:text-zinc-100 font-semibold' : 'text-zinc-400' }}">المعلومات الأساسية</span>
                </div>
                <div class="flex-1 h-px bg-zinc-200 mx-4"></div>
                <div class="flex items-center gap-2">
                    <span class="flex items-center justify-center size-6 rounded-full text-xs font-bold {{ $badgeStep >= 2 ? 'bg-purple-600 text-white' : 'bg-zinc-100 text-zinc-500' }}">2</span>
                    <span class="text-xs font-medium {{ $badgeStep === 2 ? 'text-zinc-900 dark:text-zinc-100 font-semibold' : 'text-zinc-400' }}">صورة الوسام</span>
                </div>
                <div class="flex-1 h-px bg-zinc-200 mx-4"></div>
                <div class="flex items-center gap-2">
                    <span class="flex items-center justify-center size-6 rounded-full text-xs font-bold {{ $badgeStep >= 3 ? 'bg-purple-600 text-white' : 'bg-zinc-100 text-zinc-500' }}">3</span>
                    <span class="text-xs font-medium {{ $badgeStep === 3 ? 'text-zinc-900 dark:text-zinc-100 font-semibold' : 'text-zinc-400' }}">شروط الاستحقاق</span>
                </div>
            </div>

            @if($badgeStep === 1)
                {{-- STEP 1: Basic Info --}}
                <div class="space-y-4">
                    <flux:input wire:model="badge_name" label="اسم الوسام" placeholder="مثال: وسام التسميع المتميز" required />
                    <flux:textarea wire:model="badge_description" label="الوصف" placeholder="اشرح للطلاب كيفية كسب هذا الوسام" />
                    
                    <div class="grid grid-cols-2 gap-4">
                        <flux:input type="number" wire:model="badge_reward_xp" label="مكافأة نقاط الخبرة (XP)" placeholder="0" min="0" required />
                        <flux:input type="number" wire:model="badge_reward_coins" label="مكافأة العملات" placeholder="0" min="0" required />
                    </div>
                </div>

                <div class="flex justify-end gap-2 pt-4">
                    <flux:button wire:click="$set('showBadgeModal', false)" variant="ghost">إلغاء</flux:button>
                    <flux:button wire:click="nextBadgeStep" variant="primary" class="bg-purple-600 hover:bg-purple-700 border-none text-white">الخطوة التالية</flux:button>
                </div>
            @elseif($badgeStep === 2)
                {{-- STEP 2: Badge Image --}}
                <div class="space-y-4">
                    <div>
                        <flux:input type="file" label="صورة الوسام" wire:model="badge_image_file" accept="image/*" />
                        <div class="mt-2 text-xs text-zinc-500">اختر صورة مناسبة للوسام. سيتم تحويلها تلقائياً إلى صيغة WebP وتقليص حجمها.</div>
                        
                        @if ($badge_image_file)
                            <div class="mt-3 p-3 border border-dashed rounded-xl border-purple-200 dark:border-purple-800 bg-purple-50/20 dark:bg-purple-950/10 flex flex-col items-center gap-2">
                                <span class="text-xs text-purple-600 dark:text-purple-400 font-medium">معاينة الصورة المرفوعة:</span>
                                <img src="{{ $badge_image_file->temporaryUrl() }}" class="size-16 object-contain rounded-lg border shadow-sm bg-white dark:bg-zinc-800" />
                            </div>
                        @elseif ($editingBadgeId && $badge_icon && (str_contains($badge_icon, '/') || str_contains($badge_icon, '.')))
                            <div class="mt-3 p-3 border border-dashed rounded-xl border-zinc-200 dark:border-zinc-800 bg-zinc-50 dark:bg-zinc-900/50 flex flex-col items-center gap-2">
                                <span class="text-xs text-zinc-500 font-medium">الصورة الحالية للوسام:</span>
                                <img src="{{ asset('storage/' . $badge_icon) }}" class="size-16 object-contain rounded-lg border shadow-sm bg-white dark:bg-zinc-800" />
                            </div>
                        @endif
                    </div>
                </div>

                <div class="flex justify-end gap-2 pt-4">
                    <flux:button wire:click="prevBadgeStep" variant="ghost">السابق</flux:button>
                    <flux:button wire:click="nextBadgeStep" variant="primary" class="bg-purple-600 hover:bg-purple-700 border-none text-white">الخطوة التالية</flux:button>
                </div>
            @elseif($badgeStep === 3)
                {{-- STEP 3: Earning Conditions --}}
                <div class="space-y-4">
                    <flux:select label="طريقة الاحتساب" wire:model.live="badge_mechanism">
                        <flux:select.option value="manual">توزيع يدوي / مخصص</flux:select.option>
                        <flux:select.option value="streak">إنجاز متتالي (Streak)</flux:select.option>
                        <flux:select.option value="count">تراكمي بالعدد غير المشروط بالتتالي (Cumulative)</flux:select.option>
                    </flux:select>

                    @if($badge_mechanism !== 'manual')
                        <flux:select label="نوع الإنجاز" wire:model.live="badge_achievement_type">
                            <flux:select.option value="">اختر نوع الإنجاز...</flux:select.option>
                            <flux:select.option value="attendance">حضور الحلقة</flux:select.option>
                            <flux:select.option value="hifz">حفظ القرآن</flux:select.option>
                            <flux:select.option value="review">مراجعة القرآن</flux:select.option>
                            <flux:select.option value="criterion">بند تقييم مخصص</flux:select.option>
                        </flux:select>

                        @if($badge_achievement_type === 'criterion')
                            <flux:select label="بند التقييم المرتبط (تلقائي)" wire:model="badge_leaderboard_criterion_id" placeholder="اختر بند تقييم لربطه تلقائياً">
                                <flux:select.option value="">اختر البند المخصص...</flux:select.option>
                                @foreach($dbCriteria as $criterion)
                                    <flux:select.option value="{{ $criterion->id }}">{{ $criterion->name }} ({{ $criterion->points }} نقاط)</flux:select.option>
                                @endforeach
                            </flux:select>
                        @endif

                        <flux:input type="number" wire:model="badge_requirement_value" label="العدد/الأيام المطلوبة" placeholder="مثال: 7" required />
                    @endif
                </div>

                <div class="flex justify-end gap-2 pt-4">
                    <flux:button wire:click="prevBadgeStep" variant="ghost">السابق</flux:button>
                    <flux:button wire:click="saveBadge" variant="primary" class="bg-purple-600 hover:bg-purple-700 border-none text-white">حفظ</flux:button>
                </div>
            @endif
        </div>
    </flux:modal>

    {{-- MODAL: HAND OUT A MANUAL BADGE --}}
    <flux:modal wire:model="showGrantBadgeModal" class="md:w-[560px]">
        <div class="space-y-5 text-right" dir="rtl">
            <div>
                <flux:heading size="lg" class="font-bold text-zinc-950 dark:text-white">توزيع الوسام</flux:heading>
                <flux:subheading class="text-zinc-500">
                    اختر الطلاب الذين يستحقون الوسام. يصلهم معتمداً، ويظهر لهم زر الاستلام في لوحتهم.
                </flux:subheading>
            </div>

            <flux:input wire:model.live.debounce.300ms="grantSearch" placeholder="ابحث باسم الطالب" icon="magnifying-glass" />

            <div class="max-h-[45vh] overflow-y-auto rounded-xl border border-zinc-200 dark:border-zinc-700 divide-y divide-zinc-100 dark:divide-zinc-800">
                @forelse($this->grantCandidates as $row)
                    @php
                        $student = $row['student'];
                        $status = $row['status'];
                    @endphp
                    <label class="flex items-center justify-between gap-3 px-3 py-2.5 {{ $status ? 'opacity-60' : 'cursor-pointer hover:bg-zinc-50 dark:hover:bg-zinc-800/50' }}"
                        wire:key="grant-{{ $student->id }}">
                        <div class="flex items-center gap-2.5 min-w-0">
                            <input type="checkbox" wire:model="grantStudentIds" value="{{ $student->id }}"
                                @disabled($status)
                                class="rounded border-zinc-300 text-purple-600 focus:ring-purple-500 shrink-0">
                            <span class="text-sm text-zinc-800 dark:text-zinc-100 truncate">{{ $student->name }}</span>
                        </div>

                        @if($status === 'claimed')
                            <flux:badge size="sm" color="green">استلمه</flux:badge>
                        @elseif($status)
                            <div class="flex items-center gap-1.5 shrink-0">
                                <flux:badge size="sm" color="amber">بانتظار الاستلام</flux:badge>
                                <flux:button type="button" size="xs" variant="ghost" icon="x-mark" class="text-rose-500"
                                    wire:click="revokeBadge({{ $grantingBadgeId }}, {{ $student->id }})" />
                            </div>
                        @endif
                    </label>
                @empty
                    <div class="px-3 py-10 text-center text-sm text-zinc-400">لا يوجد طلاب مطابقون</div>
                @endforelse
            </div>

            <flux:error name="grantStudentIds" />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">إلغاء</flux:button>
                </flux:modal.close>
                <flux:button wire:click="grantBadge" variant="primary" class="bg-purple-600 hover:bg-purple-700 border-none text-white">
                    منح الوسام
                </flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- MODAL: TEAM TASK DEFINITION --}}
    <flux:modal wire:model="showTeamTaskModal" class="md:w-[500px]">
        <div class="space-y-6 text-right" dir="rtl">
            <div>
                <flux:heading size="lg" class="font-bold text-zinc-950 dark:text-white">{{ $editingTeamTaskId ? 'تعديل تعريف المهمة' : 'تعريف مهمة جديدة للمجموعات' }}</flux:heading>
                <flux:subheading class="text-zinc-500">أدخل اسم المهمة ووصفها والمكافآت الافتراضية عند تحقيقها بنسبة 100%.</flux:subheading>
            </div>

            <flux:field>
                <flux:input wire:model="task_name" label="اسم المهمة" placeholder="مثال: مهمة النظافة، الضيافة، الإعلامية" required />
            </flux:field>

            <flux:field>
                <flux:textarea wire:model="task_description" label="وصف المهمة" placeholder="ashrah tafaseel..." />
            </flux:field>

            <div>
                <flux:heading size="sm" class="font-bold text-zinc-900 dark:text-zinc-100 flex items-center justify-between mb-2">
                    <span>بنود ومعايير التقييم التفصيلية</span>
                    <flux:button wire:click="addTaskCriterion" size="xs" variant="ghost" class="text-purple-600 hover:text-purple-700">
                        <svg class="size-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
                        </svg>
                        أضف بند تقييم
                    </flux:button>
                </flux:heading>
                
                @if (empty($task_criteria))
                    <div class="p-4 rounded-xl border border-dashed border-zinc-200 dark:border-zinc-800 text-center text-xs text-zinc-500">
                        لم يتم إضافة أي بند تقييم تفصيلي للمهمة. في حال عدم وجود بنود، سيتم تقييم المهمة مباشرة بنسبة مئوية.
                    </div>
                @else
                    <div class="space-y-3 max-h-[200px] overflow-y-auto pr-1">
                        @foreach($task_criteria as $index => $criterion)
                            <div class="p-3 rounded-xl border border-zinc-200 dark:border-zinc-800 bg-zinc-50 dark:bg-zinc-900/50 space-y-2 relative">
                                <div class="grid grid-cols-12 gap-2 items-center">
                                    <div class="col-span-8">
                                        <flux:input wire:model="task_criteria.{{ $index }}.name" placeholder="اسم البند (مثال: نظافة السجاد)" size="sm" />
                                    </div>
                                    <div class="col-span-3">
                                        <flux:input type="number" wire:model="task_criteria.{{ $index }}.coins_reward" placeholder="العملات" size="sm" />
                                    </div>
                                    <div class="col-span-1 flex justify-center">
                                        <flux:button wire:click="removeTaskCriterion({{ $index }})" size="sm" variant="ghost" square class="text-rose-500 hover:text-rose-600">
                                            <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                            </svg>
                                        </flux:button>
                                    </div>
                                </div>
                                <flux:input wire:model="task_criteria.{{ $index }}.description" placeholder="وصف البند اختياري..." size="sm" />
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            <div class="grid grid-cols-2 gap-4">
                <flux:input type="number" wire:model="task_xp_reward" label="الحد الأقصى لنقاط الخبرة (XP)" required />
                <flux:input type="number" wire:model="task_coins_reward" label="الحد الأقصى للعملات للفريق (يُحسب تلقائياً من البنود)" required disabled="{{ !empty($task_criteria) }}" />
            </div>

            <div class="flex justify-end gap-2 pt-4 border-t border-zinc-100 dark:border-zinc-800">
                <flux:button wire:click="$set('showTeamTaskModal', false)" variant="ghost">إلغاء</flux:button>
                <flux:button wire:click="saveTeamTask" variant="primary" class="bg-purple-600 hover:bg-purple-700 border-none text-white">حفظ التعريف</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- MODAL: TEAM TASK ASSIGNMENT & GRADING --}}
    <flux:modal wire:model="showAssignmentModal" class="md:w-[550px]">
        <div class="space-y-6 text-right" dir="rtl">
            <div>
                <flux:heading size="lg" class="font-bold text-zinc-950 dark:text-white">{{ $editingAssignmentId ? 'تعديل وتقييم تكليف المجموعة' : 'تكليف جديد لمجموعة' }}</flux:heading>
                <flux:subheading class="text-zinc-500">حدد المهمة والمجموعة المكلفة والتواريخ، أو قم بتقييم إنجازهم بالدرجة.</flux:subheading>
            </div>

            <flux:field>
                <flux:select wire:model.live="assignment_task_id" label="اختر المهمة المعرفة" placeholder="اختر المهمة...">
                    <flux:select.option value="">اختر أحد المهام...</flux:select.option>
                    @foreach($dbTeamTasks as $task)
                        <flux:select.option value="{{ $task->id }}">{{ $task->name }} (أقصى مكافأة: {{ $task->xp_reward }} XP / {{ $task->coins_reward }} عملة)</flux:select.option>
                    @endforeach
                </flux:select>
            </flux:field>

            <flux:field>
                <flux:select wire:model="assignment_team_id" label="المجموعة المكلفة" placeholder="اختر المجموعة...">
                    <flux:select.option value="">اختر أحد المجموعات...</flux:select.option>
                    @foreach($dbTeams as $team)
                        <flux:select.option value="{{ $team->id }}">{{ $team->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            </flux:field>

            <flux:field>
                <flux:select wire:model="assignment_teacher_id" label="المعلم المسؤول عن التقييم" placeholder="اختر المعلم...">
                    <flux:select.option value="">المشرف (تقييم مباشر)</flux:select.option>
                    @foreach($dbTeachers as $teacher)
                        <flux:select.option value="{{ $teacher->id }}">{{ $teacher->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            </flux:field>

            <div class="grid grid-cols-2 gap-4">
                <livewire:shared.hijri-datepicker wire:model="assignment_start_date" label="تاريخ البدء (هجري)" />
                <livewire:shared.hijri-datepicker wire:model="assignment_end_date" label="تاريخ الانتهاء (هجري)" />
            </div>
            @error('assignment_start_date')
                <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span>
            @enderror

            @if($assignment_task_id)
                @php
                    $selectedTaskObj = $dbTeamTasks->firstWhere('id', $assignment_task_id);
                @endphp
                @if($selectedTaskObj && $selectedTaskObj->criteria->isNotEmpty())
                    <div class="border-t border-zinc-200 dark:border-zinc-800 pt-4 space-y-4">
                        <flux:heading size="sm" class="font-bold text-zinc-800 dark:text-zinc-200">تقييم بنود المهمة التفصيلية (درجة من 10 لكل بند)</flux:heading>
                        <div class="space-y-3">
                            @foreach($selectedTaskObj->criteria as $criterion)
                                <div class="flex items-center justify-between p-3 rounded-xl border border-zinc-100 dark:border-zinc-800 bg-zinc-50/50 dark:bg-zinc-900/30">
                                    <div class="flex-1 text-right">
                                        <div class="text-sm font-semibold text-zinc-900 dark:text-white">{{ $criterion->name }}</div>
                                        @if($criterion->description)
                                            <div class="text-xs text-zinc-500">{{ $criterion->description }}</div>
                                        @endif
                                        <div class="text-xs text-purple-600 dark:text-purple-400 font-medium">العملات المستحقة عند التحقيق الكامل: {{ $criterion->coins_reward }}</div>
                                    </div>
                                    <div class="w-24">
                                        <flux:input type="number" wire:model.live="assignment_scores.{{ $criterion->id }}" min="0" max="10" placeholder="0 - 10" />
                                    </div>
                                </div>
                            @endforeach
                        </div>
                        @error('assignment_scores.*')
                            <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span>
                        @enderror

                        @if($assignment_grade !== null)
                            <div class="p-3 bg-purple-50 dark:bg-purple-950/20 rounded-xl border border-purple-200 dark:border-purple-800 text-center">
                                <span class="text-sm font-bold text-purple-700 dark:text-purple-300">الدرجة المحتسبة تلقائياً: {{ $assignment_grade }}%</span>
                            </div>
                        @endif
                    </div>
                @endif
            @endif

            <div class="border-t border-zinc-200 dark:border-zinc-800 pt-4 space-y-4">
                <flux:heading size="sm" class="font-bold text-zinc-800 dark:text-zinc-200">ملاحظات التقييم</flux:heading>
                
                @if(empty($selectedTaskObj) || $selectedTaskObj->criteria->isEmpty())
                    <div class="grid grid-cols-3 gap-4">
                        <div class="col-span-1">
                            <flux:input type="number" wire:model="assignment_grade" label="الدرجة (من 100)" placeholder="مثال: 90" min="0" max="100" />
                        </div>
                        <div class="col-span-2">
                            <flux:input wire:model="assignment_notes" label="ملاحظات التقييم" placeholder="اكتب أي ملاحظات على أداء المجموعة..." />
                        </div>
                    </div>
                @else
                    <flux:field>
                        <flux:input wire:model="assignment_notes" label="ملاحظات التقييم" placeholder="اكتب أي ملاحظات على أداء المجموعة..." />
                    </flux:field>
                @endif
                <p class="text-[11px] text-zinc-400 font-medium text-zinc-500 leading-normal">ملاحظة: الدرجة تحسب كنسبة مئوية من نقاط الـ XP والعملات المعرفة للمهمة، وتودع بالكامل في رصيد وخزينة المجموعة.</p>
            </div>

            <div class="flex justify-end gap-2 pt-4 border-t border-zinc-100 dark:border-zinc-800">
                <flux:button wire:click="$set('showAssignmentModal', false)" variant="ghost">إلغاء</flux:button>
                <flux:button wire:click="saveAssignment" variant="primary" class="bg-purple-600 hover:bg-purple-700 border-none text-white">حفظ التكليف</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- MODAL: TEAM --}}
    <flux:modal wire:model="showTeamModal" class="md:w-[550px]">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ $editingTeamId ? 'تعديل الأسرة' : 'إنشاء أسرة جديدة' }}</flux:heading>
                <flux:subheading>أكمل خطوات إعداد الأسرة وتوزيع الطلاب عليها.</flux:subheading>
            </div>

            <!-- Steps Progress Indicator -->
            <div class="flex items-center justify-between border-b border-zinc-100 dark:border-zinc-800 pb-4 mb-4 text-xs font-bold text-zinc-400 select-none" dir="rtl">
                <div class="flex items-center gap-2 {{ $team_step >= 1 ? 'text-purple-600 dark:text-purple-400' : '' }}">
                    <span class="size-6 rounded-full flex items-center justify-center border-2 {{ $team_step >= 1 ? 'border-purple-600 dark:border-purple-400 bg-purple-50 dark:bg-purple-950' : 'border-zinc-300' }}">١</span>
                    <span>المعلومات</span>
                </div>
                <div class="h-0.5 w-8 bg-zinc-200 dark:bg-zinc-800 flex-1 mx-2"></div>
                <div class="flex items-center gap-2 {{ $team_step >= 2 ? 'text-purple-600 dark:text-purple-400' : '' }}">
                    <span class="size-6 rounded-full flex items-center justify-center border-2 {{ $team_step >= 2 ? 'border-purple-600 dark:border-purple-400 bg-purple-50 dark:bg-purple-950' : 'border-zinc-300' }}">٢</span>
                    <span>اللون</span>
                </div>
                <div class="h-0.5 w-8 bg-zinc-200 dark:bg-zinc-800 flex-1 mx-2"></div>
                <div class="flex items-center gap-2 {{ $team_step >= 3 ? 'text-purple-600 dark:text-purple-400' : '' }}">
                    <span class="size-6 rounded-full flex items-center justify-center border-2 {{ $team_step >= 3 ? 'border-purple-600 dark:border-purple-400 bg-purple-50 dark:bg-purple-950' : 'border-zinc-300' }}">٣</span>
                    <span>القيادة</span>
                </div>
                <div class="h-0.5 w-8 bg-zinc-200 dark:bg-zinc-800 flex-1 mx-2"></div>
                <div class="flex items-center gap-2 {{ $team_step >= 4 ? 'text-purple-600 dark:text-purple-400' : '' }}">
                    <span class="size-6 rounded-full flex items-center justify-center border-2 {{ $team_step >= 4 ? 'border-purple-600 dark:border-purple-400 bg-purple-50 dark:bg-purple-950' : 'border-zinc-300' }}">٤</span>
                    <span>الأعضاء</span>
                </div>
            </div>

            <!-- Wizard Contents -->
            <div class="space-y-4">
                @if($team_step === 1)
                    <!-- Step 1: Info -->
                    <div class="space-y-4">
                        <flux:input wire:model="team_name" label="اسم الأسرة" placeholder="مثال: أسرة الفاتح، سفينة الأمل" required />
                        
                        <flux:input wire:model="team_slogan" label="الشعار النصي للأسرة (اختياري)" placeholder="مثال: بالقرآن نحيا، نلتقي لنرتقي" />
                        
                        <flux:input type="file" wire:model="team_logo_file" label="شعار الأسرة (صورة - اختياري)" accept="image/*" />
                        
                        @if ($team_logo_file)
                            <div class="mt-2 text-center bg-zinc-50 dark:bg-zinc-900 p-4 rounded-xl border border-dashed border-zinc-200 dark:border-zinc-800">
                                <span class="text-xs text-zinc-400 block mb-2">معاينة الشعار المرفوع:</span>
                                <img src="{{ $team_logo_file->temporaryUrl() }}" class="size-24 object-contain mx-auto rounded-xl border border-zinc-200 shadow" />
                            </div>
                        @elseif ($existing_logo_path)
                            <div class="mt-2 text-center bg-zinc-50 dark:bg-zinc-900 p-4 rounded-xl border border-zinc-200 dark:border-zinc-800">
                                <span class="text-xs text-zinc-400 block mb-2">الشعار الحالي:</span>
                                <img src="{{ asset('storage/' . $existing_logo_path) }}" class="size-24 object-contain mx-auto rounded-xl border border-zinc-200 shadow" />
                            </div>
                        @endif
                    </div>
                @endif

                @if($team_step === 2)
                    <!-- Step 2: Color Picker -->
                    <div class="space-y-4">
                        <label class="text-sm font-semibold text-zinc-700 dark:text-zinc-300 block">اختر لوناً للأسرة:</label>
                        <div class="grid grid-cols-5 gap-3">
                            @foreach(['#4f46e5', '#7c3aed', '#ec4899', '#f43f5e', '#e11d48', '#ea580c', '#d97706', '#16a34a', '#0d9488', '#0284c7'] as $color)
                                <button type="button" wire:click="$set('team_color', '{{ $color }}')" class="size-10 rounded-full border-2 transition-all flex items-center justify-center hover:scale-105" style="background-color: {{ $color }}; border-color: {{ $team_color === $color ? '#ffffff' : 'transparent' }}; box-shadow: {{ $team_color === $color ? '0 0 0 2px ' . $color : 'none' }}">
                                    @if($team_color === $color)
                                        <flux:icon icon="check" class="size-4 text-white font-bold" />
                                    @endif
                                </button>
                            @endforeach
                        </div>
                        
                        <div class="pt-2">
                            <flux:input type="color" wire:model="team_color" label="أو اختر لوناً مخصصاً (عن طريق رمز الـ Hex)" class="w-full h-12 p-1 rounded-lg border border-zinc-200 dark:border-zinc-800" />
                        </div>
                    </div>
                @endif

                @if($team_step === 3)
                    <!-- Step 3: Choose Leaders -->
                    <div class="space-y-4">
                        <flux:select label="اختيار قائد الأسرة (Leader)" wire:model="team_leader_id" placeholder="اختر طالباً ليكون قائداً للأسرة...">
                            <flux:select.option value="">-- بدون قائد --</flux:select.option>
                            @foreach($students as $std)
                                <flux:select.option value="{{ $std->id }}">{{ $std->name }} ({{ $std->circle->name ?? 'بدون حلقة' }})</flux:select.option>
                            @endforeach
                        </flux:select>
                        
                        <flux:select label="اختيار نائب القائد (Assistant)" wire:model="team_assistant_id" placeholder="اختر طالباً ليكون مساعداً أو نائباً...">
                            <flux:select.option value="">-- بدون نائب --</flux:select.option>
                            @foreach($students as $std)
                                @if($std->id != $team_leader_id)
                                    <flux:select.option value="{{ $std->id }}">{{ $std->name }} ({{ $std->circle->name ?? 'بدون حلقة' }})</flux:select.option>
                                @endif
                            @endforeach
                        </flux:select>
                    </div>
                @endif

                @if($team_step === 4)
                    <!-- Step 4: Grouped Students List -->
                    <div class="space-y-4">
                        <label class="text-sm font-semibold text-zinc-700 dark:text-zinc-300 block">حدد طلاب الأسرة (مجمعين حسب الحلقات):</label>
                        
                        <div class="space-y-6 max-h-[300px] overflow-y-auto pr-1">
                            @php
                                $studentsByCircle = $students->groupBy(fn($std) => $std->circle->name ?? 'بدون حلقة');
                            @endphp
                            @foreach($studentsByCircle as $circleName => $circleStudents)
                                <div class="space-y-2.5">
                                    <span class="text-xs font-black text-indigo-600 dark:text-indigo-400 block border-r-4 border-indigo-500 pr-2 pb-0.5 bg-indigo-50/10 dark:bg-indigo-950/10 rounded-l-md">{{ $circleName }}</span>
                                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-2.5">
                                        @foreach($circleStudents as $std)
                                            @php
                                                $otherTeam = null;
                                                $stdTeamId = $studentTeams[$std->id] ?? null;
                                                if ($stdTeamId && $stdTeamId != $editingTeamId) {
                                                    $otherTeam = $dbTeams->firstWhere('id', $stdTeamId);
                                                }
                                                $isSelected = in_array((string)$std->id, $team_student_ids);
                                            @endphp
                                            <label class="flex items-start gap-2 p-2.5 rounded-xl border transition-all cursor-pointer select-none {{ $isSelected ? 'border-purple-600 bg-purple-500/10 dark:bg-purple-500/5' : 'border-zinc-200 dark:border-zinc-800 bg-zinc-50/30 hover:bg-zinc-100/50 dark:hover:bg-zinc-800/30' }}">
                                                <input type="checkbox" value="{{ $std->id }}" wire:model="team_student_ids" class="rounded border-zinc-300 text-purple-600 focus:ring-purple-500 mt-0.5" />
                                                <div class="space-y-0.5">
                                                    <span class="font-bold text-xs leading-tight block {{ $isSelected ? 'text-purple-700 dark:text-purple-400' : 'text-zinc-800 dark:text-zinc-200' }}">{{ $std->name }}</span>
                                                    @if($otherTeam)
                                                        <span class="text-[9px] font-semibold text-rose-500 block leading-none">موزع في: {{ $otherTeam->name }}</span>
                                                    @endif
                                                </div>
                                            </label>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>

            <!-- Buttons -->
            <div class="flex justify-between gap-2 pt-4 border-t border-zinc-100 dark:border-zinc-800">
                <div>
                    @if($team_step > 1)
                        <flux:button wire:click="prevTeamStep" variant="ghost">السابق</flux:button>
                    @endif
                </div>
                
                <div class="flex gap-2">
                    <flux:button wire:click="$set('showTeamModal', false)" variant="ghost">إلغاء</flux:button>
                    
                    @if($team_step < 4)
                        <flux:button wire:click="nextTeamStep" variant="primary" class="bg-purple-600 hover:bg-purple-700 border-none text-white">التالي</flux:button>
                    @else
                        <flux:button wire:click="saveTeam" variant="primary" class="bg-purple-600 hover:bg-purple-700 border-none text-white">حفظ وإغلاق</flux:button>
                    @endif
                </div>
            </div>
        </div>
    </flux:modal>

    {{-- MODAL: STORE ITEM --}}
    <flux:modal wire:model="showItemModal" class="md:w-[500px]">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ $editingItemId ? 'تعديل جائزة المتجر' : 'إضافة جائزة جديدة للمتجر' }}</flux:heading>
                <flux:subheading>أدخل معلومات الجائزة وسعرها بالعملة الافتراضية.</flux:subheading>
            </div>

            <div class="space-y-4">
                <flux:input wire:model="item_name" label="اسم الجائزة" placeholder="مثال: بطاقة تجميد الحماسة، درع الحماية" required />
                <flux:textarea wire:model="item_description" label="الوصف" placeholder="وصف الميزة للطلاب" />
                
                <div class="grid grid-cols-2 gap-4">
                    <flux:input type="number" wire:model="item_price" label="سعر الجائزة بالعملة" placeholder="مثل: 150" required />
                    
                    <flux:select label="نوع الجائزة" wire:model.live="item_type" :disabled="$this->isEditingPermanentItem()">
                        <flux:select.option value="freeze">تجميد (فردي للطلاب)</flux:select.option>
                        <flux:select.option value="team_attack">خصم على مجموعة (جماعي للأسرة)</flux:select.option>
                        <flux:select.option value="shield">درع حماية لمدة يوم (جماعي للأسرة)</flux:select.option>
                        <flux:select.option value="team_points">إضافة نقاط للفريق (جماعي للأسرة)</flux:select.option>
                    </flux:select>
                </div>

                @if($item_type === 'shield' && !$this->isEditingPermanentItem())
                    <div>
                        <livewire:shared.hijri-datepicker wire:model="item_target_date" label="تاريخ اليوم المحدد بالهجري (اختياري - اتركه فارغاً لتحديد التاريخ عند الشراء)" />
                    </div>
                @elseif(in_array($item_type, ['team_attack', 'team_points']) && !$this->isEditingPermanentItem())
                    <div>
                        <flux:input type="number" wire:model="item_value" label="{{ $item_type === 'team_attack' ? 'عدد النقاط المخصومة' : 'عدد النقاط المضافة' }}" placeholder="مثال: 50" required />
                    </div>
                @endif

                @if($item_type !== 'freeze' && !$this->isEditingPermanentItem())
                    <div class="p-4 rounded-xl border border-zinc-200 dark:border-zinc-800 bg-zinc-50 dark:bg-zinc-900/50 space-y-3.5">
                        <span class="font-bold text-sm text-zinc-700 dark:text-zinc-300 block">شروط تصويت الموافقة على الشراء (جماعي):</span>
                        
                        <div class="flex items-center gap-2">
                            <flux:checkbox wire:model="item_require_assistant_approval" label="يشترط موافقة النائب (مساعد القائد)" />
                        </div>
                        
                        <div class="space-y-1.5">
                            <flux:input type="number" wire:model="item_require_member_approval_count" label="عدد الموافقات المطلوبة من أعضاء الفريق (0 لعدم اشتراط عدد محدد)" placeholder="مثال: 2" min="0" />
                            <p class="text-[11px] text-zinc-400">إذا تركتها 0 ولم تشترط موافقة النائب، سيتم شراء المنتج فوراً دون الحاجة للتصويت.</p>
                        </div>
                    </div>
                @endif

                <div class="flex items-center gap-2 pt-2">
                    <flux:checkbox wire:model="item_is_active" label="تفعيل هذا المنتج (يظهر كخيار متاح للشراء في المتجر ولوحة التحكم)" />
                </div>

                @if($item_type === 'freeze' && !$item_is_team_product)
                    <div class="mt-4 border-t border-zinc-200 dark:border-zinc-800 pt-4 space-y-4">
                        <div class="flex justify-between items-center">
                            <flux:label class="font-bold">تخصيص أيام التجميد الإضافية حسب المستويات</flux:label>
                            <flux:button size="xs" wire:click="addFreezeLevelRule" variant="ghost" icon="plus" class="text-purple-600">إضافة مستوى</flux:button>
                        </div>
                        <p class="text-xs text-zinc-500">
                            بشكل افتراضي، يمكن للطالب تجميد اليوم الحالي واليوم السابق فقط (حد يوم واحد سابق). يمكنك تحديد مستويات معينة تزيد من عدد الأيام السابقة المسموح بتجميدها.
                        </p>
                        
                        <div class="space-y-3">
                            @foreach($freezeLevelRules as $index => $rule)
                                <div class="flex items-center gap-3">
                                    <flux:input type="number" wire:model="freezeLevelRules.{{ $index }}.level" label="المستوى" placeholder="مثال: 3" class="w-1/2" />
                                    <flux:input type="number" wire:model="freezeLevelRules.{{ $index }}.days" label="الأيام السابقة المسموح بها" placeholder="مثال: 2" class="w-1/2" />
                                    <div class="pt-6">
                                        <flux:button wire:click="removeFreezeLevelRule({{ $index }})" variant="ghost" icon="trash" class="text-rose-500" />
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>

            <div class="flex justify-end gap-2">
                <flux:button wire:click="$set('showItemModal', false)" variant="ghost">إلغاء</flux:button>
                <flux:button wire:click="saveItem" variant="primary" class="bg-purple-600 hover:bg-purple-700 border-none text-white">حفظ</flux:button>
            </div>
        </div>
    </flux:modal>

    <!-- Activity Creation/Edition Modal -->
    <flux:modal name="activityModal" wire:model="showActivityModal" class="min-w-[28rem] md:min-w-[42rem] space-y-6">
        <div>
            <flux:heading size="lg">{{ $editingActivityId ? 'تعديل الفعالية والأنشطة' : 'تعريف فعالية جديدة' }}</flux:heading>
            <flux:subheading>حدد اسم الفعالية والمراكز ونوع الجوائز التنافسية المخصصة لها.</flux:subheading>
        </div>

        <div class="space-y-4 max-h-[70vh] overflow-y-auto pr-1">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div class="md:col-span-2">
                    <flux:input label="اسم الفعالية" wire:model="activity_name" required placeholder="مثال: الدوري الرياضي، المسابقة الثقافية الكبرى..." />
                </div>
                <div>
                    <flux:input type="color" label="لون الفعالية" wire:model="activity_color" class="h-10 cursor-pointer" />
                </div>
            </div>

            <div class="flex items-center gap-4">
                <div class="flex-1">
                    <flux:input type="file" label="شعار/أيقونة الفعالية (PNG أو SVG)" wire:model="activity_icon_file" accept=".png,.svg,.jpg,.jpeg" />
                </div>
                @if($activity_icon_file)
                    <div class="size-16 rounded-xl border flex items-center justify-center overflow-hidden bg-zinc-50 dark:bg-zinc-900 shrink-0">
                        <img src="{{ $activity_icon_file->temporaryUrl() }}" class="size-12 object-contain" />
                    </div>
                @elseif($editingActivityId && $activity_icon_path)
                    <div class="size-16 rounded-xl border flex items-center justify-center overflow-hidden bg-zinc-50 dark:bg-zinc-900 shrink-0">
                        <img src="{{ asset('storage/' . $activity_icon_path) }}" class="size-12 object-contain" />
                    </div>
                @endif
            </div>

            <flux:textarea label="الوصف" wire:model="activity_description" placeholder="اكتب تفاصيل أو قواعد المسابقة والنشاط..." />

            <div class="border-t border-zinc-200 dark:border-zinc-800 pt-4 space-y-4">
                <div class="flex justify-between items-center">
                    <flux:heading size="md">فئات المراكز والجوائز للمنافسة</flux:heading>
                    <flux:button wire:click="addActivityRank" size="sm" icon="plus" variant="filled">إضافة مركز جديد</flux:button>
                </div>

                <div class="space-y-4">
                    @foreach($activity_ranks as $index => $rank)
                        <div class="bg-zinc-50 dark:bg-zinc-800/40 p-4 rounded-xl border border-zinc-200/60 dark:border-zinc-800 space-y-3 relative">
                            <button type="button" wire:click="removeActivityRank({{ $index }})" class="absolute top-3 left-3 text-zinc-400 hover:text-rose-500 transition-colors" title="حذف المركز">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>

                            <div class="grid grid-cols-1 md:grid-cols-5 gap-3 pt-2">
                                <div class="col-span-1 md:col-span-2">
                                    <flux:input label="اسم المركز" wire:model="activity_ranks.{{ $index }}.name" required placeholder="المركز الأول، كأس اللعب النظيف، إلخ" />
                                </div>
                                <div>
                                    <flux:input type="number" min="0" label="نقاط المجموعة" wire:model="activity_ranks.{{ $index }}.team_xp" />
                                </div>
                                <div>
                                    <flux:input type="number" min="0" label="عملات المجموعة" wire:model="activity_ranks.{{ $index }}.team_coins" />
                                </div>
                            </div>
                            <div class="grid grid-cols-1 md:grid-cols-5 gap-3">
                                <div class="col-span-1 md:col-span-2 text-xs text-zinc-450 dark:text-zinc-500 flex items-center pr-1">
                                    المكافآت الفردية لكل عضو في الفريق الفائز:
                                </div>
                                <div>
                                    <flux:input type="number" min="0" label="نقاط العضو" wire:model="activity_ranks.{{ $index }}.member_xp" />
                                </div>
                                <div>
                                    <flux:input type="number" min="0" label="عملات العضو" wire:model="activity_ranks.{{ $index }}.member_coins" />
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="border-t border-zinc-200 dark:border-zinc-800 pt-4 space-y-4">
                <div class="flex justify-between items-center">
                    <flux:heading size="md">تواريخ وجولات الفعالية المجدولة</flux:heading>
                    <flux:button wire:click="addActivityRound" size="sm" icon="plus" variant="filled">إضافة جولة جديدة</flux:button>
                </div>

                <div class="space-y-4">
                    @foreach($activity_rounds as $index => $round)
                        <div class="flex items-end gap-3 bg-zinc-50 dark:bg-zinc-800/40 p-4 rounded-xl border border-zinc-200/60 dark:border-zinc-800 relative">
                            <div class="flex-1">
                                <flux:input label="اسم الجولة / المباراة" wire:model="activity_rounds.{{ $index }}.name" required placeholder="الجولة الأولى، مباراة 1، إلخ" />
                            </div>
                            <div class="flex-1">
                                <livewire:shared.hijri-datepicker wire:model="activity_rounds.{{ $index }}.round_date" label="تاريخ الجولة (هجري)" :key="'round-date-'.$index" />
                            </div>
                            <button type="button" wire:click="removeActivityRound({{ $index }})" class="text-zinc-400 hover:text-rose-500 pb-2 transition-colors" title="حذف الجولة">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        <div class="flex justify-end gap-2 pt-4">
            <flux:button @click="showActivityModal = false" variant="ghost">إلغاء</flux:button>
            <flux:button wire:click="saveActivity" variant="primary" class="bg-purple-600 hover:bg-purple-700 text-white border-none">حفظ الفعالية</flux:button>
        </div>
    </flux:modal>

    <!-- Round Winners Recording Modal -->
    <flux:modal name="roundWinnersModal" wire:model="showRoundWinnersModal" class="min-w-[24rem] md:min-w-[32rem] space-y-6">
        @php
            $activeRoundForModal = $dbRounds->firstWhere('id', $selectedRoundId);
        @endphp
        @if($activeRoundForModal)
            <div>
                <flux:heading size="lg">رصد وتعديل نتائج الجولة</flux:heading>
                <flux:subheading>{{ $activeRoundForModal->activity->name }} - {{ $activeRoundForModal->name }} (<x-hijri-date :date="$activeRoundForModal->round_date" />)</flux:subheading>
            </div>

            <div class="space-y-4">
                @foreach($activeRoundForModal->activity->ranks as $rank)
                    <flux:select label="{{ $rank->name }}" wire:model="roundRanksWinners.{{ $rank->id }}">
                        <option value="">-- لا يوجد فائز --</option>
                        @foreach($dbTeams as $team)
                            <option value="{{ $team->id }}">{{ $team->name }}</option>
                        @endforeach
                    </flux:select>
                    <div class="text-[10px] text-zinc-500 dark:text-zinc-400 -mt-2 mr-1">
                        المكافأة: (المجموعة: +{{ $rank->team_xp }}XP / +{{ $rank->team_coins }} عملة) - (الأعضاء: +{{ $rank->member_xp }}XP / +{{ $rank->member_coins }} عملة)
                    </div>
                @endforeach
            </div>

            <div class="flex justify-end gap-2 pt-4">
                <flux:button @click="showRoundWinnersModal = false" variant="ghost">إلغاء</flux:button>
                <flux:button wire:click="saveRoundWinners" variant="primary" class="bg-purple-600 hover:bg-purple-700 text-white border-none">حفظ النتائج</flux:button>
            </div>
        @else
            <div class="text-center py-6 text-zinc-500">جاري تحميل تفاصيل الجولة...</div>
        @endif
    </flux:modal>
</div>
