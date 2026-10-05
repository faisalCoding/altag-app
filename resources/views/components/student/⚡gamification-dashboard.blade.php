<?php

use Livewire\Component;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;
use Flux\Flux;

new class extends Component {
    use \Livewire\WithFileUploads {
        _uploadErrored as protected livewireUploadErrored;
    }

    public $profile_image_file = null;
    public array $targetTeams = [];
    public array $targetDates = [];
    public string $teamColor = '#4f46e5';
    public bool $showDoublePointsModal = false;
    public string $doublePointsDate = '';
    public string $doublePointsType = 'team';
    public int $doublePointsPrice = 150;

    public bool $showFreezeModal = false;
    public string $freezeTargetDate = '';
    public string $freezeDayName = '';
    public string $freezeHijriDate = '';
    public int $freezePrice = 100;

    public ?string $newsDate = null;

    /**
     * Rebuild the student's streak and milestone rows once, as the page opens.
     *
     * It used to run in with(), so every tap on the page (a news chip, a modal,
     * a claim) rebuilt it again before drawing. Nothing the student does here
     * moves the streak without saying so: the freeze and the milestone claim
     * rebuild it themselves, and a teacher's marking rebuilds it on their side.
     * Anything else, such as a supervisor editing the milestones, shows the
     * next time the page opens.
     */
    public function mount(): void
    {
        $student = Auth::guard('student')->user();
        $leaderboard = $student ? $this->displayedGamification($student) : null;

        if ($leaderboard) {
            \App\Services\GamificationService::recalculateStudentStreak($student, $leaderboard);
        }
    }

    public function setNewsDate(string $date): void
    {
        $this->newsDate = $date;
    }

    /**
     * Save a newly picked photo as the student's avatar.
     *
     * The file input is hidden behind the avatar, so a refused file must say
     * so out loud: the rule's message shows under the avatar and as a toast.
     * It used to fail in silence, the avatar unchanged and no word why.
     */
    public function updatedProfileImageFile(): void
    {
        try {
            $this->validate([
                'profile_image_file' => 'required|image|max:10240',
            ], [
                'profile_image_file.required' => 'لم يصل ملف الصورة، حاول مرة أخرى.',
                'profile_image_file.image' => 'الملف المختار ليس صورة. اختر صورة بصيغة JPG أو PNG أو WebP.',
                'profile_image_file.max' => 'حجم الصورة أكبر من 10 ميجابايت. اختر صورة أصغر.',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->profile_image_file = null;
            Flux::toast($e->validator->errors()->first('profile_image_file'), variant: 'danger');

            throw $e;
        }

        $student = Auth::guard('student')->user();

        // Process profile photo and convert to WebP. A file can pass the image
        // rule by its type and still not decode (damaged, or a format GD can't
        // read); that used to end in an error page instead of a word.
        try {
            $manager = new \Intervention\Image\ImageManager(new \Intervention\Image\Drivers\Gd\Driver);
            $tempPath = $this->profile_image_file->getRealPath();
            $image = $manager->decode($tempPath);
            $image->scale(height: 256);
            $webpData = $image->encode(new \Intervention\Image\Encoders\WebpEncoder(85))->toString();
        } catch (\Throwable $e) {
            report($e);
            $this->profile_image_file = null;
            $this->addError('profile_image_file', 'تعذرت قراءة الصورة. اختر صورة أخرى بصيغة JPG أو PNG أو WebP.');
            Flux::toast('تعذرت قراءة الصورة. اختر صورة أخرى بصيغة JPG أو PNG أو WebP.', variant: 'danger');

            return;
        }

        $filename = 'avatars/' . uniqid() . '_student_' . $student->id . '.webp';
        \Illuminate\Support\Facades\Storage::disk('public')->put($filename, $webpData);

        // Delete old avatar if exists
        if ($student->avatar_path) {
            \Illuminate\Support\Facades\Storage::disk('public')->delete($student->avatar_path);
        }

        $student->update(['avatar_path' => $filename]);

        $this->profile_image_file = null;
        Flux::toast('تم تحديث صورتك الشخصية بنجاح!', variant: 'success');
    }

    /**
     * A photo refused on its way up, before updatedProfileImageFile() sees it.
     *
     * Livewire takes every upload to a temporary store first, under its own
     * rules (a file over 12 MB stops there), and reports the refusal through
     * this method in English: "The profile_image_file failed to upload." The
     * avatar's refusal is said in Arabic instead, under the avatar and as a
     * toast, like a refusal by the avatar's own rules. Any other upload keeps
     * Livewire's handling.
     */
    public function _uploadErrored($name, $errorsInJson, $isMultiple)
    {
        try {
            $this->livewireUploadErrored($name, $errorsInJson, $isMultiple);
        } catch (\Illuminate\Validation\ValidationException $e) {
            if ($name !== 'profile_image_file') {
                throw $e;
            }

            $message = 'تعذر رفع الصورة. اختر صورة أصغر من 10 ميجابايت وحاول مرة أخرى.';
            Flux::toast($message, variant: 'danger');

            throw \Illuminate\Validation\ValidationException::withMessages(['profile_image_file' => $message]);
        }
    }

    public function openDoublePointsModal(string $date, string $type = 'team'): void
    {
        $this->doublePointsType = $type;

        $student = Auth::guard('student')->user();
        $activeGamification = \App\Models\Leaderboard::whereHas('circles', fn($q) => $q->where('circles.id', $student->circle_id))
            ->whereNotNull('supervisor_id')
            ->where('is_active', true)
            ->latest()
            ->first() ?? \App\Models\Leaderboard::where('circle_id', $student->circle_id)
            ->whereNull('supervisor_id')
            ->where('is_active', true)
            ->latest()
            ->first();

        if ($activeGamification) {
            $isTeam = $type === 'team';
            $levelInfo = \App\Services\GamificationService::getStudentLevel($student->id, $activeGamification->id);
            $level = $levelInfo['perks'] ?? null;
            $lvlSettings = $level && is_array($level->settings) ? $level->settings : (array) ($level?->settings ?? []);
            
            $this->doublePointsPrice = $isTeam ? (int) ($lvlSettings['team_multiplier_price'] ?? 150) : (int) ($lvlSettings['individual_multiplier_price'] ?? 150);
        } else {
            $this->doublePointsPrice = 150;
        }

        $upcoming = $this->getUpcomingDays();
        $defaultDate = '';
        if (!empty($upcoming)) {
            $existsAndWorking = false;
            foreach ($upcoming as $day) {
                if ($day['date'] === $date && $day['is_working']) {
                    $existsAndWorking = true;
                    break;
                }
            }
            if ($existsAndWorking) {
                $defaultDate = $date;
            } else {
                // Find the first working day in the upcoming days list
                foreach ($upcoming as $day) {
                    if ($day['is_working']) {
                        $defaultDate = $day['date'];
                        break;
                    }
                }
                if (empty($defaultDate)) {
                    $defaultDate = $upcoming[0]['date'];
                }
            }
        } else {
            $defaultDate = $date;
        }

        $this->doublePointsDate = $defaultDate;
        $this->showDoublePointsModal = true;
    }

    public function purchaseDoublePoints(): void
    {
        $student = Auth::guard('student')->user();
        
        $activeGamification = \App\Models\Leaderboard::whereHas('circles', fn($q) => $q->where('circles.id', $student->circle_id))
            ->whereNotNull('supervisor_id')
            ->where('is_active', true)
            ->latest()
            ->first();

        if (!$activeGamification) {
            $activeGamification = \App\Models\Leaderboard::where('circle_id', $student->circle_id)
                ->whereNull('supervisor_id')
                ->where('is_active', true)
                ->latest()
                ->first();
        }

        if (!$activeGamification) {
            Flux::toast('لا يوجد مسابقة تلعيب نشطة حالياً.', variant: 'danger');
            return;
        }

        if (empty($this->doublePointsDate)) {
            Flux::toast('يرجى اختيار تاريخ التفعيل أولاً.', variant: 'danger');
            return;
        }

        // Validate working days
        $workingDays = \App\Services\GamificationService::getWorkingDaysForLeaderboard($activeGamification, $student->effective_stage_id);
        if (!in_array($this->doublePointsDate, $workingDays)) {
            Flux::toast('خطأ: لا يمكن تفعيل الميزة في يوم إجازة. يجب اختيار يوم من أيام الدوام المعتمدة للحلقة.', variant: 'danger');
            return;
        }

        $levelInfo = \App\Services\GamificationService::getStudentLevel($student->id, $activeGamification->id);
        $level = $levelInfo['perks'] ?? null;
        $lvlSettings = $level && is_array($level->settings) ? $level->settings : (array) ($level?->settings ?? []);

        $isTeam = $this->doublePointsType === 'team';
        $isEnabled = $isTeam ? ($lvlSettings['has_team_multiplier'] ?? true) : ($lvlSettings['has_individual_multiplier'] ?? true);
        $price = $isTeam ? (int) ($lvlSettings['team_multiplier_price'] ?? 150) : (int) ($lvlSettings['individual_multiplier_price'] ?? 150);

        if (!$isEnabled) {
            Flux::toast('خطأ: مضاعف النقاط غير مفعل لمستواك الحالي.', variant: 'danger');
            return;
        }

        $item = \App\Models\GamificationStoreItem::firstOrCreate([
            'leaderboard_id' => $activeGamification->id,
            'item_type' => 'multiplier',
            'is_team_product' => $isTeam,
        ], [
            'name' => $isTeam ? 'مضاعف النقاط للكل' : 'مضاعف النقاط الفردي',
            'description' => $isTeam ? 'مضاعفة جميع نقاط الخبرة التي يحصّلها الفريق من كل المصادر (الحفظ، المراجعة، الحضور، المهام، الفعاليات، والتسويات اليدوية) ليوم واحد.' : 'مضاعفة نقاط الحفظ والمراجعة والحضور الخاصة بك ليوم واحد.',
            'price' => 150,
            'value' => 2,
            'is_active' => true,
        ]);

        if (!$item->is_active) {
            Flux::toast('خطأ: هذا المنتج غير مفعل حالياً في المتجر ولا يمكن شراؤه.', variant: 'danger');
            return;
        }

        $status = \App\Services\GamificationService::requestStorePurchase($student->id, $item->id, null, $this->doublePointsDate, $price);

        if ($status === 'success' || $status === 'approved') {
            Flux::toast('تم شراء ميزة مضاعفة النقاط بنجاح!', variant: 'success');
            $this->showDoublePointsModal = false;
        } elseif ($status === 'pending_voting') {
            Flux::toast('تم إرسال طلب الشراء. بانتظار تصويت أعضاء الفريق!', variant: 'success');
            $this->showDoublePointsModal = false;
        } elseif ($status === 'insufficient_coins') {
            Flux::toast('رصيدك غير كافٍ للشراء.', variant: 'danger');
        } elseif ($status === 'insufficient_team_coins') {
            Flux::toast('رصيد الفريق غير كافٍ لشراء هذا المنتج.', variant: 'danger');
        } elseif ($status === 'only_leader') {
            Flux::toast('فقط قائد المجموعة يمكنه شراء منتجات المجموعة وتحديد تفاصيلها.', variant: 'danger');
        } elseif ($status === 'must_be_in_team') {
            Flux::toast('يجب أن تكون في فريق لشراء هذا المنتج.', variant: 'danger');
        } elseif ($status === 'invalid_target_date') {
            Flux::toast('تاريخ التفعيل يجب أن يكون من يوم الغد فصاعداً وهو يوم دوام.', variant: 'danger');
        } elseif ($status === 'individual_multiplier_already_purchased') {
            Flux::toast('خطأ: تم بالفعل شراء أو تفعيل مضاعف نقاط فردي لهذا التاريخ.', variant: 'danger');
        } elseif ($status === 'team_multiplier_already_purchased') {
            Flux::toast('خطأ: تم بالفعل شراء أو تفعيل مضاعف نقاط جماعي لهذا التاريخ.', variant: 'danger');
        } elseif ($status === 'multiplier_already_purchased') {
            Flux::toast('خطأ: تم بالفعل شراء أو تفعيل مضاعف نقاط (فردي أو جماعي) لهذا التاريخ.', variant: 'danger');
        } else {
            Flux::toast('تعذر إتمام عملية الشراء.', variant: 'danger');
        }
    }

    /**
     * Open the freeze modal for a day.
     *
     * Nothing else on the page changes, so the page isn't drawn again for it:
     * the modal opens from its wire:model, and the day and the price it names
     * are read from the component's state in the browser (x-text) rather than
     * printed by a redraw. Opening it used to run the whole page's reads again.
     */
    #[\Livewire\Attributes\Renderless]
    public function openFreezeModal(string $date, string $dayName, string $hijriDate): void
    {
        $student = Auth::guard('student')->user();
        $activeGamification = \App\Models\Leaderboard::whereHas('circles', fn($q) => $q->where('circles.id', $student->circle_id))
            ->whereNotNull('supervisor_id')
            ->where('is_active', true)
            ->latest()
            ->first() ?? \App\Models\Leaderboard::where('circle_id', $student->circle_id)
            ->whereNull('supervisor_id')
            ->where('is_active', true)
            ->latest()
            ->first();

        if ($activeGamification) {
            $levelInfo = \App\Services\GamificationService::getStudentLevel($student->id, $activeGamification->id);
            $level = $levelInfo['perks'] ?? null;
            $lvlSettings = $level && is_array($level->settings) ? $level->settings : (array) ($level?->settings ?? []);
            $this->freezePrice = (int) ($lvlSettings['freeze_price'] ?? 100);
        } else {
            $this->freezePrice = 100;
        }

        $this->freezeTargetDate = $date;
        $this->freezeDayName = $dayName;
        $this->freezeHijriDate = $hijriDate;
        $this->showFreezeModal = true;
    }

    public function purchaseFreeze(): void
    {
        $student = Auth::guard('student')->user();
        $activeGamification = \App\Models\Leaderboard::whereHas('circles', fn($q) => $q->where('circles.id', $student->circle_id))
            ->whereNotNull('supervisor_id')
            ->where('is_active', true)
            ->latest()
            ->first() ?? \App\Models\Leaderboard::where('circle_id', $student->circle_id)
            ->whereNull('supervisor_id')
            ->where('is_active', true)
            ->latest()
            ->first();

        if (!$activeGamification) {
            Flux::toast('لا يوجد مسابقة تلعيب نشطة حالياً.', variant: 'danger');
            return;
        }

        $levelInfo = \App\Services\GamificationService::getStudentLevel($student->id, $activeGamification->id);
        $level = $levelInfo['perks'] ?? null;
        $lvlSettings = $level && is_array($level->settings) ? $level->settings : (array) ($level?->settings ?? []);

        $hasFreeze = $lvlSettings['has_freeze'] ?? true;
        $price = (int) ($lvlSettings['freeze_price'] ?? 100);

        if (!$hasFreeze) {
            Flux::toast('خطأ: ميزة التجميد غير مفعلة لمستواك الحالي.', variant: 'danger');
            return;
        }

        $item = \App\Models\GamificationStoreItem::where('leaderboard_id', $activeGamification->id)
            ->where('item_type', 'freeze')
            ->where('is_streak_freeze', true)
            ->where('is_team_product', false)
            ->first();

        if (!$item || !$item->is_active) {
            Flux::toast('خطأ: منتج تجميد الحماسة غير متوفر أو غير نشط حالياً.', variant: 'danger');
            return;
        }

        $status = \App\Services\GamificationService::requestStorePurchase($student->id, $item->id, null, $this->freezeTargetDate, $price);

        if ($status === 'success' || $status === 'approved') {
            Flux::toast('تم تجميد اليوم بنجاح!', variant: 'success');
            \App\Services\GamificationService::recalculateStudentStreak($student, $activeGamification);
            $this->showFreezeModal = false;
        } elseif ($status === 'insufficient_coins') {
            Flux::toast('رصيدك غير كافٍ للشراء. تحتاج إلى ' . $price . ' عملة.', variant: 'danger');
        } elseif ($status === 'already_frozen') {
            Flux::toast('خطأ: هذا اليوم مجمد بالفعل.', variant: 'danger');
        } else {
            Flux::toast('تعذر إتمام عملية التجميد.', variant: 'danger');
        }
    }

    public function getUpcomingDays(): array
    {
        $student = Auth::guard('student')->user();
        if (!$student) {
            return [];
        }

        $activeGamification = \App\Models\Leaderboard::whereHas('circles', fn($q) => $q->where('circles.id', $student->circle_id))
            ->whereNotNull('supervisor_id')
            ->where('is_active', true)
            ->latest()
            ->first();

        if (!$activeGamification) {
            $activeGamification = \App\Models\Leaderboard::where('circle_id', $student->circle_id)
                ->whereNull('supervisor_id')
                ->where('is_active', true)
                ->latest()
                ->first();
        }

        if (!$activeGamification) {
            return [];
        }

        $allWorkingDays = \App\Services\GamificationService::getWorkingDaysForLeaderboard($activeGamification, $student->effective_stage_id);
        $tomorrow = \Carbon\Carbon::now('Asia/Riyadh')->addDay();

        $upcoming = [];
        for ($i = 0; $i < 20; $i++) {
            $date = $tomorrow->copy()->addDays($i);
            $dateStr = $date->format('Y-m-d');
            $isWorking = in_array($dateStr, $allWorkingDays);

            $upcoming[] = [
                'date' => $dateStr,
                'hijri' => \App\Support\HijriDate::format($date, 'EEEE، d MMMM'),
                'gregorian' => $date->translatedFormat('l، d F Y'),
                'is_working' => $isWorking,
            ];
        }

        return $upcoming;
    }

    /**
     * Classify a team's approved store purchase for the inventory list.
     *
     * Products with a target date (multiplier, shield) are "not used yet" while
     * that date is in the future, "active" on the day itself, and "used" once it
     * has passed. Instant products (team points/attacks) count as used on purchase.
     *
     * @return array{state: string, label: string, color: string, icon: string, used: bool}
     */
    public function teamPurchaseUsage(\App\Models\GamificationStorePurchase $purchase): array
    {
        // Compare calendar dates (the competition runs on Asia/Riyadh days, matching
        // how purchase effects are applied in GamificationService).
        $today = Carbon::now('Asia/Riyadh')->toDateString();
        $targetDate = $purchase->target_date
            ? Carbon::parse($purchase->target_date)->toDateString()
            : null;

        if ($targetDate !== null) {
            if ($targetDate > $today) {
                return ['state' => 'scheduled', 'label' => 'لم يُستخدم بعد', 'color' => 'blue', 'icon' => 'clock', 'used' => false];
            }

            if ($targetDate === $today) {
                return ['state' => 'active', 'label' => 'نشط اليوم', 'color' => 'green', 'icon' => 'bolt', 'used' => true];
            }
        }

        return ['state' => 'used', 'label' => 'مُستخدَم', 'color' => 'zinc', 'icon' => 'check-circle', 'used' => true];
    }

    /**
     * The competition this page shows: the circle's active supervisor
     * competition first, otherwise its teacher's, and only a gamification one.
     *
     * Every action that works on "the competition" asks this, so it acts on
     * the one on screen. «استلام الكل» and the donation used to ask
     * getActiveLeaderboards() instead, which also wants today between the start
     * and end dates by the UTC clock. Nothing deactivates a competition when its
     * end date passes, so the day after, the page still listed the pending
     * rewards and the donate box while both actions found no competition and
     * returned without a word; and with a supervisor competition yet to start on
     * screen, «استلام الكل» claimed in the teacher's running one instead.
     */
    protected function displayedGamification(\App\Models\Student $student): ?\App\Models\Leaderboard
    {
        $leaderboard = \App\Models\Leaderboard::whereHas('circles', fn ($q) => $q->where('circles.id', $student->circle_id))
            ->whereNotNull('supervisor_id')
            ->where('is_active', true)
            ->latest()
            ->first()
            ?? \App\Models\Leaderboard::where('circle_id', $student->circle_id)
                ->whereNull('supervisor_id')
                ->where('is_active', true)
                ->latest()
                ->first();

        return $leaderboard?->competition_type === 'gamification' ? $leaderboard : null;
    }

    /**
     * Why the team treasury takes no donation today, or null when it does.
     *
     * Donations are open while the competition runs, counted in the academy's
     * days (Asia/Riyadh): the app's clock is UTC, and asking it kept the box
     * shut from midnight to 3am on the first day. The page reads the same answer
     * to show either the donate box or this note, so a student is never offered
     * a button that refuses without saying why.
     */
    protected function donationClosedReason(\App\Models\Leaderboard $leaderboard): ?string
    {
        $today = \App\Services\TeacherSyncSnapshot::today();

        if ($leaderboard->start_date && Carbon::parse($leaderboard->start_date)->toDateString() > $today) {
            return 'لم تبدأ المسابقة بعد، ويُفتح التبرع للفريق مع بدايتها.';
        }

        if ($leaderboard->end_date && Carbon::parse($leaderboard->end_date)->toDateString() < $today) {
            return 'انتهت المسابقة، فلم يعد التبرع للفريق متاحاً.';
        }

        return null;
    }

    public function with()
    {
        $student = Auth::guard('student')->user();

        // Supervisor competitions have priority, then the teacher's; see displayedGamification().
        $activeGamification = $this->displayedGamification($student);
        $activeGamification?->loadMissing('circles');

        $gamificationState = null;
        $gamificationLevelInfo = null;
        $freezeableDates = [];
        $nextFreezeUpgrade = null;
        $studentTeam = null;
        $teamRole = null;
        $theme = null;
        $storeItems = collect();
        $studentBadges = [];
        $pendingClaims = collect();
        $allBadges = collect();
        $milestones = collect();
        $claimedMilestones = [];
        $pendingTeamPurchases = collect();
        $teamPurchases = collect();
        $teamShieldActiveToday = false;
        $teamMultiplierActiveToday = false;
        $studentVotes = [];        $teamColor = '#4f46e5';
        $workingDays = [];
        $xpToday = 0;
        $coinsToday = 0;

        if ($activeGamification) {
            // The streak was rebuilt as the page opened; see mount().
            $gamificationState = \App\Models\GamificationStudentState::firstOrCreate([
                'student_id' => $student->id,
                'leaderboard_id' => $activeGamification->id,
            ]);
            $gamificationLevelInfo = \App\Services\GamificationService::getStudentLevel($student->id, $activeGamification->id);

            /*
             * XP and coins the student has earned today, shown as live "today"
             * deltas in the compact stats bar.
             *
             * "Today" starts at midnight in Riyadh, converted to the app's UTC
             * clock before it is compared: bound as it was, the Riyadh clock
             * time was read as UTC, so the day began at 3am and anything earned
             * between midnight and 3am never showed.
             *
             * The coins are the student's own coins only, as the balance beside
             * them is (rows with no team). A donation is written as the donor's
             * 'earn' on the team's side too, so giving 30 coins away used to show
             * as «+30 اليوم» under a balance that had just dropped by 30.
             *
             * XP keeps every row and is counted as the XP total above it is: only
             * rows whose work falls inside the competition's dates
             * (filterTransactionsWithinWindow()). Summed whole, a badge claimed
             * after the end date showed «+50 اليوم» under a total that stayed put.
             */
            $todayEarnings = \App\Models\GamificationTransaction::where('leaderboard_id', $activeGamification->id)
                ->where('student_id', $student->id)
                ->where('type', 'earn')
                ->claimed()
                ->where('created_at', '>=', now('Asia/Riyadh')->startOfDay()->setTimezone(config('app.timezone')))
                ->get(['id', 'team_id', 'reference_type', 'reference_id', 'amount', 'xp_amount', 'created_at']);
            $xpToday = (int) \App\Services\GamificationService::filterTransactionsWithinWindow($todayEarnings, $activeGamification)->sum('xp_amount');
            $coinsToday = (int) $todayEarnings->whereNull('team_id')->sum('amount');

            // The level just read, handed on rather than read again by each helper.
            $freezeableDates = \App\Services\GamificationService::getFreezeableDates($student, $activeGamification, $gamificationLevelInfo);
            $nextFreezeUpgrade = \App\Services\GamificationService::getNextFreezeUpgrade($student, $activeGamification, $gamificationLevelInfo);
            $maxFreezeDays = \App\Services\GamificationService::getStudentMaxFreezeDays($student, $activeGamification, $gamificationLevelInfo);
            $studentTeam = \App\Models\GamificationTeam::whereHas('students', fn($q) => $q->where('users.id', $student->id))
                ->where('leaderboard_id', $activeGamification->id)
                ->first();
            if ($studentTeam) {
                $teamRole = \Illuminate\Support\Facades\DB::table('gamification_team_student')
                    ->where('team_id', $studentTeam->id)
                    ->where('student_id', $student->id)
                    ->value('role');
                    $this->teamColor = $studentTeam->color ?? '#4f46e5';
            }
            $theme = \App\Services\GamificationThemeService::getTheme($activeGamification);
            $storeItems = \App\Models\GamificationStoreItem::where('leaderboard_id', $activeGamification->id)
                ->where('is_active', true)
                ->whereNotIn('item_type', ['multiplier', 'freeze'])
                ->get();
            $studentBadges = \Illuminate\Support\Facades\DB::table('gamification_badge_student')
                ->where('student_id', $student->id)
                ->where('status', 'claimed')
                ->pluck('badge_id')
                ->toArray();
            // Per-badge award status for this student (claimed / approved /
            // pending_approval) so the badges grid can show "awaiting claim" and
            // "awaiting approval" states, not just locked.
            $badgeStatuses = \Illuminate\Support\Facades\DB::table('gamification_badge_student')
                ->where('student_id', $student->id)
                ->pluck('status', 'badge_id')
                ->toArray();
            $pendingClaims = \App\Models\GamificationBadge::join('gamification_badge_student', 'gamification_badges.id', '=', 'gamification_badge_student.badge_id')
                ->where('gamification_badge_student.student_id', $student->id)
                ->where('gamification_badge_student.status', 'approved')
                ->where('gamification_badges.leaderboard_id', $activeGamification->id)
                ->select('gamification_badges.*')
                ->get();
            $allBadges = \App\Models\GamificationBadge::where('leaderboard_id', $activeGamification->id)->get();

            // Progress toward each auto-tracked badge (streak/count) so locked badges
            // show how many consecutive/total achievements are done and how many
            // remain. Working days computed once and reused across all badges.
            $badgeWorkingDays = \App\Services\GamificationService::getWorkingDaysForLeaderboard($activeGamification, $student->effective_stage_id);
            $badgeProgress = [];
            foreach ($allBadges as $b) {
                $val = \App\Services\GamificationService::calculateBadgeValue($b, $student->id, $activeGamification, $badgeWorkingDays);
                if ($val === null) {
                    continue;
                }
                $required = (int) $b->requirement_value;
                $badgeProgress[$b->id] = [
                    'value' => (int) $val,
                    'required' => $required,
                    'remaining' => max(0, $required - (int) $val),
                    'is_streak' => str_starts_with($b->badge_type, 'streak_') || in_array($b->badge_type, ['attendance_streak', 'hifz_streak']),
                ];
            }
            $milestones = \Illuminate\Support\Facades\DB::table('gamification_streak_milestones')
                ->where('leaderboard_id', $activeGamification->id)
                ->orderBy('days_required', 'asc')
                ->get();
            $claimedMilestones = \Illuminate\Support\Facades\DB::table('gamification_claimed_milestones')
                ->where('student_id', $student->id)
                ->where('status', 'claimed')
                ->pluck('milestone_id')
                ->toArray();
            $pendingMilestoneClaims = \Illuminate\Support\Facades\DB::table('gamification_claimed_milestones')
                ->join('gamification_streak_milestones', 'gamification_claimed_milestones.milestone_id', '=', 'gamification_streak_milestones.id')
                ->where('gamification_claimed_milestones.student_id', $student->id)
                ->where('gamification_claimed_milestones.status', 'approved')
                ->where('gamification_streak_milestones.leaderboard_id', $activeGamification->id)
                ->select('gamification_streak_milestones.*', 'gamification_claimed_milestones.id as claim_record_id')
                ->get();
            if ($studentTeam) {
                $pendingTeamPurchases = \App\Models\GamificationStorePurchase::where('team_id', $studentTeam->id)
                    ->where('status', 'pending_approval')
                    ->with(['item', 'student', 'votes.student'])
                    ->get();

                // Approved products bought from the team treasury (shield, multiplier,
                // team points, attacks) shown as the team's purchased-products inventory.
                $teamPurchases = \App\Models\GamificationStorePurchase::where('team_id', $studentTeam->id)
                    ->where('status', 'approved')
                    ->with('item')
                    ->latest()
                    ->get()
                    ->filter(fn ($purchase) => $purchase->item !== null)
                    ->values();

                /*
                 * Whether the team's shield and multiplier hold today, the
                 * academy's today (Riyadh). These are the same checks an attack
                 * and the team's points ask, so the team card's two tiles say
                 * what will actually happen today. The multiplier tile used to
                 * ask by the UTC date, and so read «غير نشط» until 3am on the
                 * day bought for; the shield tile read a column nothing writes
                 * any more and never said «نشط» at all.
                 */
                $teamShieldActiveToday = \App\Services\GamificationService::isShieldActiveForTeam(
                    $studentTeam,
                    Carbon::now('Asia/Riyadh')->toDateString()
                );
                $teamMultiplierActiveToday = \App\Services\GamificationService::isMultiplierActiveForTeam(
                    $studentTeam,
                    Carbon::now('Asia/Riyadh')->toDateString()
                );
            }
            $studentVotes = \Illuminate\Support\Facades\DB::table('gamification_purchase_votes')
                ->where('student_id', $student->id)
                ->pluck('vote', 'store_purchase_id')
                ->toArray();

            $teamColor = $studentTeam ? ($studentTeam->color ?? ($theme['color'] ?? '#4f46e5')) : ($theme['color'] ?? '#4f46e5');

            $style = [
                'bg' => 'bg-slate-50 text-slate-800 min-h-screen rounded-3xl border border-slate-200/80 shadow-lg relative',
                'card' => 'backdrop-blur-md bg-white/95 border border-slate-200/80 rounded-2xl p-5 shadow-sm relative overflow-hidden transition-all duration-300 hover:border-team-primary/50 hover:shadow-md',
                'accent' => 'text-team-primary',
                'progress' => 'bg-team-primary',
                'badge' => 'indigo',
                'badge_active' => 'indigo',
                'badge_locked' => 'zinc',
                'btn' => 'bg-team-primary hover:bg-team-primary-hover text-white font-bold border-none shadow-sm transition-colors duration-150',
                'coin_emoji' => $theme['coin_emoji'] ?? '💎',
                'xp_emoji' => $theme['xp_emoji'] ?? '✨',
                'streak_emoji' => '🔥',
                'team_emoji' => $theme['team_emoji'] ?? '🛡️',
            ];

            // Get all working days for the competition
            $startDate = $activeGamification->start_date;
            $endDate = $activeGamification->end_date;

            $startDateStr = Carbon::parse($startDate)->format('Y-m-d');
            $endDateStr = Carbon::parse($endDate)->format('Y-m-d');

            // Fetch the enthusiasm map for this range in one batch
            $enthusiasmMap = \App\Services\GamificationService::getEnthusiasmMapForRange(
                $student,
                $startDateStr,
                $endDateStr,
                $activeGamification
            );

            // Get working days using the helper method
            $workingDaysDates = \App\Services\GamificationService::getWorkingDaysForLeaderboard($activeGamification, $student->effective_stage_id);

            $multiplierPurchases = \App\Models\GamificationStorePurchase::where('status', 'approved')
                ->where(function($query) use ($student, $studentTeam) {
                    $query->where('student_id', $student->id);
                    if ($studentTeam) {
                        $query->orWhere('team_id', $studentTeam->id);
                    }
                })
                // Bought in this competition: a student in two running competitions
                // used to see the other one's multipliers on this timeline.
                ->whereHas('item', fn($q) => $q->where('item_type', 'multiplier')->where('leaderboard_id', $activeGamification->id))
                ->with('item')
                ->get();

            $dayNumber = 1;
            // The academy's today (Riyadh), as the freeze dates are counted: by the
            // app's UTC clock, from midnight to 3am today's circle was grey, its
            // «تجميد» hidden, and yesterday wore the today ring.
            $todayStr = \App\Services\TeacherSyncSnapshot::today();
            $approvedFreezes = $activeGamification ? \App\Models\GamificationStorePurchase::where('student_id', $student->id)
                ->where('status', 'approved')
                ->whereHas('item', fn($q) => $q->where('is_streak_freeze', true)->where('leaderboard_id', $activeGamification->id))
                ->whereNotNull('target_date')
                ->pluck('target_date')
                ->map(fn($d) => Carbon::parse($d)->format('Y-m-d'))
                ->toArray() : [];

            foreach ($workingDaysDates as $dateStr) {
                $currentDate = Carbon::parse($dateStr);
                $hasEnthusiasm = $enthusiasmMap[$dateStr] ?? false;
                $isFuture = $dateStr > $todayStr;
                $isFrozen = in_array($dateStr, $approvedFreezes);

                if ($isFuture) {
                    $status = 'gray';
                } elseif ($isFrozen) {
                    $status = 'frozen';
                } elseif ($hasEnthusiasm) {
                    $status = 'fiery';
                } else {
                    $status = 'orange';
                }

                $hasIndividualMultiplier = false;
                $hasTeamMultiplier = false;

                foreach ($multiplierPurchases as $p) {
                    $pDate = $p->target_date ? Carbon::parse($p->target_date)->format('Y-m-d') : null;
                    if (!$pDate && $p->item && $p->item->target_date) {
                        $pDate = Carbon::parse($p->item->target_date)->format('Y-m-d');
                    }

                    if ($pDate === $dateStr) {
                        if ($p->team_id) {
                            $hasTeamMultiplier = true;
                        } else {
                            $hasIndividualMultiplier = true;
                        }
                    }
                }

                if ($studentTeam && $studentTeam->multiplier_active_until) {
                    if ($dateStr <= Carbon::parse($studentTeam->multiplier_active_until)->format('Y-m-d')) {
                        $hasTeamMultiplier = true;
                    }
                }

                $workingDays[] = [
                    'number' => $dayNumber++,
                    'date' => $dateStr,
                    'day_name' => \App\Support\HijriDate::weekday($currentDate),
                    'formatted_date' => \App\Support\HijriDate::format($currentDate, 'd MMM'),
                    'is_future' => $isFuture,
                    'is_today' => $dateStr === $todayStr,
                    'has_enthusiasm' => $hasEnthusiasm,
                    'status' => $status,
                    'has_individual_multiplier' => $hasIndividualMultiplier,
                    'has_team_multiplier' => $hasTeamMultiplier,
                ];
            }

            // Map milestones to working days dynamically, from the streak as it
            // stands today: a run broken by a missed working day starts again
            // from today, rather than placing the next milestone on days gone by.
            $currentStreak = \App\Services\GamificationService::liveStreak($gamificationState, $workingDaysDates, $todayStr);
            $lastActivity = $gamificationState->last_activity_date;

            $lastActiveIdx = null;
            if ($lastActivity) {
                $lastActivityStr = \Carbon\Carbon::parse($lastActivity)->format('Y-m-d');
                foreach ($workingDays as $idx => $wd) {
                    if ($wd['date'] === $lastActivityStr) {
                        $lastActiveIdx = $idx;
                        break;
                    }
                }
            }

            if ($currentStreak > 0 && $lastActiveIdx !== null) {
                $streakStartIdx = $lastActiveIdx - $currentStreak + 1;
            } else {
                $streakStartIdx = null;
                foreach ($workingDays as $idx => $wd) {
                    if ($wd['date'] >= $todayStr) {
                        $streakStartIdx = $idx;
                        break;
                    }
                }
                if ($streakStartIdx === null) {
                    $streakStartIdx = 0;
                }
            }

            foreach ($milestones as $m) {
                $mDayIdx = $streakStartIdx + $m->days_required - 1;
                if ($mDayIdx >= 0 && $mDayIdx < count($workingDays)) {
                    $workingDays[$mDayIdx]['milestone'] = $m;
                }
            }
        }

        // Leaderboard standings
        $leaderboardStandings = [];
        $standingsByTrack = collect();
        if ($activeGamification) {
            $service = new \App\Services\LeaderboardService();
            $leaderboardStandings = $service->getStandings($activeGamification);
            // Grouped from the standings above, not worked out a second time.
            $standingsByTrack = $service->getStandingsByTrack($activeGamification, $leaderboardStandings);
        }

        // The student's own rank: within their track when tracks exist, otherwise
        // across the whole standings list.
        $studentRank = null;
        $studentRankTotal = 0;
        $studentRankScope = 'all'; // 'all' | 'track'
        if ($activeGamification) {
            if ($standingsByTrack->isNotEmpty()) {
                foreach ($standingsByTrack as $group) {
                    foreach ($group['standings'] as $s) {
                        if ($s['student']->id === $student->id) {
                            $studentRank = $s['track_rank'];
                            $studentRankTotal = count($group['standings']);
                            $studentRankScope = 'track';
                            break 2;
                        }
                    }
                }
            }
            if ($studentRank === null && ! empty($leaderboardStandings)) {
                $studentRankScope = 'all';
                $studentRankTotal = count($leaderboardStandings);
                foreach ($leaderboardStandings as $index => $s) {
                    if ($s['student']->id === $student->id) {
                        $studentRank = $index + 1;
                        break;
                    }
                }
            }
        }

        // News / daily digest
        $newsDate = $this->newsDate ?: \Carbon\Carbon::today()->toDateString();
        $dailyDigest = $activeGamification ? \App\Services\GamificationNewsService::getDailyDigest($activeGamification->id, $newsDate) : [];
        $availableNewsDates = $activeGamification ? \App\Services\GamificationNewsService::getAvailableDates($activeGamification->id) : [];

        // Fetch Earliest Pending Missions (one per active approved plan)
        $activeApprovedPlans = \App\Models\StudentPlan::where('student_id', $student->id)->where('status', 'active')->where('is_approved', 1)->get();

        /*
         * Hifz and review advance at their own pace, so each gets its own
         * earliest ungraded day. Taking the single earliest day with either
         * part ungraded showed only whichever was further behind, and hid the
         * other one entirely. See the same note on the main dashboard.
         */
        $pendingMissions = [];
        foreach ($activeApprovedPlans as $plan) {
            foreach (['hifz', 'review'] as $part) {
                $day = \App\Models\StudentPlanDay::with(['fromAyah.surah', 'toAyah.surah', 'reviewFromAyah.surah', 'reviewToAyah.surah'])
                    ->where('student_plan_id', $plan->id)
                    // A «لم يسمع» (0) was not recited, so the part is still pending.
                    ->where(fn ($q) => $q->whereNull($part.'_achievement')->orWhere($part.'_achievement', 0))
                    ->orderBy('date', 'asc')
                    ->first();

                if (! $day) {
                    continue;
                }

                $day->setRelation('plan', $plan);
                $day->pendingPart = $part;
                $pendingMissions[] = $day;
            }
        }

        // Fetch Earliest Pending Hadith Missions (one per active Hadith plan)
        $activeHadithPlans = \App\Models\StudentHadithPlan::where('student_id', $student->id)->where('status', 'active')->get();

        $activeOdePlans = \App\Models\StudentOdePlan::where('student_id', $student->id)
            ->where('status', 'active')->with('path.ode')->get();
        $pendingHadithMissions = [];
        foreach ($activeHadithPlans as $plan) {
            $mission = \App\Models\HadithPathDay::with(['fromHadith', 'toHadith', 'reviewFromHadith', 'reviewToHadith'])
                ->where('hadith_path_id', $plan->hadith_path_id)
                ->where(function ($query) use ($plan) {
                    $query->whereDoesntHave('achievements', function ($q) use ($plan) {
                        $q->where('student_hadith_plan_id', $plan->id);
                    })
                    ->orWhereHas('achievements', function ($q) use ($plan) {
                        $q->where('student_hadith_plan_id', $plan->id)
                          ->where(function ($sub) {
                              $sub->where(function ($h) {
                                  $h->whereNotNull('hadith_path_days.from_hadith_id')
                                    ->whereNull('student_hadith_achievements.hifz_achievement');
                              })
                              ->orWhere(function ($r) {
                                  $r->whereNotNull('hadith_path_days.review_from_hadith_id')
                                    ->whereNull('student_hadith_achievements.review_achievement');
                              });
                          });
                    });
                })
                ->orderBy('day_number', 'asc')
                ->first();

            if ($mission) {
                $mission->setRelation('plan', $plan);

                $achievement = \App\Models\StudentHadithAchievement::where('student_hadith_plan_id', $plan->id)
                    ->where('hadith_path_day_id', $mission->id)
                    ->first();

                $mission->hifz_achievement = $achievement?->hifz_achievement;
                $mission->review_achievement = $achievement?->review_achievement;
                $mission->hifz_graded_at = $achievement?->hifz_graded_at;
                $mission->review_graded_at = $achievement?->review_graded_at;
                
                // Fetch all hadiths for this plan's path to allow showing text and previous texts
                $allHadiths = \App\Models\Hadith::with('lines')->where(function ($query) use ($plan) {
                    $query->where('hadith_text_id', $plan->path->hadith_text_id)
                        ->orWhereHas('chapter', function ($q) use ($plan) {
                            $q->where('hadith_text_id', $plan->path->hadith_text_id);
                        });
                })->orderBy('hadith_chapter_id', 'asc')->orderBy('id', 'asc')->get();
                
                $mission->allHadiths = $allHadiths;
                $pendingHadithMissions[] = $mission;
            }
        }

        // Ranked team standings (with today's points + today's enthusiasm-day count).
        // Today is the academy's (Riyadh): left to the service's UTC default, the
        // two "today" columns showed yesterday's figures from midnight to 3am. The
        // date names only those columns; the totals and ranks don't read it.
        $teamStandings = [];
        if ($activeGamification) {
            $teamStandings = \App\Services\GamificationService::getTeamStandings($activeGamification, \App\Services\TeacherSyncSnapshot::today());
        }

        $teamStudents = collect();
        $teamStudentStates = collect();
        $teamStudentStreaks = [];
        $teamScore = 0;
        $otherTeams = collect();
        $teamTaskTransactions = collect();

        if ($activeGamification && $studentTeam) {
            $teamStudents = $studentTeam->students()->with('circle')->get();
            $teamStudentIds = $teamStudents->pluck('id')->toArray();
            $teamStudentStates = \App\Models\GamificationStudentState::whereIn('student_id', $teamStudentIds)
                ->where('leaderboard_id', $activeGamification->id)
                ->get()
                ->keyBy('student_id');

            /*
             * Each member's «الحماسة» as it stands today, counted on their own
             * stage's working days. The stored streak is only rebuilt when the
             * member is marked or opens their page, so it used to keep showing a
             * run broken days ago.
             */
            $workingDaysByStage = [(string) $student->effective_stage_id => $workingDaysDates ?? []];
            foreach ($teamStudents as $teammate) {
                $stageKey = (string) $teammate->effective_stage_id;
                $workingDaysByStage[$stageKey] ??= \App\Services\GamificationService::getWorkingDaysForLeaderboard($activeGamification, $teammate->effective_stage_id);
                $teamStudentStreaks[$teammate->id] = \App\Services\GamificationService::liveStreak(
                    $teamStudentStates->get($teammate->id),
                    $workingDaysByStage[$stageKey],
                );
            }

            // The team's total is its row in the standings just read, the same
            // figure, rather than every one of its transactions read again.
            $ownStanding = collect($teamStandings)->first(fn (array $row) => $row['team']->id === $studentTeam->id);
            $teamScore = $ownStanding
                ? (int) $ownStanding['score']
                : \App\Services\GamificationService::getTeamScore($studentTeam, $activeGamification);
        }

        // The teams a team attack may target, read once for the store rather than
        // once per attack product.
        if ($activeGamification && $storeItems->contains('item_type', 'team_attack')) {
            $otherTeams = \App\Models\GamificationTeam::where('leaderboard_id', $activeGamification->id)
                ->where('id', '!=', $studentTeam?->id ?? 0)
                ->get();
        }

        $teamTasks = collect();
        if ($activeGamification && $studentTeam) {
            $teamTasks = \App\Models\GamificationTeamTaskAssignment::with(['task.criteria', 'scores.criterion'])
                ->where('team_id', $studentTeam->id)
                ->whereHas('task', function ($query) use ($activeGamification) {
                    $query->where('leaderboard_id', $activeGamification->id);
                })
                ->orderBy('start_date', 'asc')
                ->get();

            // What each graded task paid, read once for the list: the earliest
            // transaction for each assignment, as the list used to read one by one.
            $teamTaskTransactions = $teamTasks->isEmpty() ? collect() : \App\Models\GamificationTransaction::where('reference_type', \App\Models\GamificationTeamTaskAssignment::class)
                ->whereIn('reference_id', $teamTasks->pluck('id'))
                ->orderBy('id')
                ->get()
                ->unique('reference_id')
                ->keyBy('reference_id');
        }

        $allActivityRounds = collect();
        if ($activeGamification) {
            // The activity's ranks too: the podium places a renamed rank by its order among them.
            $allActivityRounds = \App\Models\GamificationActivityRound::with(['activity.ranks', 'winners.rank', 'winners.team'])
                ->whereHas('activity', function ($query) use ($activeGamification) {
                    $query->where('leaderboard_id', $activeGamification->id);
                })
                ->orderBy('round_date', 'asc')
                ->get();
        }

        /*
         * The multipliers still to come or running today, for the strip under the
         * streak: those bought for today or later, or for no set day. Picked out
         * of the purchases already read for the timeline instead of read again.
         */
        $activeMultipliers = collect();
        if ($activeGamification) {
            $multiplierToday = Carbon::now('Asia/Riyadh')->toDateString();
            $activeMultipliers = $multiplierPurchases
                ->filter(fn ($purchase) => $purchase->target_date === null
                    || Carbon::parse($purchase->target_date)->toDateString() >= $multiplierToday)
                ->values();
        }

        // The donation allowance on the team tab, from the level read above.
        $donationStatus = ($activeGamification && $studentTeam)
            ? \App\Services\GamificationService::getDailyDonationStatus($student->id, $activeGamification->id, $gamificationLevelInfo)
            : ['has_donation' => false, 'percentage' => 0, 'base' => 0, 'limit' => 0, 'donated' => 0, 'remaining' => 0];

        // Turn Reservation: let students book their tasmeeh turn from within the competition view too.
        $todayStr = \Carbon\Carbon::now('Asia/Riyadh')->format('Y-m-d');
        $activeSession = null;
        if ($student->circle_id) {
            $teacherIds = \Illuminate\Support\Facades\DB::table('circle_teacher')
                ->where('circle_id', $student->circle_id)
                ->pluck('teacher_id');

            $sessions = \App\Models\TurnReservationSession::whereIn('teacher_id', $teacherIds)->get();
            foreach ($sessions as $session) {
                if ($session->isActiveToday()) {
                    $activeSession = $session;
                    break;
                }
            }
        }

        $studentReservation = null;
        if ($activeSession) {
            $studentReservation = \App\Models\TurnReservation::where('turn_reservation_session_id', $activeSession->id)
                ->whereDate('date', $todayStr)
                ->where('student_id', $student->id)
                ->first();
        }

        return [
            'activeSession' => $activeSession,
            'studentReservation' => $studentReservation,
            'student' => $student,
            'activeGamification' => $activeGamification,
            'gamificationState' => $gamificationState,
            'gamificationLevelInfo' => $gamificationLevelInfo,
            // 0 below a first level that needs XP; see getStudentLevel().
            'studentLevel' => $gamificationLevelInfo['current']?->level_number ?? 0,
            'xpToday' => $xpToday,
            'coinsToday' => $coinsToday,
            'studentTeam' => $studentTeam,
            'teamRole' => $teamRole,
            'theme' => $theme,
            'allActivityRounds' => $allActivityRounds,
            'storeItems' => $storeItems,
            'studentBadges' => $studentBadges,
            'pendingClaims' => $pendingClaims,
            'pendingMilestoneClaims' => $pendingMilestoneClaims ?? collect(),
            'allBadges' => $allBadges,
            'badgeProgress' => $badgeProgress ?? [],
            'badgeStatuses' => $badgeStatuses ?? [],
            'milestones' => $milestones,
            'claimedMilestones' => $claimedMilestones,
            'pendingTeamPurchases' => $pendingTeamPurchases,
            'teamPurchases' => $teamPurchases,
            'teamShieldActiveToday' => $teamShieldActiveToday,
            'teamMultiplierActiveToday' => $teamMultiplierActiveToday,
            'studentVotes' => $studentVotes,
            'style' => $style,
            'teamColor' => $teamColor,
            'leaderboardStandings' => $leaderboardStandings,
            'standingsByTrack' => $standingsByTrack,
            'studentRank' => $studentRank,
            'studentRankTotal' => $studentRankTotal,
            'studentRankScope' => $studentRankScope,
            'dailyDigest' => $dailyDigest,
            'availableNewsDates' => $availableNewsDates,
            'newsDate' => $newsDate,
            'studentXP' => $gamificationLevelInfo['xp'] ?? 0,
            'pendingRewards' => $activeGamification ? \App\Services\GamificationService::getPendingRewards($student->id, $activeGamification->id) : collect(),
            'pendingMissions' => $pendingMissions,
            'pendingHadithMissions' => $pendingHadithMissions,
            'activeApprovedPlans' => $activeApprovedPlans,
            'activeHadithPlans' => $activeHadithPlans,
            'activeOdePlans' => $activeOdePlans,
            'teamStandings' => $teamStandings,
            'teamStudents' => $teamStudents,
            'teamStudentStates' => $teamStudentStates,
            'teamStudentStreaks' => $teamStudentStreaks,
            'teamScore' => $teamScore,
            'workingDays' => $workingDays,
            'currentStreak' => $currentStreak ?? 0,
            'freezeableDates' => $freezeableDates,
            'nextFreezeUpgrade' => $nextFreezeUpgrade,
            'maxFreezeDays' => $maxFreezeDays ?? 1,
            'teamTasks' => $teamTasks,
            'teamTaskTransactions' => $teamTaskTransactions,
            'otherTeams' => $otherTeams,
            'activeMultipliers' => $activeMultipliers,
            'donationStatus' => $donationStatus,
            'donationClosedReason' => $activeGamification ? $this->donationClosedReason($activeGamification) : null,
        ];
    }

    public function reserveTurn($sessionId)
    {
        $student = Auth::guard('student')->user();
        $session = \App\Models\TurnReservationSession::find($sessionId);

        if (!$session || !$session->isActiveNow()) {
            Flux::toast('عذراً، وقت الحجز غير متاح حالياً.', variant: 'danger');
            return;
        }

        $todayStr = \Carbon\Carbon::now('Asia/Riyadh')->format('Y-m-d');

        // Students booking at the same moment each get their own number; see reserveNext().
        $reservation = \App\Models\TurnReservation::reserveNext($session->id, $student->id, $todayStr);

        if (! $reservation) {
            Flux::toast('الحجز مزدحم الآن، حاول مرة أخرى بعد لحظات.', variant: 'danger');
            return;
        }

        if ($reservation->wasRecentlyCreated) {
            Flux::toast('تم حجز دورك بنجاح!', variant: 'success');
        }
    }

    public function cancelTurn($sessionId)
    {
        $student = Auth::guard('student')->user();
        $todayStr = \Carbon\Carbon::now('Asia/Riyadh')->format('Y-m-d');

        \App\Models\TurnReservation::where('turn_reservation_session_id', $sessionId)
            ->whereDate('date', $todayStr)
            ->where('student_id', $student->id)
            ->delete();

        Flux::toast('تم إلغاء حجزك بنجاح.', variant: 'success');
    }

    public function claimBadge($badgeId)
    {
        $student = Auth::guard('student')->user();
        $pivot = \Illuminate\Support\Facades\DB::table('gamification_badge_student')
            ->where('student_id', $student->id)
            ->where('badge_id', $badgeId)
            ->where('status', 'approved')
            ->first();

        if (!$pivot) {
            Flux::toast('لا يوجد وسام معتمد بانتظار الاستلام.', variant: 'danger');
            return;
        }

        $badge = \App\Models\GamificationBadge::findOrFail($badgeId);

        /**
         * The reward belongs to the competition that issued the badge.
         *
         * It used to go to whichever competition happened to be running for the
         * student, which is a different question with a different answer: a
         * student in two competitions would have been paid by the wrong one,
         * and once the competition ended there was no running one at all — so
         * the badge was marked taken, the student was congratulated, and
         * nothing was ever granted.
         *
         * The transaction is keyed by the badge, so a retry after a failure
         * grants it once; and the badge is only marked taken afterwards, so a
         * failure leaves it claimable rather than spent.
         */
        if ($badge->reward_xp > 0 || $badge->reward_coins > 0) {
            \App\Models\GamificationTransaction::firstOrCreate(
                [
                    'leaderboard_id' => $badge->leaderboard_id,
                    'student_id' => $student->id,
                    'reference_type' => \App\Models\GamificationBadge::class,
                    'reference_id' => $badge->id,
                ],
                [
                    'type' => 'earn',
                    'amount' => $badge->reward_coins,
                    'xp_amount' => $badge->reward_xp,
                    'description' => "مكافأة الحصول على وسام: {$badge->name} 🏅 (+{$badge->reward_xp} XP)",
                ]
            );

            \App\Services\GamificationService::recalculateStudentState($student->id, $badge->leaderboard_id);
        }

        \Illuminate\Support\Facades\DB::table('gamification_badge_student')
            ->where('student_id', $student->id)
            ->where('badge_id', $badgeId)
            ->update([
                'status' => 'claimed',
                'updated_at' => now(),
            ]);

        Flux::toast("مبروك! لقد حصلت على وسام {$badge->name}", variant: 'success');
    }

    /**
     * Take a reached streak milestone's reward.
     *
     * The button names the milestone, not its claim row. The rows are deleted
     * and written again whenever the streak is recalculated (on a render here,
     * and whenever a teacher grades or marks the student), so they come back
     * with new ids: a row id held by an open modal went stale, and the first tap
     * answered that there was no reward to take. The milestone's id does not
     * change; its earliest row still awaiting a claim is the one claimed.
     *
     * The claim is one transaction, and its row is marked claimed before the
     * reward is written, by an update only one request can win. A streak
     * rebuild (itself one transaction) can then land only before or after it,
     * never between the row being read and marked: in between, the mark fell on
     * a row the rebuild had just replaced, and the student was congratulated on
     * a reward the rebuild had wiped. Retried, as SQLite refuses a writer whose
     * reads began before another writer's commit.
     */
    public function claimMilestone($milestoneId)
    {
        $student = Auth::guard('student')->user();

        [$outcome, $milestone] = \Illuminate\Support\Facades\DB::transaction(function () use ($student, $milestoneId): array {
            $pivot = \Illuminate\Support\Facades\DB::table('gamification_claimed_milestones')
                ->where('student_id', $student->id)
                ->where('milestone_id', (int) $milestoneId)
                ->where('status', 'approved')
                ->orderBy('created_at')
                ->orderBy('id')
                ->first();

            if (! $pivot) {
                return ['nothing_to_claim', null];
            }

            $milestone = \Illuminate\Support\Facades\DB::table('gamification_streak_milestones')->where('id', $pivot->milestone_id)->first();
            if (! $milestone) {
                return ['unknown_milestone', null];
            }

            $marked = \Illuminate\Support\Facades\DB::table('gamification_claimed_milestones')
                ->where('id', $pivot->id)
                ->where('status', 'approved')
                ->update([
                    'status' => 'claimed',
                    'updated_at' => now(),
                ]) === 1;

            if (! $marked) {
                return ['nothing_to_claim', null];
            }

            // The same as the badge above: the reward belongs to the competition
            // that set the milestone, and is keyed by this claim — a student may
            // reach the same milestone again on a later streak run.
            if ($milestone->reward_xp > 0 || $milestone->reward_coins > 0) {
                \App\Models\GamificationTransaction::firstOrCreate(
                    [
                        'leaderboard_id' => $milestone->leaderboard_id,
                        'student_id' => $student->id,
                        'reference_type' => \App\Models\GamificationStreakMilestone::class,
                        'reference_id' => $pivot->id,
                    ],
                    [
                        'type' => 'earn',
                        'amount' => $milestone->reward_coins,
                        'xp_amount' => $milestone->reward_xp,
                        'description' => "مكافأة أيام الحماسة لـ {$milestone->days_required} أيام متتالية: {$milestone->description}",
                    ]
                );

                \App\Services\GamificationService::recalculateStudentState($student->id, $milestone->leaderboard_id);
            }

            return ['claimed', $milestone];
        }, 3);

        if ($outcome === 'nothing_to_claim') {
            Flux::toast('لا يوجد جائزة معتمدة بانتظار الاستلام.', variant: 'danger');
            return;
        }

        if ($outcome === 'unknown_milestone') {
            Flux::toast('لم يتم العثور على الجائزة المطلوبة.', variant: 'danger');
            return;
        }

        // The page's redraw used to rebuild the streak straight after the claim,
        // which writes the reward again in the rebuild's own form, dated on the
        // day the streak reached the milestone. The redraw no longer rebuilds
        // (see mount()), so the claim does.
        if ($leaderboard = $this->displayedGamification($student)) {
            \App\Services\GamificationService::recalculateStudentStreak($student, $leaderboard);
        }

        Flux::toast("مبروك! لقد استلمت جائزة الحماسة {$milestone->days_required} أيام بنجاح", variant: 'success');
    }

    /**
     * Buy a product from the store tab.
     *
     * Only what the store shows can be bought here: an active product of the
     * competition on screen, other than the multiplier and the streak freeze.
     * Those two have their own buttons, which check that the student's level
     * allows them and charge the level's price; reached through here with a
     * hand-typed id, they skipped both and cost the item's fixed price, even
     * with the product turned off by the supervisor.
     */
    public function buyItem($itemId)
    {
        $student = Auth::guard('student')->user();
        $leaderboard = $this->displayedGamification($student);

        $item = $leaderboard
            ? \App\Models\GamificationStoreItem::where('leaderboard_id', $leaderboard->id)
                ->where('is_active', true)
                ->whereNotIn('item_type', ['multiplier', 'freeze'])
                ->find((int) $itemId)
            : null;

        if (! $item) {
            Flux::toast('هذا المنتج غير متوفر حالياً في المتجر.', variant: 'danger');
            return;
        }

        $itemId = $item->id;
        $targetTeamId = filled($this->targetTeams[$itemId] ?? null) ? (int) $this->targetTeams[$itemId] : null;
        $targetDate = filled($this->targetDates[$itemId] ?? null) ? (string) $this->targetDates[$itemId] : null;

        $status = \App\Services\GamificationService::requestStorePurchase($student->id, $itemId, $targetTeamId, $targetDate);

        if ($status === 'insufficient_coins') {
            Flux::toast('رصيدك أو رصيد فريقك غير كافٍ للشراء.', variant: 'danger');
        } elseif ($status === 'pending_voting') {
            Flux::toast('تم إرسال طلب الشراء. بانتظار تصويت أعضاء الفريق!', variant: 'success');
        } elseif ($status === 'approved' || $status === 'success') {
            Flux::toast('تم الشراء وتفعيل ميزة المتجر بنجاح!', variant: 'success');
            unset($this->targetTeams[$itemId]);
            unset($this->targetDates[$itemId]);
        } elseif ($status === 'must_be_in_team') {
            Flux::toast('يجب أن تكون في فريق لشراء هذا المنتج.', variant: 'danger');
        } elseif ($status === 'only_leader') {
            Flux::toast('فقط قائد المجموعة يمكنه شراء منتجات المجموعة وتحديد تفاصيلها.', variant: 'danger');
        } elseif ($status === 'insufficient_team_coins') {
            Flux::toast('رصيد الفريق غير كافٍ لشراء هذا المنتج.', variant: 'danger');
        } elseif ($status === 'target_team_required') {
            Flux::toast('الرجاء اختيار المجموعة المستهدفة للخصم.', variant: 'danger');
        } elseif ($status === 'target_date_required') {
            Flux::toast('الرجاء اختيار تاريخ تفعيل الميزة.', variant: 'danger');
        } elseif ($status === 'invalid_target_date') {
            Flux::toast('تاريخ التفعيل يجب أن يكون من يوم الغد فصاعداً.', variant: 'danger');
        } elseif ($status === 'multiplier_already_purchased') {
            Flux::toast('خطأ: تم بالفعل شراء أو تفعيل مضاعف نقاط لهذا التاريخ.', variant: 'danger');
        } elseif ($status === 'item_inactive') {
            Flux::toast('هذا المنتج غير متوفر حالياً في المتجر.', variant: 'danger');
        } elseif ($status === 'cannot_attack_own_team' || $status === 'invalid_target_team') {
            Flux::toast('الرجاء اختيار مجموعة منافسة صحيحة للخصم.', variant: 'danger');
        } else {
            Flux::toast('تعذر إتمام عملية الشراء.', variant: 'danger');
        }
    }

    public function votePurchase($purchaseId, $vote)
    {
        $student = Auth::guard('student')->user();
        $success = \App\Services\GamificationService::voteForPurchase($student->id, $purchaseId, (bool)$vote);

        if ($success) {
            Flux::toast('تم تسجيل تصويتك بنجاح.', variant: 'success');
        } else {
            Flux::toast('تعذر تسجيل التصويت.', variant: 'danger');
        }
    }

    public function claimReward($transactionId)
    {
        $student = Auth::guard('student')->user();
        $tx = \App\Models\GamificationTransaction::where('id', (int) $transactionId)
            ->where('student_id', $student->id)
            ->first();
        $xp = (int) ($tx->xp_amount ?? 0);

        if (\App\Services\GamificationService::claimReward((int) $transactionId, $student->id)) {
            Flux::toast('تم استلام المكافأة!', variant: 'success');
            $this->dispatch('reward-claimed', xp: $xp);
        }
    }

    /**
     * Claim every reward the panel lists, in the competition on screen, the
     * same one each row's «استلام» claims in. The button's particles have flown
     * by the time this answers, so it always says what happened.
     */
    public function claimAllRewards()
    {
        $student = Auth::guard('student')->user();
        $leaderboard = $this->displayedGamification($student);
        if (! $leaderboard) {
            Flux::toast('لا توجد مسابقة نشطة حالياً.', variant: 'danger');
            return;
        }
        $pendingXp = (int) \App\Services\GamificationService::getPendingRewards($student->id, $leaderboard->id)->sum('xp_amount');
        $count = \App\Services\GamificationService::claimAllRewards($student->id, $leaderboard->id);
        if ($count > 0) {
            Flux::toast("تم استلام {$count} مكافأة!", variant: 'success');
            $this->dispatch('reward-claimed', xp: $pendingXp);
        } else {
            Flux::toast('لا توجد مكافآت بانتظار الاستلام.', variant: 'warning');
        }
    }

    public function donateToTeam($amount)
    {
        \Illuminate\Support\Facades\Log::info("donateToTeam triggered on server with amount: " . $amount);
        $amount = (int)$amount;
        if ($amount <= 0) {
            Flux::toast('الرجاء إدخال مبلغ صحيح للتبرع.', variant: 'danger');
            $this->dispatch('donation-finished');
            return;
        }

        $student = Auth::guard('student')->user();
        $leaderboard = $this->displayedGamification($student);
        if (!$leaderboard) {
            Flux::toast('لا توجد مسابقة نشطة حالياً.', variant: 'danger');
            $this->dispatch('donation-finished');
            return;
        }

        // Closed outside the competition's dates, saying so, as the page does.
        if ($closedReason = $this->donationClosedReason($leaderboard)) {
            Flux::toast($closedReason, variant: 'warning');
            $this->dispatch('donation-finished');
            return;
        }

        $team = \App\Models\GamificationTeam::whereHas('students', fn($q) => $q->where('users.id', $student->id))
            ->where('leaderboard_id', $leaderboard->id)
            ->first();

        if (!$team) {
            Flux::toast('أنت غير مسجل في أي فريق حالياً.', variant: 'danger');
            $this->dispatch('donation-finished');
            return;
        }

        $error = null;
        $success = \App\Services\GamificationService::donateCoinsToTeam($student->id, $team->id, (int)$amount, $error);
        if ($success) {
            Flux::toast('تم التبرع بنجاح للفريق!', variant: 'success');
            $this->dispatch('donation-successful');
        } else {
            Flux::toast($error ?: 'رصيدك غير كافٍ للتبرع.', variant: 'danger');
        }
        $this->dispatch('donation-finished');
    }

    public function testMethod()
    {
        Flux::toast('تم استدعاء دالة التجربة بنجاح!', variant: 'success');
        \Illuminate\Support\Facades\Log::info("testMethod triggered successfully on server");
    }

    public function renderEmoji($emoji, $class = 'size-5 inline-block align-middle')
    {
        if (str_contains((string)$emoji, '/') || str_ends_with((string)$emoji, '.webp')) {
            $url = \Illuminate\Support\Facades\Storage::url($emoji);
            return '<img src="' . e($url) . '" class="' . e($class) . '" alt="" />';
        }

        $cleanEmoji = trim((string)$emoji);

        switch ($cleanEmoji) {
            case '💎':
            case '🪙':
                return '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="text-amber-500 fill-amber-500/10 ' . $class . '">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v12m-3-2.818.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                        </svg>';
            case '🦪':
                return '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="text-teal-600 fill-teal-500/10 ' . $class . '">
                            <circle cx="12" cy="12" r="9" />
                            <circle cx="12" cy="12" r="3.5" class="fill-white" />
                        </svg>';
            case '✨':
                return '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="text-indigo-500 fill-indigo-500/10 ' . $class . '">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904L9 21l-1.813-5.096L2.091 14.09 7.187 12.28 9 7.187l1.813 5.093 5.096 1.813-5.096 1.811z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19.071 4.929l-.929 2.071-2.071.929 2.071.929.929 2.071.929-2.071 2.071-.929-2.071-.929z" />
                        </svg>';
            case '🌊':
                return '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="text-sky-500 ' . $class . '">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M2 17c4-2 8-2 12 0s8 2 12 0M2 12c4-2 8-2 12 0s8 2 12 0" />
                        </svg>';
            case '🌌':
                return '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="text-purple-500 fill-purple-500/10 ' . $class . '">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364-6.364l-.707.707M6.343 17.657l-.707.707m0-12.728l.707.707m12.02 12.02l.707.707M12 8a4 4 0 100 8 4 4 0 000-8z" />
                        </svg>';
            case '🔥':
                return '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="text-orange-500 fill-orange-500/10 ' . $class . '">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15.362 5.214A8.252 8.252 0 0 1 12 21 8.25 8.25 0 0 1 6.038 7.047 8.287 8.287 0 0 0 9 9.601a8.983 8.983 0 0 1 3.361-6.867 8.21 8.21 0 0 0 3 2.48Z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 18a3.75 3.75 0 0 0 .495-7.467 5.99 5.99 0 0 0-1.925 3.546 5.974 5.974 0 0 1-2.133-1A3.75 3.75 0 0 0 12 18Z" />
                        </svg>';
            case '⚔️':
                return '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="text-amber-600 ' . $class . '">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 20L20 4M14 4l6 6M4 20l6-6M4 20l-1-1M20 4l1 1" />
                        </svg>';
            case '🛡️':
                return '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="text-blue-500 fill-blue-500/10 ' . $class . '">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.57-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z" />
                        </svg>';
            default:
                return e($emoji);
        }
    }

    protected function getHijriLabel(\DateTimeInterface|string $date)
    {
        $parsed = is_string($date) ? \Carbon\Carbon::parse($date) : $date;
        return \App\Support\HijriDate::full($parsed->getTimestamp());
    }
};
?>

<div>
@if($activeGamification)
@php
    // The tabs this student has, the same list the phone's bottom bar draws.
    $gamTabs = \App\Support\StudentGamificationTabs::for($studentTeam, $theme);
@endphp
{{--
    Which tab is open. The sidebar's «الإنجازات» and «المتصدرون» point here by
    fragment. On the plain dashboard those are anchors to scroll to; here the
    same content lives in a tab, so the fragment opens the tab instead of
    scrolling to nothing, and «المتصدرون» then scrolls down to the table. The
    fragment leaves the address once read: left there, every later reload
    reopened the link's tab whatever the student had chosen since.

    Without a fragment the tab last chosen reopens, but only if this student
    still has it. A student moved off their team, or a new competition with no
    teams yet, would reopen «فريقي» onto a blank page, its panel not drawn.

    The choice is remembered here and announced as gamnav-changed, which the
    phone's bottom bar follows for its highlight; every switcher sends the same
    event, so the two never disagree on the open tab.
--}}
<div x-data="{
        tabs: @js(array_column($gamTabs, 'tab')),
        fragments: { '#achievements': 'badges', '#leaderboard-standings': 'leaderboard' },
        currentTab: 'leaderboard',
        init() {
            let remembered = null;
            try { remembered = localStorage.getItem('student-gam-tab'); } catch (e) {}
            this.show(remembered);
            this.followFragment();
            this.$nextTick(() => this.announce());
        },
        show(tab) {
            this.currentTab = this.tabs.includes(tab) ? tab : 'leaderboard';
            try { localStorage.setItem('student-gam-tab', this.currentTab); } catch (e) {}
        },
        followFragment() {
            const fragment = window.location.hash;
            const tab = this.fragments[fragment];
            if (! tab) { return; }
            this.show(tab);
            history.replaceState(history.state, '', window.location.pathname + window.location.search);
            if (fragment === '#leaderboard-standings') { this.$nextTick(() => this.scrollToStandings('instant')); }
        },
        announce() {
            window.dispatchEvent(new CustomEvent('gamnav-changed', { detail: { tab: this.currentTab } }));
        },
        scrollToStandings(behavior = 'smooth') {
            document.getElementById('leaderboard-standings')?.scrollIntoView({ behavior, block: 'start' });
        },
    }"
    x-on:gamnav-changed.window="if (tabs.includes($event.detail.tab)) { show($event.detail.tab); }"
    x-on:hashchange.window="followFragment(); announce();"
    class="{{ $style['bg'] }} font-sans" dir="rtl" style="background-image: radial-gradient(circle at top left, var(--team-color-10) 0%, transparent 45%), radial-gradient(circle at bottom right, var(--team-color-10) 0%, transparent 45%); background-color: #f8fafc;">
<style>
    :root {
        --team-color: {{ $teamColor ?? '#4f46e5' }};
        --team-color-hover: {{ ($teamColor ?? '#4f46e5') }}dd;
        --team-color-10: {{ ($teamColor ?? '#4f46e5') }}0f;
        --team-color-20: {{ ($teamColor ?? '#4f46e5') }}26;
        --team-color-30: {{ ($teamColor ?? '#4f46e5') }}40;
    }
    .bg-team-primary { background-color: <?php echo $teamColor ?> !important; }
    .hover\:bg-team-primary-hover:hover { background-color: var(--team-color-hover) !important; }
    .text-team-primary { color: var(--team-color) !important; }
    .border-team-primary { border-color: var(--team-color) !important; }
    .bg-team-10 { background-color: var(--team-color-10) !important; }
    .bg-team-20 { background-color: var(--team-color-20) !important; }
    .bg-team-30 { background-color: var(--team-color-30) !important; }
    .border-team-10 { border-color: var(--team-color-10) !important; }
    .border-team-20 { border-color: var(--team-color-20) !important; }
    
    /* Scrollbar utilities for mobile scrollable timelines/tabs */
    .scrollbar-none::-webkit-scrollbar { display: none; }
    .scrollbar-none { -ms-overflow-style: none; scrollbar-width: none; }
    
    .scrollbar-thin::-webkit-scrollbar { height: 4px; }
    .scrollbar-thin::-webkit-scrollbar-track { background: #f1f5f9; border-radius: 4px; }
    .scrollbar-thin::-webkit-scrollbar-thumb { background: var(--team-color); border-radius: 4px; opacity: 0.3; }

    /* Reward-claim animations: particle flash + value pop */
    @keyframes gam-flash {
        0% { box-shadow: 0 0 0 0 var(--team-color-30); transform: scaleY(1); }
        40% { box-shadow: 0 0 10px 3px var(--team-color); transform: scaleY(2.2); }
        100% { box-shadow: 0 0 0 0 transparent; transform: scaleY(1); }
    }
    .gam-flash { animation: gam-flash 0.6s ease-out; }

    @keyframes gam-pop {
        0% { transform: scale(1); }
        45% { transform: scale(1.45); color: #059669; }
        100% { transform: scale(1); }
    }
    .gam-pop { animation: gam-pop 0.5s ease-out; display: inline-block; }

    .gam-xp-particle {
        position: fixed; z-index: 9999; pointer-events: none;
        width: 12px; height: 12px; border-radius: 9999px;
        background: radial-gradient(circle at 30% 30%, #fff, var(--team-color));
        box-shadow: 0 0 8px 1px var(--team-color);
        will-change: transform, opacity;
    }
</style>
    @if($pendingClaims->isNotEmpty())
        @php
            $claim = $pendingClaims->first();
        @endphp
        {{--
            The claim can't be dismissed, so it must always be reachable. It sits
            above the phone's bottom bar (z-[9999]), which used to cover its button
            on short screens, and it scrolls when the box is taller than the screen.
            The box is centred by m-auto rather than by the wrapper: a flex-centred
            box taller than its wrapper spills off the top, out of scroll's reach.
        --}}
        <div data-claim-overlay class="fixed inset-0 z-[10000] flex overflow-y-auto overscroll-contain p-4 backdrop-blur-xl bg-slate-900/60">
            <div class="relative max-w-md w-full m-auto rounded-3xl p-6 md:p-8 border border-slate-200 bg-white shadow-2xl text-center space-y-4 sm:space-y-6 overflow-hidden">
                <!-- Sparkle Background Effects using team color -->
                <div class="absolute -top-12 -left-12 size-40 rounded-full bg-team-10 blur-3xl"></div>
                <div class="absolute -bottom-12 -right-12 size-40 rounded-full bg-team-10 blur-3xl"></div>

                <!-- Animated Glow behind badge -->
                <div class="relative flex justify-center py-2 sm:py-4">
                    <div class="absolute inset-0 size-32 mx-auto rounded-full bg-team-20 blur-xl animate-pulse"></div>
                    <div class="relative size-28 rounded-full bg-team-10 flex items-center justify-center border-2 border-team-primary shadow-lg overflow-hidden">
                        @if(str_contains($claim->icon, '/') || str_contains($claim->icon, '.'))
                            <img src="{{ asset('storage/' . $claim->icon) }}" class="size-20 object-contain" />
                        @else
                            @if($claim->icon === 'star')
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="size-16 text-amber-400 animate-bounce">
                                    <path fill-rule="evenodd" d="M10.788 3.21c.448-1.077 1.976-1.077 2.424 0l2.082 5.006 5.404.434c1.164.093 1.636 1.545.749 2.305l-4.117 3.527 1.257 5.273c.271 1.136-.964 2.033-1.96 1.425L12 18.354 7.373 21.18c-.996.608-2.231-.29-1.96-1.425l1.257-5.273-4.117-3.527c-.887-.76-.415-2.212.749-2.305l5.404-.434 2.082-5.005Z" clip-rule="evenodd" />
                                </svg>
                            @elseif($claim->icon === 'fire')
                                <svg xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 24 24" class="size-16 text-orange-500 animate-bounce">
                                    <path d="M15.362 5.214A8.252 8.252 0 0 1 12 21 8.25 8.25 0 0 1 6.038 7.047 8.287 8.287 0 0 0 9 9.601a8.983 8.983 0 0 1 3.361-6.867 8.21 8.21 0 0 0 3 2.48Z" />
                                </svg>
                            @else
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-16 text-indigo-500 animate-bounce">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 18.75h-9m9 0a3 3 0 0 1 3 3h-15a3 3 0 0 1 3-3m9 0v-3.375c0-.621-.503-1.125-1.125-1.125h-6.75a1.125 1.125 0 0 0-1.125 1.125v3.375m9 0h-9M9 10.5h.008v.008H9V10.5Zm6 0h.008v.008H15V10.5Z" />
                                </svg>
                            @endif
                        @endif
                    </div>
                </div>

                <div class="space-y-2">
                    <flux:badge color="amber" size="sm" class="mx-auto uppercase tracking-wider font-bold animate-pulse">وسام جديد معتمد!</flux:badge>
                    <h2 class="text-2xl font-black text-slate-900 leading-tight mt-2">{{ $claim->name }}</h2>
                    <p class="text-sm text-slate-500 max-w-xs mx-auto leading-relaxed">{{ $claim->description ?: 'تهانينا! لقد حصلت على هذا الوسام تقديراً لتميزك واجتهادك.' }}</p>
                </div>

                <!-- Rewards Section -->
                <div class="grid grid-cols-2 gap-4 bg-slate-50 p-4 rounded-2xl border border-slate-100">
                    <div class="flex flex-col items-center gap-1 p-2 bg-white border border-slate-100 rounded-xl shadow-sm">
                        <span class="text-2xl">✨</span>
                        <span class="text-xs text-slate-400">نقاط الخبرة</span>
                        <span class="text-lg font-black text-team-primary">+{{ $claim->reward_xp }} XP</span>
                    </div>
                    <div class="flex flex-col items-center gap-1 p-2 bg-white border border-slate-100 rounded-xl shadow-sm">
                        <span class="text-2xl">🪙</span>
                        <span class="text-xs text-slate-400">العملات الذهبية</span>
                        <span class="text-lg font-black text-amber-600">+{{ $claim->reward_coins }} عملة</span>
                    </div>
                </div>

                <!-- Action Button -->
                <div class="pt-2">
                    <flux:button wire:click="claimBadge({{ $claim->id }})" variant="primary" class="w-full bg-team-primary hover:bg-team-primary-hover border-none font-bold text-white shadow-md py-3.5 rounded-2xl text-base transition-colors">
                        <span class="flex items-center justify-center gap-2">
                            <span>استلام وقبول الوسام</span>
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 18.75h-9m9 0a3 3 0 0 1 3 3h-15a3 3 0 0 1 3-3m9 0v-3.375c0-.621-.503-1.125-1.125-1.125h-6.75a1.125 1.125 0 0 0-1.125 1.125v3.375m9 0h-9M9 10.5h.008v.008H9V10.5Zm6 0h.008v.008H15V10.5Z" />
                            </svg>
                        </span>
                    </flux:button>
                </div>
            </div>
        </div>
    @elseif($pendingMilestoneClaims->isNotEmpty())
        @php
            $mClaim = $pendingMilestoneClaims->first();
        @endphp
        {{-- Built like the badge claim above, for the same reasons. --}}
        <div data-claim-overlay class="fixed inset-0 z-[10000] flex overflow-y-auto overscroll-contain p-4 backdrop-blur-xl bg-slate-900/60">
            <div class="relative max-w-md w-full m-auto rounded-3xl p-6 md:p-8 border border-slate-200 bg-white shadow-2xl text-center space-y-4 sm:space-y-6 overflow-hidden">
                <!-- Sparkle Background Effects using team color -->
                <div class="absolute -top-12 -left-12 size-40 rounded-full bg-team-10 blur-3xl"></div>
                <div class="absolute -bottom-12 -right-12 size-40 rounded-full bg-team-10 blur-3xl"></div>

                <!-- Animated Glow behind gift -->
                <div class="relative flex justify-center py-2 sm:py-4">
                    <div class="absolute inset-0 size-32 mx-auto rounded-full bg-team-20 blur-xl animate-pulse"></div>
                    <div class="relative size-28 rounded-full bg-team-10 flex items-center justify-center border-2 border-team-primary shadow-lg overflow-hidden">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-16 text-emerald-500 animate-bounce">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M21 11.25v8.25a1.5 1.5 0 0 1-1.5 1.5H5.25a1.5 1.5 0 0 1-1.5-1.5v-8.25M12 4.875A2.625 2.625 0 1 0 9.375 7.5H12m0-2.625V7.5m0-2.625A2.625 2.625 0 1 1 14.625 7.5H12m0 0h6.75a.75.75 0 0 1 .75.75v3a.75.75 0 0 1-.75.75H3.75a.75.75 0 0 1-.75-.75v-3a.75.75 0 0 1 .75-.75H12M9 7.5H12m0 0H9" />
                        </svg>
                    </div>
                </div>

                <div class="space-y-2">
                    <flux:badge color="amber" size="sm" class="mx-auto uppercase tracking-wider font-bold animate-pulse">جائزة حماسة جديدة!</flux:badge>
                    <h2 class="text-2xl font-black text-slate-900 leading-tight mt-2">إنجاز حماسة متتالية: {{ $mClaim->days_required }} أيام</h2>
                    <p class="text-sm text-slate-500 max-w-xs mx-auto leading-relaxed">{{ $mClaim->description ?: 'تهانينا! لقد حافظت على شعلة حماستك واستحققت هذه الجائزة الرائعة.' }}</p>
                </div>

                <!-- Rewards Section -->
                <div class="grid grid-cols-2 gap-4 bg-slate-50 p-4 rounded-2xl border border-slate-100">
                    <div class="flex flex-col items-center gap-1 p-2 bg-white border border-slate-100 rounded-xl shadow-sm">
                        <span class="text-2xl">✨</span>
                        <span class="text-xs text-slate-400">نقاط الخبرة</span>
                        <span class="text-lg font-black text-team-primary">+{{ $mClaim->reward_xp }} XP</span>
                    </div>
                    <div class="flex flex-col items-center gap-1 p-2 bg-white border border-slate-100 rounded-xl shadow-sm">
                        <span class="text-2xl">🪙</span>
                        <span class="text-xs text-slate-400">العملات الذهبية</span>
                        <span class="text-lg font-black text-amber-600">+{{ $mClaim->reward_coins }} عملة</span>
                    </div>
                </div>

                <!-- Action Button -->
                <div class="pt-2">
                    {{-- By milestone: the claim row's id changes with every streak rebuild. --}}
                    <flux:button wire:click="claimMilestone({{ $mClaim->id }})" variant="primary" class="w-full bg-team-primary hover:bg-team-primary-hover border-none font-bold text-white shadow-md py-3.5 rounded-2xl text-base transition-colors">
                        <span class="flex items-center justify-center gap-2">
                            <span>استلام وقبول الجائزة</span>
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                            </svg>
                        </span>
                    </flux:button>
                </div>
            </div>
        </div>
    @endif

    <!-- Theme Background Effects -->
    <div class="absolute inset-0 pointer-events-none opacity-10 overflow-hidden rounded-3xl">
        <div class="absolute -top-40 -left-40 w-96 h-96 bg-team-primary rounded-full blur-3xl"></div>
        <div class="absolute -bottom-40 -right-40 w-96 h-96 bg-team-primary rounded-full blur-3xl"></div>
    </div>

    {{--
        Top stats bar, pinned at the top of every tab. The wrapper is what
        sticks: a sticky element holds only inside its parent, and the bar is
        its wrapper's only child. Its top is pulled up by all but a sliver of
        its padding, so once pinned the bar floats just below the screen's edge,
        on a band of the page's colour so nothing scrolling beneath shows above it.
        It stacks at 15: over the page's cards (z-10) that scroll beneath it, and
        under the phone's side menu (z-20), which it used to cover, close button
        and first items included.
    --}}
    <div class="sticky -top-1 md:-top-6 z-[15] p-3 md:p-8 pb-0 rounded-t-3xl bg-slate-50/95 backdrop-blur-sm">
        <!-- Compact Stats Bar (always visible, pinned at the top of every tab) -->
        @php
            $lvlCur = $gamificationLevelInfo['current'] ?? null;
            $lvlNxt = $gamificationLevelInfo['next'] ?? null;
            $lvlXp = (int) ($gamificationLevelInfo['xp'] ?? 0);
            $lvlBase = (int) ($lvlCur->xp_required ?? 0);
            $lvlTarget = $lvlNxt->xp_required ?? null;
            $lvlPct = ($lvlTarget !== null && $lvlTarget > $lvlBase)
                ? min(100, max(0, (int) round((($lvlXp - $lvlBase) / ($lvlTarget - $lvlBase)) * 100)))
                : 100;
        @endphp
        <div id="gam-stats-bar"
            class="isolate flex items-stretch justify-around gap-1 bg-white/95 backdrop-blur border border-slate-200 rounded-2xl px-2 py-2 shadow-sm">

            {{-- Level (tap → home) with progress to next level --}}
            <button type="button"
                x-on:click="window.dispatchEvent(new CustomEvent('gamnav-changed', { detail: { tab: 'leaderboard' } })); window.scrollTo({ top: 0, behavior: 'instant' });"
                class="flex-1 flex flex-col items-center gap-1 rounded-xl px-2 py-1 hover:bg-slate-50 transition-colors cursor-pointer">
                <span class="flex items-center gap-1 text-[10px] text-slate-400 font-bold leading-none">
                    <flux:icon icon="trophy" class="size-3 shrink-0" />{{ __('المستوى') }}
                </span>
                <span id="gam-level-value" class="text-base font-black leading-none text-team-primary">{{ $studentLevel }}</span>
                <span id="gam-level-meter" class="w-full max-w-14 h-1 rounded-full bg-slate-100 overflow-hidden" title="{{ $lvlPct }}% {{ __('نحو المستوى التالي') }}">
                    <span id="gam-level-fill" class="block h-full rounded-full bg-team-primary transition-all duration-700" style="width: {{ $lvlPct }}%"></span>
                </span>
            </button>

            <div class="w-px self-center h-9 bg-slate-200"></div>

            {{--
                XP (tap → home) with today's delta: the XP the level is counted
                from, and the same score the standings show, since both leave
                out work graded outside the competition's dates (getStudentXP()).
            --}}
            <button type="button"
                x-on:click="window.dispatchEvent(new CustomEvent('gamnav-changed', { detail: { tab: 'leaderboard' } })); window.scrollTo({ top: 0, behavior: 'instant' });"
                class="flex-1 flex flex-col items-center gap-1 rounded-xl px-2 py-1 hover:bg-slate-50 transition-colors cursor-pointer min-w-0">
                <span data-stats-xp-label class="flex items-center gap-1 text-[10px] text-slate-400 font-bold leading-none truncate max-w-full">
                    <flux:icon icon="sparkles" class="size-3 shrink-0" />{{ __('النقاط') }}
                </span>
                <span id="gam-xp-value" class="text-base font-black text-slate-900 leading-none">{{ number_format($lvlXp) }}</span>
                <span class="text-[9px] font-black leading-none {{ $xpToday > 0 ? 'text-emerald-600' : 'text-transparent' }}">
                    {{ $xpToday > 0 ? '+'.number_format($xpToday).' '.__('اليوم') : '.' }}
                </span>
            </button>

            <div class="w-px self-center h-9 bg-slate-200"></div>

            {{-- Coins (tap → store) with today's delta + context highlight --}}
            <button type="button"
                x-on:click="window.dispatchEvent(new CustomEvent('gamnav-changed', { detail: { tab: 'store' } })); window.scrollTo({ top: 0, behavior: 'instant' });"
                :class="currentTab === 'store' ? 'bg-amber-50 ring-1 ring-amber-200' : 'hover:bg-slate-50'"
                class="flex-1 flex flex-col items-center gap-1 rounded-xl px-2 py-1 transition-colors cursor-pointer">
                <span class="text-[10px] text-slate-400 font-bold leading-none">{{ __('العملات') }}</span>
                <span class="text-base font-black text-amber-600 leading-none flex items-center gap-1">{{ $gamificationState?->coins ?? 0 }} {!! $this->renderEmoji($style['coin_emoji'], 'size-4 inline-block align-middle') !!}</span>
                <span class="text-[9px] font-black leading-none {{ $coinsToday > 0 ? 'text-emerald-600' : 'text-transparent' }}">
                    {{ $coinsToday > 0 ? '+'.number_format($coinsToday).' '.__('اليوم') : '.' }}
                </span>
            </button>

            @if($studentRank)
                <div class="w-px self-center h-9 bg-slate-200"></div>

                {{-- Rank (tap → standings on the home tab). It scrolls to the table
                     itself, which sits below the missions and plans; it used to
                     scroll to the top, away from the table it promises. The wait
                     lets the home tab show before the table is measured. --}}
                <button type="button"
                    x-on:click="window.dispatchEvent(new CustomEvent('gamnav-changed', { detail: { tab: 'leaderboard' } })); $nextTick(() => scrollToStandings());"
                    class="flex-1 flex flex-col items-center gap-1 rounded-xl px-2 py-1 hover:bg-slate-50 transition-colors cursor-pointer min-w-0">
                    <span class="flex items-center gap-1 text-[10px] text-slate-400 font-bold leading-none truncate max-w-full">
                        <flux:icon icon="chart-bar" class="size-3 shrink-0" />{{ $studentRankScope === 'track' ? __('مركزك في مسارك') : __('مركزك') }}
                    </span>
                    {{-- One line, or «12 /» over «140» on a narrow phone makes the pinned bar taller. --}}
                    <span id="gam-rank-value" class="whitespace-nowrap tabular-nums text-base font-black leading-none text-team-primary">
                        {{ $studentRank }}<span class="text-[10px] text-slate-400 font-bold"> / {{ $studentRankTotal }}</span>
                    </span>
                </button>
            @endif
        </div>

        {{--
            The tabs on a wide screen, pinned with the stats bar. The phone's
            bottom bar is the only other switcher, and from lg up it hides, as it
            would sit over the side menu. Without this strip a laptop or a
            landscape iPad had no way into the news or the team tab, and with the
            team tab went its votes, tasks, donations and standings.
        --}}
        <nav data-desktop-tabs aria-label="{{ __('أقسام المسابقة') }}" class="hidden lg:flex flex-wrap items-center gap-2 mt-3">
            @foreach($gamTabs as $tab)
                <button type="button" wire:key="gam-desktop-tab-{{ $tab['tab'] }}" data-tab="{{ $tab['tab'] }}"
                    x-on:click="window.dispatchEvent(new CustomEvent('gamnav-changed', { detail: { tab: '{{ $tab['tab'] }}' } })); @if($tab['tab'] === 'news') window.dispatchEvent(new CustomEvent('news-opened')); @endif window.scrollTo({ top: 0, behavior: 'instant' });"
                    :class="currentTab === '{{ $tab['tab'] }}' ? 'bg-team-primary border-transparent text-white shadow-sm' : 'bg-white border-slate-200 text-slate-600 hover:bg-slate-50'"
                    :aria-current="currentTab === '{{ $tab['tab'] }}' ? 'page' : null"
                    class="flex items-center gap-2 rounded-xl border px-4 py-2 text-sm font-bold transition-colors cursor-pointer">
                    <flux:icon icon="{{ $tab['icon'] }}" variant="solid" class="size-5 shrink-0"
                        x-show="currentTab === '{{ $tab['tab'] }}'" x-cloak />
                    <flux:icon icon="{{ $tab['icon'] }}" variant="outline" class="size-5 shrink-0"
                        x-show="currentTab !== '{{ $tab['tab'] }}'" />
                    {{ $tab['name'] }}
                </button>
            @endforeach
        </nav>
    </div>

    {{--
        Home Tab View: the top cards show only on the home tab. Every tab panel
        carries x-cloak, so none paints until Alpine has picked the open tab;
        without it all of them flashed stacked on load, then collapsed to one.
    --}}
    <div x-show="currentTab === 'leaderboard'" x-cloak class="space-y-8 p-3 md:p-8">
    <!-- Turn Reservation: book tasmeeh turn from the competition view -->
    @if($activeSession)
        <div class="relative z-10 rounded-3xl border border-slate-200 bg-white overflow-hidden shadow-sm">
            <div class="absolute top-0 left-0 p-4 opacity-[0.06] pointer-events-none">
                <flux:icon icon="ticket" class="w-28 h-28 text-team-primary" />
            </div>
            <div class="relative z-10 flex flex-col sm:flex-row items-center justify-between gap-4 p-5">
                <div>
                    <div class="flex items-center gap-2.5">
                        <div class="size-9 rounded-xl bg-team-10 text-team-primary flex items-center justify-center">
                            <flux:icon icon="ticket" class="size-5" />
                        </div>
                        <div class="font-bold text-slate-900">
                            {{ $activeSession->isActiveNow() ? __('حجز دور التسميع متاح الآن') : __('جدول التسميع لليوم') }}
                        </div>
                    </div>
                    <p class="text-sm text-slate-500 mt-1.5">
                        {{ __('بادر بحجز رقمك في طابور التسميع قبل انتهاء الوقت المخصص.') }}
                        <span class="font-semibold text-slate-600">({{ \Carbon\Carbon::parse($activeSession->start_time)->format('g:i A') }} - {{ \Carbon\Carbon::parse($activeSession->end_time)->format('g:i A') }})</span>
                    </p>
                </div>

                <div class="shrink-0 w-full sm:w-auto">
                    @if($studentReservation)
                        <div class="flex flex-col sm:flex-row items-center gap-2">
                            <div class="bg-team-primary text-white px-6 py-3 rounded-2xl flex items-center gap-3 shadow-lg w-full sm:w-auto justify-center">
                                <flux:icon icon="check-badge" class="size-6 text-white/70" />
                                <div>
                                    <div class="text-[11px] text-white/80 uppercase tracking-wider font-semibold">{{ __('تم الحجز') }}</div>
                                    <div class="font-bold text-xl">{{ __('رقمك: ') }} {{ $studentReservation->turn_number }}</div>
                                </div>
                            </div>
                            @if($activeSession->isActiveNow())
                                <flux:button wire:click="cancelTurn({{ $activeSession->id }})"
                                    wire:confirm="{{ __('هل أنت متأكد من إلغاء حجزك؟') }}" variant="danger" icon="x-mark"
                                    class="w-full sm:w-auto h-full min-h-[52px] rounded-2xl px-4">
                                    {{ __('إلغاء') }}
                                </flux:button>
                            @endif
                        </div>
                    @else
                        @if($activeSession->isActiveNow())
                            <button wire:click="reserveTurn({{ $activeSession->id }})" wire:loading.attr="disabled"
                                class="w-full inline-flex items-center justify-center gap-2 px-6 py-3 rounded-2xl bg-team-primary hover:bg-team-primary-hover text-white font-bold shadow-lg transition-colors">
                                <flux:icon icon="ticket" class="size-5" />
                                {{ __('احجز دوري الآن') }}
                            </button>
                        @else
                            <div class="text-slate-500 font-semibold text-sm bg-slate-100 px-4 py-2.5 rounded-xl text-center">
                                {{ __('غير متاح الآن') }}
                            </div>
                        @endif
                    @endif
                </div>
            </div>
        </div>
    @endif

    <!-- Pending rewards to claim -->
    @if($pendingRewards->isNotEmpty())
        <div class="relative z-10 mb-6 rounded-3xl border border-amber-200 bg-white overflow-hidden shadow-sm">
            <div class="flex items-center justify-between gap-3 p-4 border-b border-slate-100">
                <div class="flex items-center gap-2.5">
                    <div class="size-9 rounded-xl bg-amber-50 text-amber-500 flex items-center justify-center"><flux:icon icon="gift" class="size-5" /></div>
                    <div>
                        <div class="font-bold text-slate-900">{{ __('مكافآت بانتظار الاستلام') }}</div>
                        <div class="text-xs text-slate-400">{{ $pendingRewards->count() }} {{ __('مكافآت لم تُستلم بعد') }}</div>
                    </div>
                </div>
                <div class="flex gap-1.5 text-xs font-bold">
                    <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-indigo-50 text-indigo-600"><flux:icon icon="bolt" class="size-3.5" /> +{{ $pendingRewards->sum('xp_amount') }}</span>
                    <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-amber-50 text-amber-600"><flux:icon icon="circle-stack" class="size-3.5" /> +{{ $pendingRewards->sum('amount') }}</span>
                </div>
            </div>

            <div class="divide-y divide-slate-100 max-h-80 overflow-y-auto scrollbar-thin">
                @foreach($pendingRewards as $reward)
                    @php
                        $rewardIcon = match($reward->reference_type) {
                            'App\\Models\\StudentPlanDay' => 'book-open',
                            'App\\Models\\FreeRecitation' => 'book-open',
                            'App\\Models\\StudentOdeAchievement' => 'musical-note',
                            'App\\Models\\StudentHadithAchievement' => 'document-text',
                            'App\\Models\\Attendance' => 'user-group',
                            'App\\Models\\LeaderboardScore' => 'star',
                            'App\\Models\\GamificationActivityWinner' => 'trophy',
                            'App\\Models\\GamificationLevel' => 'academic-cap',
                            'leaderboard_extra_points' => 'plus-circle',
                            default => 'gift',
                        };
                    @endphp
                    <div class="flex items-center gap-3 p-3.5" wire:key="reward-{{ $reward->id }}">
                        <div class="size-9 rounded-lg bg-slate-50 text-slate-500 flex items-center justify-center shrink-0"><flux:icon :icon="$rewardIcon" class="size-5" /></div>
                        <div class="flex-1 min-w-0">
                            <div class="text-sm text-slate-800 truncate">{{ $reward->description }}</div>
                        </div>
                        <div class="hidden sm:flex gap-1.5 shrink-0">
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-bold bg-indigo-50 text-indigo-600">+{{ $reward->xp_amount }} {{ __('خبرة') }}</span>
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-bold bg-amber-50 text-amber-600">+{{ $reward->amount }} {{ __('عملة') }}</span>
                        </div>
                        <button wire:click="claimReward({{ $reward->id }})" onclick="window.gamLaunchXp && window.gamLaunchXp(this)" wire:loading.attr="disabled" class="shrink-0 text-xs font-bold px-4 py-1.5 rounded-lg bg-emerald-50 text-emerald-600 border border-emerald-200 hover:bg-emerald-100 transition-colors">{{ __('استلام') }}</button>
                    </div>
                @endforeach
            </div>

            <div class="flex items-center justify-between gap-3 p-4 bg-slate-50/70 border-t border-slate-100">
                <span class="text-xs text-slate-500">{{ __('الإجمالي') }}: +{{ $pendingRewards->sum('xp_amount') }} {{ __('خبرة') }} · +{{ $pendingRewards->sum('amount') }} {{ __('عملة') }}</span>
                <button wire:click="claimAllRewards" onclick="window.gamLaunchXp && window.gamLaunchXp(this, 16)" wire:loading.attr="disabled" class="inline-flex items-center gap-2 px-5 py-2 rounded-xl bg-emerald-500 hover:bg-emerald-600 text-white font-bold text-sm transition-colors"><flux:icon icon="check" class="size-4" /> {{ __('استلام الكل') }}</button>
            </div>
        </div>
    @endif

    <!-- Top Core Stats Grid -->
    <div class="relative z-10 grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Student Profile & Level Card -->
        <div class="{{ $style['card'] }}">
            {{--
                On a phone a long name or level used to push the coins past the
                card's edge, where they were clipped: 1250 read as 250. The name
                block now takes the leftover width and wraps, the level truncates
                inside its badge, and the avatar and coins keep their size.
            --}}
            <div class="flex items-center gap-4">
                <div class="relative group shrink-0">
                    <label for="profile-avatar-upload" class="cursor-pointer block relative">
                        @if($student->avatar_path)
                            <div class="w-16 h-16 rounded-full overflow-hidden border-2 border-slate-200 shadow-sm relative">
                                <img src="{{ Storage::url($student->avatar_path) }}" class="w-full h-full object-cover" />
                                <div class="absolute inset-0 bg-black/40 opacity-0 group-hover:opacity-100 flex items-center justify-center transition-opacity text-white text-[10px] font-bold">
                                    {{ __('تعديل') }}
                                </div>
                            </div>
                        @else
                            <div class="w-17 h-17 rounded-full flex items-center justify-center border-2 border-slate-200 text-lg font-black shadow-sm relative group-hover:border-team-primary transition-colors" style="{{ $student->avatarStyle() }}">
                                <span>{{ $student->initials() }}</span>
                                <div class="absolute inset-0 bg-black/40 opacity-0 group-hover:opacity-100 flex items-center justify-center transition-opacity text-white rounded-full text-[10px] font-bold">
                                    {{ __('رفع') }}
                                </div>
                            </div>
                        @endif
                    </label>
                    <input type="file" id="profile-avatar-upload" wire:model="profile_image_file" class="hidden" accept="image/*" />
                    {{-- A photo takes a while to upload from a phone; the avatar shows it is on its way. --}}
                    <div wire:loading.flex wire:target="profile_image_file" data-avatar-uploading class="absolute inset-0 rounded-full bg-black/50 items-center justify-center pointer-events-none">
                        <flux:icon.loading class="size-5 text-white" />
                    </div>
                </div>
                <div class="min-w-0 flex-1">
                    <h3 class="font-bold text-lg text-slate-900 break-words">{{ $student->name }}</h3>
                    <div class="flex flex-col justify-start items-start gap-2 mt-1">
                        <flux:badge color="{{ $style['badge'] }}" size="sm" icon="{{ $gamificationLevelInfo['current']->icon ?? 'sparkles' }}" class="max-w-full">
                            <span class="truncate">{{ $gamificationLevelInfo['current']->name ?? __('مبتدئ') }}</span>
                        </flux:badge>
                    </div>
                </div>
                <h2 class="shrink-0 text-3xl sm:text-4xl font-black text-slate-900 mt-2 flex items-center gap-2">
                    {{ $gamificationState->coins ?? 0 }}
                    <span class="text-2xl">{!! $this->renderEmoji($style['coin_emoji'], 'size-6 sm:size-7 inline-block align-middle') !!}</span>
                </h2>
            </div>
            {{-- Why a picked photo was refused: too large, not an image, or the upload failed. --}}
            @error('profile_image_file')
                <p data-avatar-error class="mt-2 text-xs font-semibold text-red-600">{{ $message }}</p>
            @enderror

            <!-- XP Progress -->
            @php
                $lvlCurrent = $gamificationLevelInfo['current'];
                $lvlNext = $gamificationLevelInfo['next'];
                $studentXP = $gamificationLevelInfo['xp'];
                $lvlProgress = 0;
                if ($lvlNext) {
                    $range = $lvlNext->xp_required - ($lvlCurrent->xp_required ?? 0);
                    $progress = $studentXP - ($lvlCurrent->xp_required ?? 0);
                    $lvlProgress = $range > 0 ? min(100, max(0, round(($progress / $range) * 100))) : 100;
                } else {
                    $lvlProgress = 100;
                }
            @endphp
            <div class="mt-15">
                <div class="flex justify-between items-center text-xs font-semibold mb-1.5 text-slate-600">
                <span class="text-xl text-team-primary">
                          <span class="text-xs ">{{ __('مستوى') }}</span> {{ $studentLevel }}
                        </span>
                    <span class="text-xl"><strong class="text-4xl ">{{ $studentXP }}</strong> XP</span>
                    <span class="text-slate-400 text-xl">
                        @if($lvlNext)
                            / {{ $lvlNext->xp_required }}
                        @else
                            {{ __('أقصى مستوى!') }}
                        @endif
                    </span>
                </div>
                <div class="w-full bg-slate-100 rounded-full h-3 overflow-hidden border border-slate-200">
                    <div class="h-full rounded-full {{ $style['progress'] }} transition-all duration-700" style="width: {{ $lvlProgress }}%"></div>
                </div>
                <div class="text-[10px] text-slate-400 text-center mt-1">
                    {{ $lvlProgress }}% {{ __('نحو المستوى التالي') }}
                </div>

                @php
                    $showIndividualMultiplier = false;
                    if ($activeGamification && isset($gamificationLevelInfo['perks'])) {
                        $lvlSettings = $gamificationLevelInfo['perks']->settings ?? [];
                        $showIndividualMultiplier = (bool) ($lvlSettings['has_individual_multiplier'] ?? true);
                    }
                @endphp
                @if($showIndividualMultiplier)
                    <div class="mt-4 pt-3 border-t border-slate-100 flex items-center justify-between">
                        <span class="text-xs text-slate-500 font-medium flex items-center gap-1">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="size-4 text-yellow-500">
                                <path fill-rule="evenodd" d="M14.615 1.595a.75.75 0 0 1 .359.852L12.982 9.75h7.268a.75.75 0 0 1 .548 1.262l-10.5 11.25a.75.75 0 0 1-1.272-.71l1.992-7.302H3.75a.75.75 0 0 1-.548-1.262l10.5-11.25a.75.75 0 0 1 .913-.143Z" clip-rule="evenodd" />
                            </svg>
                            {{ __('مضاعفة نقاط الحفظ والمراجعة') }}
                        </span>
                        <flux:button variant="primary" wire:click="openDoublePointsModal('{{ \Carbon\Carbon::now('Asia/Riyadh')->addDay()->format('Y-m-d') }}', 'individual')" size="xs" class="bg-team-primary hover:bg-team-primary-hover border-none font-bold !text-white shadow-sm" style="color: white !important;" icon="bolt">
                            {{ __('مضاعفة النقاط') }}
                        </flux:button>
                    </div>
                @endif
            </div>
        </div>       
    </div>

    <!-- Streak Milestones (Enthusiasm) Card -->
    <div class="relative z-10 {{ $style['card'] }}">
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
            <div class="flex items-center gap-3">
                <div class="p-2.5 rounded-xl bg-orange-50 dark:bg-orange-950/30 text-orange-500 shrink-0">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-8 text-orange-500 animate-pulse fill-orange-500/20">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M15.362 5.214A8.252 8.252 0 0 1 12 21 8.25 8.25 0 0 1 6.038 7.047 8.287 8.287 0 0 0 9 9.601a8.983 8.983 0 0 1 3.361-6.867 8.21 8.21 0 0 0 3 2.48Z" />
                      <path stroke-linecap="round" stroke-linejoin="round" d="M12 18a3.75 3.75 0 0 0 .495-7.467 5.99 5.99 0 0 0-1.925 3.546 5.974 5.974 0 0 1-2.133-1A3.75 3.75 0 0 0 12 18Z" />
                    </svg>
                </div>
                <div>
                    <h3 class="font-black text-lg text-slate-900">{{ __('أيام الحماسة المتتالية') }}</h3>
                    <p class="text-xs text-slate-500 mt-0.5">
                        {{ __('حقق شروط الحماسة يومياً لتبقي الشعلة مضيئة وتكسب الجوائز الكبرى.') }}
                    </p>
                </div>
            </div>
            <div class="text-center bg-slate-50 border border-slate-200 rounded-2xl px-6 py-3 shrink-0 shadow-inner">
                <span data-current-streak class="text-3xl font-black {{ $style['accent'] }}">{{ $currentStreak }}</span>
                <span class="text-xs text-slate-500 block font-semibold mt-0.5">{{ __('أيام متتالية') }}</span>
            </div>
        </div>

        <!-- Active Multipliers (Double Points) List; picked in with() -->
        @if($activeMultipliers->isNotEmpty())
            <div class="mt-4 flex flex-wrap gap-2 items-center text-xs font-semibold text-slate-600 dark:text-slate-400 bg-slate-50 dark:bg-zinc-900 border border-slate-200/60 dark:border-zinc-800 rounded-xl px-3 py-2">
                <span class="flex items-center gap-1 shrink-0">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="size-3.5 text-yellow-500">
                        <path fill-rule="evenodd" d="M14.615 1.595a.75.75 0 0 1 .359.852L12.982 9.75h7.268a.75.75 0 0 1 .548 1.262l-10.5 11.25a.75.75 0 0 1-1.272-.71l1.992-7.302H3.75a.75.75 0 0 1-.548-1.262l10.5-11.25a.75.75 0 0 1 .913-.143Z" clip-rule="evenodd" />
                    </svg>
                    <span>المضاعفات النشطة:</span>
                </span>
                <div class="flex flex-wrap gap-1.5">
                    @foreach($activeMultipliers as $mult)
                        @php
                            $multDateStr = $mult->target_date ? \Carbon\Carbon::parse($mult->target_date)->format('Y-m-d') : null;
                            if (!$multDateStr && $mult->item && $mult->item->target_date) {
                                $multDateStr = \Carbon\Carbon::parse($mult->item->target_date)->format('Y-m-d');
                            }
                            $isTeamMult = (bool) $mult->team_id;
                            
                            $formattedMultDate = 'غير محدد';
                            if ($multDateStr) {
                                $carbonDate = \Carbon\Carbon::parse($multDateStr);
                                $formattedMultDate = \App\Support\HijriDate::dayMonth($carbonDate->getTimestamp());
                            }
                        @endphp
                        <flux:badge size="sm" color="{{ $isTeamMult ? 'indigo' : 'amber' }}" icon="bolt" class="font-bold">
                            {{ $isTeamMult ? 'جماعي' : 'فردي' }} ({{ $formattedMultDate }})
                        </flux:badge>
                    @endforeach
                </div>
            </div>
        @endif

        <!-- Working Days circles -->
        @if(!empty($workingDays))
            <div class="mt-8 border-t border-slate-100 pt-6">
                <h4 class="text-xs font-bold text-slate-500 mb-6 uppercase tracking-wider">{{ __('تتبع أيام الحماسة في المسابقة:') }}</h4>
                <style>
                    .scrollbar-none::-webkit-scrollbar {
                        display: none;
                    }
                    .scrollbar-none {
                        -ms-overflow-style: none;
                        scrollbar-width: none;
                    }
                </style>
                {{--
                    Today is centred by scrolling the strip alone. scrollIntoView
                    also scrolled the page to bring the strip on screen, so a phone
                    jumped past the profile card on load and left today's circle
                    under the bottom bar. scrollBy takes a physical offset, so it
                    holds in RTL whatever sign scrollLeft uses there.
                --}}
                <div class="flex flex-row gap-4 items-center justify-start overflow-x-auto py-4 scrollbar-none snap-x snap-mandatory scroll-smooth"
                     x-init="$nextTick(() => {
                         const todayEl = $el.querySelector('.is-today-day');
                         if (todayEl) {
                             const strip = $el.getBoundingClientRect(), today = todayEl.getBoundingClientRect();
                             $el.scrollBy({ left: (today.left + today.width / 2) - (strip.left + strip.width / 2), behavior: 'smooth' });
                         }
                     })">
                    @foreach($workingDays as $day)
                        @php
                            $circleClass = '';
                            if ($day['status'] === 'fiery') {
                                // Fiery colors: gradient from yellow to red, glowing shadow, animated
                                $circleClass = 'bg-gradient-to-br from-yellow-400 via-orange-500 to-red-600 text-white shadow-[0_0_15px_rgba(249,115,22,0.6)] border-2 border-yellow-300 font-black animate-pulse';
                            } elseif ($day['status'] === 'frozen') {
                                // Frozen colors: glacial gradient from cyan to blue, glowing shadow
                                $circleClass = 'bg-gradient-to-br from-cyan-400 to-blue-500 text-white shadow-[0_0_15px_rgba(34,211,238,0.6)] border-2 border-cyan-200 font-black';
                            } elseif ($day['status'] === 'orange') {
                                // Past day with no enthusiasm: Orange colors
                                $circleClass = 'bg-orange-100 text-orange-700 border-2 border-orange-200 font-bold';
                            } else {
                                // Future day: Gray colors
                                $circleClass = 'bg-slate-100 text-slate-400 border border-slate-200/80 font-medium';
                            }

                            if (isset($day['milestone'])) {
                                $circleClass .= ' w-14 h-14 ring-2 ring-amber-400 ring-offset-1';
                            } else {
                                $circleClass .= ' w-12 h-12 text-sm';
                            }
                        @endphp
                        <div class="flex flex-col items-center gap-1.5 group relative min-w-[85px] text-center snap-center {{ $day['is_today'] ? 'is-today-day' : '' }}">
                            <!-- Circle -->
                            <div class="relative rounded-full flex items-center justify-center transition-all duration-300 transform hover:scale-110 shadow-sm cursor-help {{ $circleClass }}"
                                 title="{{ $day['day_name'] }} {{ $day['formatted_date'] }} ({{ $day['date'] }}) @if(isset($day['milestone'])) - {{ $day['milestone']->description }}@endif">
                                
                                @if($day['has_individual_multiplier'])
                                    <span class="absolute -top-1.5 -left-1.5 size-5 bg-white dark:bg-zinc-800 border border-slate-100 dark:border-zinc-700 rounded-full p-0.5 shadow-md flex items-center justify-center animate-bounce z-10" title="مضاعفة فردية">
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="size-3.5">
                                            <defs>
                                                <linearGradient id="lightning-indiv-grad-{{ $day['number'] }}" x1="0%" y1="0%" x2="100%" y2="100%">
                                                    <stop offset="0%" stop-color="#06b6d4" />
                                                    <stop offset="100%" stop-color="#f97316" />
                                                </linearGradient>
                                            </defs>
                                            <path fill="url(#lightning-indiv-grad-{{ $day['number'] }})" d="M14.615 1.595a.75.75 0 0 1 .359.852L12.982 9.75h7.268a.75.75 0 0 1 .548 1.262l-10.5 11.25a.75.75 0 0 1-1.272-.71l1.992-7.302H3.75a.75.75 0 0 1-.548-1.262l10.5-11.25a.75.75 0 0 1 .913-.143Z" />
                                        </svg>
                                    </span>
                                @endif

                                @if($day['has_team_multiplier'])
                                    <span class="absolute -top-1.5 -right-1.5 size-5 bg-white dark:bg-zinc-800 border border-slate-100 dark:border-zinc-700 rounded-full p-0.5 shadow-md flex items-center justify-center animate-bounce z-10" title="مضاعفة جماعية">
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="size-3.5">
                                            <defs>
                                                <linearGradient id="lightning-team-grad-{{ $day['number'] }}" x1="0%" y1="0%" x2="100%" y2="100%">
                                                    <stop offset="0%" stop-color="#60a5fa" />
                                                    <stop offset="100%" stop-color="#1d4ed8" />
                                                </linearGradient>
                                            </defs>
                                            <path fill="url(#lightning-team-grad-{{ $day['number'] }})" d="M14.615 1.595a.75.75 0 0 1 .359.852L12.982 9.75h7.268a.75.75 0 0 1 .548 1.262l-10.5 11.25a.75.75 0 0 1-1.272-.71l1.992-7.302H3.75a.75.75 0 0 1-.548-1.262l10.5-11.25a.75.75 0 0 1 .913-.143Z" />
                                        </svg>
                                    </span>
                                @endif

                                @if(isset($day['milestone']))
                                    @php
                                        $isClaimed = in_array($day['milestone']->id, $claimedMilestones);
                                    @endphp
                                    @if($isClaimed)
                                        <span class="text-base font-extrabold">✓</span>
                                    @else
                                        <div class="flex flex-col items-center justify-center leading-tight text-[10px] font-black">
                                            @if($day['milestone']->reward_coins > 0)
                                                <div class="flex items-center gap-0.5 whitespace-nowrap">
                                                    <span>+{{ $day['milestone']->reward_coins }}</span>
                                                    @if(str_contains((string)$style['coin_emoji'], '/') || str_ends_with((string)$style['coin_emoji'], '.webp'))
                                                        {!! $this->renderEmoji($style['coin_emoji'], 'size-3 inline-block') !!}
                                                    @else
                                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="size-3 text-amber-500 inline-block align-middle">
                                                            <path fill-rule="evenodd" d="M12 2.25c-5.385 0-9.75 4.365-9.75 9.75s4.365 9.75 9.75 9.75 9.75-4.365 9.75-9.75S17.385 2.25 12 2.25ZM9 10.5a.75.75 0 0 1 .75-.75h4.5a.75.75 0 0 1 0 1.5h-4.5a.75.75 0 0 1-.75-.75Zm0 3a.75.75 0 0 1 .75-.75h4.5a.75.75 0 0 1 0 1.5h-4.5a.75.75 0 0 1-.75-.75Z" clip-rule="evenodd" />
                                                        </svg>
                                                    @endif
                                                </div>
                                            @endif
                                            @if($day['milestone']->reward_xp > 0)
                                                <span class="font-sans whitespace-nowrap">+{{ $day['milestone']->reward_xp }}XP</span>
                                            @endif
                                        </div>
                                    @endif
                                @else
                                    @if($day['status'] === 'fiery')
                                        <span class="relative flex items-center justify-center text-sm font-bold">
                                            {{ $day['number'] }}
                                            <span class="absolute -top-3 -right-3 size-4 text-orange-500 select-none">
                                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="size-4 text-orange-500">
                                                    <path fill-rule="evenodd" d="M12.969 2.11a.75.75 0 0 1 .371.836A8.96 8.96 0 0 0 13 5c0 .723.085 1.426.248 2.102a9.778 9.778 0 0 0 1.258-1.503.75.75 0 0 1 1.157.065 11.254 11.254 0 0 1 1.66 4.385c.105.772.176 1.558.212 2.355.05.2.072.408.072.622a8.25 8.25 0 0 1-15.594 3.064c.003-.004.007-.01.011-.015a8.224 8.224 0 0 1 1.18-1.416.75.75 0 0 1 1.01.082C5.074 15.342 6.52 16 8 16c1.133 0 2.107-.5 2.756-1.29A7.96 7.96 0 0 1 9 12c0-1.8 1.103-3.344 2.686-4.004a8.272 8.272 0 0 0-.825-1.985.75.75 0 0 1 .111-.853l1-1Z" clip-rule="evenodd" />
                                                </svg>
                                            </span>
                                        </span>
                                    @elseif($day['status'] === 'frozen')
                                        <span class="relative flex items-center justify-center text-sm font-bold text-white">
                                            {{ $day['number'] }}
                                            <span class="absolute -top-3 -right-3 size-4 text-cyan-100 select-none animate-pulse">
                                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="size-4 text-cyan-100">
                                                    <line x1="12" y1="2" x2="12" y2="22"></line>
                                                    <line x1="2" y1="12" x2="22" y2="12"></line>
                                                    <line x1="4.93" y1="4.93" x2="19.07" y2="19.07"></line>
                                                    <line x1="19.07" y1="4.93" x2="4.93" y2="19.07"></line>
                                                    <path d="m20 8-2 2-2-2"></path>
                                                    <path d="m4 16 2-2 2 2"></path>
                                                    <path d="m8 4 2 2-2 2"></path>
                                                    <path d="m16 20-2-2 2-2"></path>
                                                    <path d="m8 20 2-2-2-2"></path>
                                                    <path d="m16 4-2 2 2 2"></path>
                                                    <path d="m4 8 2 2-2 2"></path>
                                                    <path d="m20 16-2-2 2 2"></path>
                                                </svg>
                                            </span>
                                        </span>
                                    @else
                                        <span class="text-sm font-bold">{{ $day['number'] }}</span>
                                    @endif
                                @endif
                            </div>
                            
                            <!-- Date labels (Day Name & Hijri Date) -->
                            <span class="text-[11px] font-bold text-slate-500 group-hover:text-slate-800 transition-colors">
                                {{ $day['day_name'] }}
                            </span>
                            <span class="text-[10px] font-semibold text-slate-400 group-hover:text-slate-600 transition-colors -mt-0.5">
                                {{ $day['formatted_date'] }}
                            </span>
                            @if($day['status'] === 'orange' && in_array($day['date'], $freezeableDates))
                                <button type="button" 
                                        wire:click="openFreezeModal('{{ $day['date'] }}', '{{ $day['day_name'] }}', '{{ $day['formatted_date'] }}')"
                                        class="mt-1 flex items-center gap-0.5 px-2 py-0.5 bg-gradient-to-r from-blue-500 to-cyan-500 hover:from-blue-600 hover:to-cyan-600 text-[10px] text-white font-bold rounded-full shadow-sm hover:shadow transition-all duration-150 transform hover:scale-105 active:scale-95">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="size-3 text-white">
                                        <line x1="12" y1="2" x2="12" y2="22"></line>
                                        <line x1="2" y1="12" x2="22" y2="12"></line>
                                        <line x1="4.93" y1="4.93" x2="19.07" y2="19.07"></line>
                                        <line x1="19.07" y1="4.93" x2="4.93" y2="19.07"></line>
                                        <path d="m20 8-2 2-2-2"></path>
                                        <path d="m4 16 2-2 2 2"></path>
                                        <path d="m8 4 2 2-2 2"></path>
                                        <path d="m16 20-2-2 2-2"></path>
                                        <path d="m8 20 2-2-2-2"></path>
                                        <path d="m16 4-2 2 2 2"></path>
                                        <path d="m4 8 2 2-2 2"></path>
                                        <path d="m20 16-2-2 2 2"></path>
                                    </svg>
                                    <span>تجميد</span>
                                </button>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </div>

    <!-- Pending Team Purchases & Votes -->
    @if($studentTeam && $pendingTeamPurchases->isNotEmpty())
        <div class="relative z-10 space-y-4">
            <flux:heading size="lg" class="text-slate-900 flex items-center gap-2 font-black">
                <flux:icon icon="shopping-bag" class="size-6 text-slate-900" />
                {{ __('طلبات شراء وتصويتات جارية ل') }}{{ $theme['team_possessive_your'] }}
            </flux:heading>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                @foreach($pendingTeamPurchases as $purchase)
                    @php
                        $hasVoted = array_key_exists($purchase->id, $studentVotes);
                        $myVote = $hasVoted ? $studentVotes[$purchase->id] : null;
                        
                        $votesMap = $purchase->votes->pluck('vote', 'student_id')->toArray();
                        $assistants = $teamStudents->filter(fn($s) => $s->pivot->role === 'assistant');
                        $members = $teamStudents->filter(fn($s) => $s->pivot->role === 'member');
                    @endphp
                    <div class="bg-white border border-slate-200 rounded-2xl p-5 flex flex-col justify-between gap-4 shadow-sm hover:shadow-md transition-shadow">
                        <div>
                            <div class="flex items-center justify-between mb-2">
                                <flux:badge color="amber">{{ __('بانتظار تصويت ال') }}{{ $theme['team_name'] }}</flux:badge>
                                <span class="text-xs text-slate-400">{{ $purchase->created_at->diffForHumans() }}</span>
                            </div>
                            <h4 class="font-bold text-slate-800 text-base">
                                {{ __('طلب شراء ميزة:') }} <span class="{{ $style['accent'] }}">{{ $purchase->item->name }}</span>
                            </h4>
                            <p class="text-xs text-slate-500 mt-1 leading-relaxed">
                                {{ __('صاحب الطلب:') }} {{ $purchase->student?->name ?? __('طالب محذوف') }} — {{ __('السعر من خزينة ال') }}{{ $theme['team_name'] }}: {{ $purchase->price_paid }} {!! $this->renderEmoji($style['coin_emoji'], 'size-3.5 inline-block align-middle') !!}
                            </p>

                            <div class="mt-4 pt-3 border-t border-slate-100 space-y-3.5 text-xs text-right" dir="rtl">
                                <div>
                                    <span class="font-bold text-slate-700 block mb-2">{{ __('متطلبات الموافقة:') }}</span>
                                    <div class="space-y-1.5 mr-2">
                                        <!-- Assistant vote requirement if item requires it and team has assistants -->
                                        @if($purchase->item->require_assistant_approval && $assistants->isNotEmpty())
                                            @php
                                                $assistantHasApproved = $purchase->votes->whereIn('student_id', $assistants->pluck('id'))->where('vote', true)->isNotEmpty();
                                            @endphp
                                            <div class="flex items-center gap-2 text-slate-600">
                                                @if($assistantHasApproved)
                                                    <flux:icon icon="check-circle" class="size-4 text-emerald-600 shrink-0" />
                                                @else
                                                    <flux:icon icon="clock" class="size-4 text-slate-400 shrink-0" />
                                                @endif
                                                <span>
                                                    {{ __('موافقة مساعد القائد (النائب)') }}
                                                </span>
                                            </div>
                                        @endif

                                        <!-- Member approval requirement -->
                                        @php
                                            $threshold = $purchase->item->require_member_approval_count;
                                            if ($threshold <= 0 && !$purchase->item->require_assistant_approval) {
                                                $threshold = intval($teamStudents->count() / 2) + 1;
                                            }
                                            $yesVotesCount = $purchase->votes->where('vote', true)->count();
                                            $noVotesCount = $purchase->votes->where('vote', false)->count();
                                            $memberApprovalMet = $yesVotesCount >= $threshold;
                                        @endphp
                                        @if($threshold > 0)
                                            <div class="flex items-center gap-2 text-slate-600">
                                                @if($memberApprovalMet)
                                                    <flux:icon icon="check-circle" class="size-4 text-emerald-600 shrink-0" />
                                                @else
                                                    <flux:icon icon="clock" class="size-4 text-slate-400 shrink-0" />
                                                @endif
                                                <span>
                                                    {{ __('موافقة :threshold من أعضاء الفريق (الحالي: :yesCount موافق / :noCount رافض)', ['threshold' => $threshold, 'yesCount' => $yesVotesCount, 'noCount' => $noVotesCount]) }}
                                                </span>
                                            </div>
                                        @endif
                                    </div>
                                </div>

                                <div class="pt-3 border-t border-slate-100/60">
                                    <span class="font-bold text-slate-700 block mb-2">{{ __('الأعضاء الذين صوتوا:') }}</span>
                                    <div class="space-y-1.5 mr-2">
                                        @forelse($purchase->votes as $vote)
                                            @php
                                                // A voter deleted while the purchase is pending has no account
                                                // left to name, but the vote still counts toward the decision,
                                                // so it stays listed, as «طالب محذوف». Read as-is, it 500ed the
                                                // page for the whole team until the purchase was decided.
                                                $voter = $vote->student;
                                                $voterTeamStudent = $voter ? $teamStudents->firstWhere('id', $voter->id) : null;
                                                $voterRole = $voterTeamStudent ? $voterTeamStudent->pivot->role : 'member';
                                                $roleLabel = $voterRole === 'leader' ? __('القائد') : ($voterRole === 'assistant' ? __('النائب') : __('عضو'));
                                            @endphp
                                            <div class="flex items-center gap-2 text-slate-600">
                                                @if($vote->vote)
                                                    <flux:icon icon="check" class="size-4 text-emerald-500 shrink-0" />
                                                @else
                                                    <flux:icon icon="x-mark" class="size-4 text-rose-500 shrink-0" />
                                                @endif
                                                <span class="font-medium">
                                                    {{ $voter?->name ?? __('طالب محذوف') }}
                                                    @if($voter)
                                                        <span class="text-[10px] text-slate-400">({{ $roleLabel }})</span>
                                                    @endif
                                                </span>
                                            </div>
                                        @empty
                                            <div class="text-slate-400 italic text-[11px] py-1">
                                                {{ __('لا توجد أصوات مسجلة بعد.') }}
                                            </div>
                                        @endforelse
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="flex items-center gap-2">
                            @if($hasVoted)
                                <div class="w-full flex items-center justify-center gap-2 py-2 rounded-xl bg-zinc-900 border border-zinc-800 text-xs font-semibold text-zinc-400">
                                    <span>{{ __('تم تصويتك بالفعل:') }} {{ $myVote ? __('موافق') : __('رافض') }}</span>
                                    @if($myVote)
                                        <flux:icon icon="check" class="size-4 text-emerald-500 shrink-0" />
                                    @else
                                        <flux:icon icon="x-mark" class="size-4 text-rose-500 shrink-0" />
                                    @endif
                                </div>
                            @else
                                <flux:button wire:click="votePurchase({{ $purchase->id }}, true)" size="sm" color="emerald" class="flex-1 font-bold" icon="check">
                                    {{ __('موافق') }}
                                </flux:button>
                                <flux:button wire:click="votePurchase({{ $purchase->id }}, false)" size="sm" color="red" class="flex-1 font-bold" icon="x-mark">
                                    {{ __('رافض') }}
                                </flux:button>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif
    </div>

    <!-- Main Store & Badges & Leaderboard & Missions tabs -->
    <div class="relative z-10 space-y-6 p-3 md:p-8 min-h-[calc(100svh-4rem)]">


        <!-- Store Content -->
        <div x-show="currentTab === 'store'" x-cloak class="space-y-4">
            @if($storeItems->isEmpty())
                <div class="text-center py-12 bg-slate-50 border border-slate-200 rounded-2xl">
                    <flux:icon icon="shopping-bag" class="size-10 mx-auto text-slate-400 mb-3" />
                    <h4 class="font-bold text-slate-500 text-sm">{{ __('المتجر فارغ حالياً') }}</h4>
                </div>
            @else
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    @foreach($storeItems as $item)
                        @php
                            /*
                             * Whether buying starts a team vote, by the same rule
                             * requestStorePurchase() applies: the product asks for
                             * an assistant's or members' approval, or the competition
                             * puts every team purchase to a vote. A product needing
                             * approval was offered as a plain «شراء» while the
                             * global setting was off, then started a vote anyway.
                             *
                             * A team of one has nobody to vote, so the service buys
                             * at once. The button used to wait for "other members"
                             * and stayed disabled, so its leader could never buy a
                             * team product at all; now it is a plain purchase.
                             */
                            $isTeamItem = (bool) $item->is_team_product;
                            $requiresVoting = $isTeamItem && (
                                $item->require_assistant_approval
                                || (int) $item->require_member_approval_count > 0
                                || ($activeGamification->settings['team_purchase_voting_enabled'] ?? false)
                            );

                            $reqTeam = $studentTeam !== null;
                            $reqRole = $teamRole === 'leader';
                            $reqCoins = $studentTeam && ($studentTeam->coins >= $item->price);
                            $reqMembers = $studentTeam && ($teamStudents->count() > 1);

                            $buysAlone = $reqTeam && ! $reqMembers;
                            $startsVote = $requiresVoting && ! $buysAlone;

                            $canAfford = $isTeamItem
                                ? ($reqTeam && $reqRole && $reqCoins)
                                : ($gamificationState && $gamificationState->coins >= $item->price);
                        @endphp
                        <div class="bg-white border border-slate-200 rounded-2xl p-5 flex flex-col justify-between gap-4 transition-all duration-300 hover:border-team-primary/40 hover:shadow-md shadow-sm">
                            <div>
                                <div class="flex items-center justify-between mb-2">
                                    <flux:badge color="{{ $isTeamItem ? 'indigo' : 'emerald' }}" size="sm">
                                        {{ $isTeamItem ? __('ميزة جماعية ل') . $theme['team_possessive_your'] : __('ميزة فردية') }}
                                    </flux:badge>
                                    <span class="text-xs text-slate-500 font-bold">
                                        {{ $item->price }} {!! $this->renderEmoji($style['coin_emoji'], 'size-3.5 inline-block align-middle') !!}
                                    </span>
                                </div>
                                <h4 class="font-bold text-slate-900 text-base leading-tight">{{ $item->name }}</h4>
                                <p class="text-xs text-slate-500 mt-1 leading-relaxed">{{ $item->description }}</p>
                                @if($item->target_date)
                                    <div class="mt-2.5 text-xs text-team-primary flex items-center gap-1.5 font-bold">
                                        <flux:icon icon="calendar" class="size-3.5" />
                                        <span>{{ __('تاريخ اليوم المحدد:') }} <x-hijri-date :date="$item->target_date" /></span>
                                    </div>
                                @endif

                                @if($item->item_type === 'team_attack')
                                    <div class="mt-2.5 space-y-1 text-right" dir="rtl">
                                        <label class="text-[11px] text-slate-500 block font-bold">ال{{ $theme['team_name'] }} المستهدفة للخصم:</label>
                                        <select wire:model="targetTeams.{{ $item->id }}" class="w-full text-xs rounded-xl border border-slate-200 bg-white px-3 py-2 text-slate-800 focus:outline-none focus:ring-1 focus:ring-team-primary focus:border-team-primary">
                                            <option value="">{{ __('اختر') }} {{ $theme['team_name'] }}...</option>
                                            @foreach($otherTeams as $ot)
                                                <option value="{{ $ot->id }}">{{ $ot->name }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                @elseif(in_array($item->item_type, ['multiplier', 'shield']) && !$item->target_date)
                                    <div class="mt-2.5 space-y-1 text-right" dir="rtl">
                                        <label class="text-[11px] text-slate-500 block font-bold">{{ __('تاريخ التفعيل (من الغد فصاعداً):') }}</label>
                                        <livewire:shared.hijri-datepicker wire:model="targetDates.{{ $item->id }}" label="" :key="'target-date-'.$item->id" />
                                    </div>
                                @endif
                            </div>

                            @if($startsVote)
                                <div data-team-vote-requirements class="p-3.5 rounded-2xl bg-slate-50 border border-slate-200/60 space-y-2.5 text-xs text-right" dir="rtl">
                                    <span class="font-bold text-slate-700 block mb-1">{{ __('متطلبات بدء تصويت المجموعة:') }}</span>
                                    <div class="space-y-2">
                                        <!-- Requirement 1: Team Registration -->
                                        <div class="flex items-center gap-2.5">
                                            <span class="flex items-center justify-center size-5 rounded-full {{ $reqTeam ? 'bg-emerald-100 text-emerald-600' : 'bg-slate-100 text-slate-400' }} text-[10px] font-black shrink-0">
                                                {{ $reqTeam ? '✓' : '✗' }}
                                            </span>
                                            <span class="{{ $reqTeam ? 'text-slate-700 font-semibold' : 'text-slate-400' }}">
                                                {{ __('التسجيل في ') }}{{ $theme['team_name'] }}
                                            </span>
                                        </div>

                                        <!-- Requirement 2: Role check -->
                                        <div class="flex items-center gap-2.5">
                                            <span class="flex items-center justify-center size-5 rounded-full {{ $reqRole ? 'bg-emerald-100 text-emerald-600' : 'bg-slate-100 text-slate-400' }} text-[10px] font-black shrink-0">
                                                {{ $reqRole ? '✓' : '✗' }}
                                            </span>
                                            <span class="{{ $reqRole ? 'text-slate-700 font-semibold' : 'text-slate-400' }}">
                                                {{ __('رتبتك قائد ال') }}{{ $theme['team_name'] }}
                                            </span>
                                        </div>

                                        <!-- Requirement 3: Team Coins -->
                                        <div class="flex items-center gap-2.5">
                                            <span class="flex items-center justify-center size-5 rounded-full {{ $reqCoins ? 'bg-emerald-100 text-emerald-600' : 'bg-slate-100 text-slate-400' }} text-[10px] font-black shrink-0">
                                                {{ $reqCoins ? '✓' : '✗' }}
                                            </span>
                                            <span class="{{ $reqCoins ? 'text-slate-700 font-semibold' : 'text-slate-400' }} flex items-center gap-1">
                                                <span>{{ __('رصيد الخزينة كافٍ (') }}{{ $item->price }}</span>
                                                {!! $this->renderEmoji($style['coin_emoji'], 'size-3.5 inline-block align-middle') !!}
                                                <span>)</span>
                                            </span>
                                        </div>

                                        <!-- Requirement 4: Other Members for voting -->
                                        <div class="flex items-center gap-2.5">
                                            <span class="flex items-center justify-center size-5 rounded-full {{ $reqMembers ? 'bg-emerald-100 text-emerald-600' : 'bg-slate-100 text-slate-400' }} text-[10px] font-black shrink-0">
                                                {{ $reqMembers ? '✓' : '✗' }}
                                            </span>
                                            <span class="{{ $reqMembers ? 'text-slate-700 font-semibold' : 'text-slate-400' }}">
                                                {{ __('وجود أعضاء آخرين في ال') }}{{ $theme['team_name'] }}{{ __(' للمشاركة بالتصويت') }}
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            @endif

                            <flux:button wire:click="buyItem({{ $item->id }})" :disabled="!$canAfford" data-store-buy="{{ $item->id }}" class="w-full font-bold {{ $style['btn'] }} disabled:opacity-40 disabled:cursor-not-allowed">
                                <span>{{ $startsVote ? __('بدء تصويت وشراء بـ ') : __('شراء بـ ') }} {{ $item->price }}</span>
                                {!! $this->renderEmoji($style['coin_emoji'], 'size-4 inline-block align-middle') !!}
                            </flux:button>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        <!-- Badges Content -->
        <div x-show="currentTab === 'badges'" x-cloak class="space-y-4">
            @if($allBadges->isEmpty())
                <div class="text-center py-12 bg-slate-50 border border-slate-200 rounded-2xl">
                    <flux:icon icon="trophy" class="size-10 mx-auto text-slate-400 mb-3" />
                    <h4 class="font-bold text-slate-500 text-sm">{{ __('لا توجد أوسمة مهيأة لهذه المسابقة') }}</h4>
                </div>
            @else
                <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-6">
                    @foreach($allBadges as $badge)
                        @php
                            $earned = in_array($badge->id, $studentBadges);
                            $progress = $badgeProgress[$badge->id] ?? null;
                            $status = $badgeStatuses[$badge->id] ?? null;

                            // A manual badge is awarded by hand (no automatic progress
                            // to track): calculateBadgeValue returns null for it.
                            $isManual = $badge->badge_type === 'manual' || $progress === null;

                            // Auto badge whose live progress already meets the
                            // requirement (e.g. requirement lowered/added after the
                            // student qualified) but no award row exists yet.
                            $reachedRequirement = ! $isManual && $progress['required'] > 0 && $progress['remaining'] === 0;

                            // Award states: approved and waiting to be claimed, or
                            // pending teacher approval / requirement just reached.
                            $awaitingClaim = ! $earned && $status === 'approved';
                            $awaitingApproval = ! $earned && ! $awaitingClaim && ($status === 'pending_approval' || $reachedRequirement);

                            // Show the progress bar only for locked, auto-tracked badges
                            // that still need more achievements.
                            $showProgress = ! $earned && ! $awaitingClaim && ! $awaitingApproval && ! $isManual
                                && $progress['required'] > 0 && $progress['remaining'] > 0;
                            $progressPercent = $showProgress
                                ? min(100, (int) round(($progress['value'] / max(1, $progress['required'])) * 100))
                                : 0;
                        @endphp
                        <div class="rounded-2xl p-5 flex flex-col items-center text-center gap-3 border shadow-sm transition-all duration-300 {{ $earned ? 'bg-team-10 border-team-20 text-slate-800 shadow-team-10/20' : 'bg-slate-50/60 border-slate-200/60 opacity-60 text-slate-400' }}" title="{{ $badge->description }}">
                            <div class="w-16 h-16 rounded-full flex items-center justify-center text-3xl {{ $earned ? 'bg-white text-team-primary border border-team-20 shadow-md' : 'bg-slate-100 text-slate-400' }} overflow-hidden">
                                @if(str_contains($badge->icon, '/') || str_contains($badge->icon, '.'))
                                    <img src="{{ asset('storage/' . $badge->icon) }}" class="w-full h-full object-contain p-2 {{ $earned ? '' : 'grayscale opacity-40' }}" alt="{{ $badge->name }}">
                                @else
                                    @if($badge->icon === 'sparkles' || $badge->icon === 'sparkle') ✨
                                    @elseif($badge->icon === 'fire') 🔥
                                    @elseif($badge->icon === 'rocket') 🚀
                                    @elseif($badge->icon === 'crown') 👑
                                    @elseif($badge->icon === 'bolt') ⚡
                                    @elseif($badge->icon === 'trophy') 🏆
                                    @elseif($badge->icon === 'star') ⭐
                                    @elseif($badge->icon === 'shield') 🛡️
                                    @elseif($badge->icon === 'heart') ❤️
                                    @else 🏅 @endif
                                @endif
                            </div>
                            <div>
                                <h5 class="font-bold text-sm text-slate-800 leading-snug">{{ $badge->name }}</h5>
                                <p class="text-[10px] text-slate-500 mt-1 line-clamp-2 leading-relaxed">{{ $badge->description }}</p>
                            </div>
                            {{-- The two «اكتمل —» labels wrap: on one line they ran past a
                                 phone's two-column card into the next one. The ! beats Flux's nowrap. --}}
                            @if($earned)
                                <flux:badge color="emerald" size="sm" icon="check-circle">{{ __('مكتمل') }}</flux:badge>
                            @elseif($awaitingClaim)
                                <flux:badge color="amber" size="sm" icon="gift" class="max-w-full whitespace-normal! text-center leading-tight">{{ __('اكتمل — بانتظار الاستلام') }}</flux:badge>
                            @elseif($awaitingApproval)
                                <flux:badge color="blue" size="sm" icon="clock" class="max-w-full whitespace-normal! text-center leading-tight">{{ __('اكتمل — بانتظار الاعتماد') }}</flux:badge>
                            @elseif($isManual)
                                <flux:badge color="purple" size="sm" icon="hand-raised">{{ __('يُمنح يدوياً') }}</flux:badge>
                            @else
                                <flux:badge color="zinc" size="sm">{{ __('مغلق') }}</flux:badge>
                            @endif

                            @if($showProgress)
                                <div class="w-full mt-1 space-y-1.5">
                                    <div class="h-1.5 w-full rounded-full bg-slate-200 overflow-hidden">
                                        <div class="h-full rounded-full bg-team-primary transition-all duration-500" style="width: {{ $progressPercent }}%"></div>
                                    </div>
                                    <p class="text-[10px] font-bold text-slate-500 leading-tight">
                                        @if($progress['is_streak'])
                                            {{ __('متتالية:') }}
                                        @endif
                                        <span class="text-team-primary">{{ $progress['value'] }}</span> / {{ $progress['required'] }}
                                    </p>
                                    <p class="text-[10px] text-amber-600 font-semibold leading-tight">
                                        {{ __('يتبقّى') }} {{ $progress['remaining'] }}
                                        @if($progress['is_streak'])
                                            {{ __('يوم متتالٍ') }}
                                        @else
                                            {{ __('إنجاز') }}
                                        @endif
                                    </p>
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        <!-- Leaderboard Content -->
        <div x-show="currentTab === 'leaderboard'" x-cloak class="space-y-6">
            <!-- Quranic Missions Section inside Main Tab -->
            <div class="space-y-4">
                @php
                    $showTeamMultiplier = false;
                    if ($activeGamification && isset($gamificationLevelInfo['perks']) && $studentTeam && $teamRole === 'leader') {
                        $lvlSettings = $gamificationLevelInfo['perks']->settings ?? [];
                        $showTeamMultiplier = (bool) ($lvlSettings['has_team_multiplier'] ?? true);
                    }
                @endphp
                <flux:heading size="lg" class="text-slate-900 flex items-center gap-2 font-black">
                    <svg class="size-6 text-slate-600 dark:text-slate-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"></path>
                    </svg>
                    {{ __('المهام والخطط') }}
                </flux:heading>

                {{--
                    This dashboard replaces the plain one whenever a competition is
                    running, so the plan links have to exist here too or most students
                    never see them.
                --}}
                @php
                    $planLinks = collect()
                        ->concat($activeApprovedPlans->map(fn ($p) => ['kind' => 'quran', 'id' => $p->id, 'label' => __('خطة الحفظ والمراجعة')]))
                        ->concat($activeHadithPlans->map(fn ($p) => ['kind' => 'hadith', 'id' => $p->id, 'label' => __('خطة المتن')]))
                        ->concat($activeOdePlans->map(fn ($p) => ['kind' => 'ode', 'id' => $p->id, 'label' => $p->path?->ode?->name ?? __('خطة المنظومة')]));
                @endphp

                @if($planLinks->isNotEmpty())
                    <div class="flex flex-wrap gap-2">
                        @foreach($planLinks as $link)
                            <a href="{{ route('student.plan.print', ['kind' => $link['kind'], 'id' => $link['id']]) }}"
                                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-white border border-slate-200 text-slate-700 text-xs font-bold shadow-sm hover:bg-slate-50 transition-colors">
                                <flux:icon icon="printer" class="size-4" />
                                {{ __('عرض وطباعة') }}: {{ $link['label'] }}
                            </a>
                        @endforeach
                    </div>
                @endif
                @if(empty($pendingMissions))
                    <div class="bg-white border border-slate-200 rounded-2xl p-5 text-center shadow-sm">
                        <p class="text-sm text-slate-500">{{ __('لا توجد مهام قرآنية معلقة حالياً') }}</p>
                    </div>
                @else
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        @foreach($pendingMissions as $m)
                            <div class="bg-white border border-slate-200 rounded-2xl p-5 space-y-4 shadow-sm">
                                <div>
                                    <div class="flex items-center justify-between mb-1">
                                        <flux:badge color="indigo" size="sm">
                                            {{ $m->pendingPart === 'review' ? __('مراجعة') : __('حفظ') }}
                                        </flux:badge>
                                        <span class="text-xs text-slate-400">{{ $m->day_name }} (<x-hijri-date :date="$m->date" />)</span>
                                    </div>
                                    @php
                                        /*
                                         * The mission is the earliest ungraded day, which is rarely
                                         * tomorrow: usually today, and for a skipped day or a plan
                                         * that has run out, a day long past. The heading said
                                         * «غداً» regardless, beside a date that said otherwise, so
                                         * it now names the day the date is, in Riyadh's calendar.
                                         */
                                        $missionDate = $m->date->toDateString();
                                        $missionToday = \App\Services\TeacherSyncSnapshot::today();
                                        $missionHeading = match (true) {
                                            $missionDate < $missionToday => __('مهمة متأخرة لم تُسمَّع بعد'),
                                            $missionDate === $missionToday => __('مهمة اليوم'),
                                            $missionDate === now('Asia/Riyadh')->addDay()->toDateString() => __('المهمة المطلوبة غداً'),
                                            default => __('المهمة القادمة'),
                                        };
                                    @endphp
                                    <h4 class="font-black text-slate-900 text-lg leading-tight mt-2">
                                        {{ $missionHeading }}
                                    </h4>
                                </div>

                                @php
                                    // What an «ممتاز» pays here, as grading pays it; 0 when the competition doesn't reward this part.
                                    $maxXP = \App\Services\GamificationService::excellentGradePoints($activeGamification->settings ?? [], $m->pendingPart)['xp'];
                                @endphp
                                <div class="flex flex-col sm:flex-row items-stretch gap-4 bg-slate-50/60 rounded-xl p-3 border border-slate-100">
                                    <div class="space-y-3 flex-1">
                                        @if($m->pendingPart === 'hifz')
                                            <div>
                                                <span class="text-[10px] text-slate-400 font-bold block uppercase tracking-wider">{{ __('مقرر الحفظ:') }}</span>
                                                <p class="text-slate-800 text-sm font-semibold mt-0.5">{{ $m->formatRange('hifz') ?? 'لا يوجد نص محدد' }}</p>
                                            </div>
                                        @endif
                                        @if($m->pendingPart === 'review')
                                            <div>
                                                <span class="text-[10px] text-slate-400 font-bold block uppercase tracking-wider">{{ __('مقرر المراجعة:') }}</span>
                                                <p class="text-slate-800 text-sm font-semibold mt-0.5">{{ $m->formatRange('review') ?? 'لا يوجد نص محدد' }}</p>
                                            </div>
                                        @endif
                                    </div>
                                    @if($maxXP > 0 || $showTeamMultiplier)
                                    <div class="flex flex-row sm:flex-col items-center sm:items-end justify-between sm:justify-center gap-3 pt-3 sm:pt-0 sm:pr-4 border-t sm:border-t-0 sm:border-r border-slate-200/60">
                                        @if($maxXP > 0)
                                            <div data-mission-xp class="text-right">
                                                <span class="text-[9px] text-slate-400 font-bold block">{{ __('النقاط المتاحة') }}</span>
                                                <span class="text-base font-black text-emerald-600">+{{ $maxXP }} XP</span>
                                            </div>
                                        @endif
                                        @if($showTeamMultiplier)
                                            <flux:button variant="primary" wire:click="openDoublePointsModal('{{ $m->date->format('Y-m-d') }}', 'team')" size="sm" class="bg-team-primary hover:bg-team-primary-hover border-none font-bold !text-white shadow-sm" style="color: white !important;" icon="bolt">
                                                {{ __('مضاعفة النقاط') }}
                                            </flux:button>
                                        @endif
                                    </div>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif

                <flux:heading size="lg" class="text-slate-900 flex items-center gap-2 font-black mt-6">
                    <svg class="size-6 text-slate-600 dark:text-slate-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"></path>
                    </svg>
                    {{ __('مهام حفظ الحديث المجدولة') }}
                </flux:heading>
                @if(empty($pendingHadithMissions))
                    <div class="bg-white border border-slate-200 rounded-2xl p-5 text-center shadow-sm">
                        <p class="text-sm text-slate-500">{{ __('لا توجد مهام حفظ حديث معلقة حالياً') }}</p>
                    </div>
                @else
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        @foreach($pendingHadithMissions as $hm)
                            @php
                                $hasHifz = ($hm->from_line_number && $hm->to_line_number) || ($hm->from_hadith_id && $hm->to_hadith_id);
                                $hasReview = ($hm->review_from_line_number && $hm->review_to_line_number) || ($hm->review_from_hadith_id && $hm->review_to_hadith_id);
                                $allHadiths = $hm->allHadiths ?? collect();
                                            
                                // Calculate current and previous Hadiths
                                $hifzHadiths = collect();
                                if ($hm->memorize_type === 'hadiths' && $hm->from_hadith_id && $hm->to_hadith_id) {
                                    $startIdx = $allHadiths->search(fn($h) => $h->id == $hm->from_hadith_id);
                                    $endIdx = $allHadiths->search(fn($h) => $h->id == $hm->to_hadith_id);
                                    if ($startIdx !== false && $endIdx !== false) {
                                        $hifzHadiths = $allHadiths->slice($startIdx, $endIdx - $startIdx + 1);
                                    }
                                } elseif ($hm->memorize_type === 'lines' && $hm->from_hadith_id) {
                                    $hadith = $allHadiths->first(fn($h) => $h->id == $hm->from_hadith_id);
                                    if ($hadith) {
                                        $hifzHadiths->push($hadith);
                                    }
                                }

                                $firstHifzHadith = $hifzHadiths->first();
                                $firstHifzIdx = $firstHifzHadith ? $allHadiths->search(fn($h) => $h->id == $firstHifzHadith->id) : false;
                                $previousHifzHadiths = $firstHifzIdx !== false && $firstHifzIdx > 0
                                    ? $allHadiths->slice(0, $firstHifzIdx)->values()
                                    : collect();
                            @endphp
                            <div x-data="{ showTextModal: false, prevCount: 0 }" class="bg-white border border-slate-200 rounded-2xl p-5 space-y-4 shadow-sm">
                                <div>
                                    <div class="flex items-center justify-between mb-1">
                                        <flux:badge color="rose" size="sm">
                                            {{ $hm->plan->path->name }}
                                        </flux:badge>
                                        <span class="text-xs text-slate-400">{{ $hm->day_name }} (<x-hijri-date :date="$hm->date" />)</span>
                                    </div>
                                    <h4 class="font-black text-slate-900 text-lg leading-tight mt-2">
                                        {{ __('المهمة المطلوبة') }}
                                    </h4>
                                </div>

                                <div class="flex flex-col sm:flex-row items-stretch gap-4 bg-slate-50/60 rounded-xl p-3 border border-slate-100">
                                    <div class="space-y-3 flex-1">
                                        @if($hasHifz)
                                            <div>
                                                <span class="text-[10px] text-slate-400 font-bold block uppercase tracking-wider">{{ __('مقرر الحفظ:') }}</span>
                                                <p class="text-slate-800 text-sm font-semibold mt-0.5">{{ $hm->formatHadithRange('hifz') ?? 'لا يوجد نص محدد' }}</p>
                                            </div>
                                        @endif
                                        @if($hasReview)
                                            <div>
                                                <span class="text-[10px] text-slate-400 font-bold block uppercase tracking-wider">{{ __('مقرر المراجعة:') }}</span>
                                                <p class="text-slate-800 text-sm font-semibold mt-0.5">{{ $hm->formatHadithRange('review') ?? 'لا يوجد نص محدد' }}</p>
                                            </div>
                                        @endif
                                    </div>
                                </div>

                                <div class="pt-3 border-t border-slate-100 flex justify-end">
                                    <button type="button" @click="showTextModal = true" class="flex items-center gap-2 px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 dark:text-slate-300 dark:bg-zinc-800 dark:hover:bg-zinc-700 rounded-lg text-xs font-bold transition-all shadow-sm">
                                        <flux:icon icon="book-open" class="size-3.5" />
                                        <span>{{ __('إظهار نص الحديث') }}</span>
                                    </button>
                                </div>

                                {{--
                                    Hadith Text Modal for Student. It stacks above the phone's
                                    bottom bar (z-[9999]), which used to hide the «إغلاق» footer
                                    and switch tabs behind the open modal when tapped.
                                --}}
                                <template x-teleport="body">
                                <div x-show="showTextModal" 
                                     x-transition:enter="transition ease-out duration-300"
                                     x-transition:enter-start="opacity-0 translate-y-4"
                                     x-transition:enter-end="opacity-100 translate-y-0"
                                     x-transition:leave="transition ease-in duration-200"
                                     x-transition:leave-start="opacity-100 translate-y-0"
                                     x-transition:leave-end="opacity-0 translate-y-4"
                                     data-hadith-text-modal
                                     class="fixed inset-0 z-[10000] bg-white dark:bg-zinc-900 flex flex-col w-full h-full text-zinc-900 dark:text-white"
                                     x-cloak>
                                     
                                     {{-- Modal Header --}}
                                     <div class="flex items-center justify-between p-5 border-b border-zinc-100 dark:border-zinc-900 bg-zinc-50/50 dark:bg-zinc-900/50 shrink-0">
                                         <div>
                                             <h3 class="font-bold text-lg text-zinc-900 dark:text-white leading-tight">
                                                 {{ __('نص الحديث') }}
                                             </h3>
                                             <p class="text-xs text-rose-600 dark:text-rose-400 mt-1 font-semibold">
                                                 {{ $hm->plan->path->name ?? '' }} ({{ $hm->formatHadithRange('hifz') }})
                                             </p>
                                         </div>
                                         <button type="button" @click="showTextModal = false" class="text-zinc-400 hover:text-zinc-650 dark:hover:text-zinc-200 p-1.5 rounded-lg hover:bg-zinc-100 dark:hover:bg-zinc-900 transition-colors">
                                             <flux:icon icon="x-mark" class="size-5" />
                                         </button>
                                     </div>

                                     {{-- Modal Content (Scrollable) --}}
                                     <div class="flex-1 overflow-y-auto p-4 md:p-6 space-y-6 bg-zinc-50/30 dark:bg-zinc-900/30 text-right">
                                         <div class="w-full space-y-8">
                                             {{-- Previous Hadiths Button --}}
                                             @if($previousHifzHadiths->isNotEmpty())
                                                 <div x-show="prevCount < {{ $previousHifzHadiths->count() }}" class="flex justify-center mb-8 shrink-0">
                                                     <flux:button type="button" @click="prevCount++" icon="arrow-up" variant="subtle" class="w-full sm:w-auto font-bold text-zinc-800 dark:text-white border border-zinc-200 dark:border-zinc-850">
                                                         {{ __('إظهار الحديث السابق') }}
                                                     </flux:button>
                                                 </div>
                                             @endif

                                             {{-- Previous Hadiths (Dimmed) --}}
                                             @foreach($previousHifzHadiths as $index => $hadith)
                                                 @php
                                                     $currentHadithLines = $hadith->lines;
                                                 @endphp
                                                 <div x-show="prevCount >= {{ $previousHifzHadiths->count() - $index }}" 
                                                      x-cloak 
                                                      class="space-y-4 opacity-50 hover:opacity-100 transition-opacity duration-200">
                                                      {{-- Hadith Header (Name) --}}
                                                      <div class="text-lg font-bold text-zinc-500 dark:text-zinc-400 pb-2 border-b border-zinc-200 dark:border-zinc-800 font-serif">
                                                          {{ $hadith->name }} <span class="text-xs font-sans text-zinc-400">({{ __('سابق') }})</span>
                                                      </div>
                                                      
                                                      @if ($hadith->sanad)
                                                          <div class="p-4 bg-zinc-50 dark:bg-zinc-900 rounded-xl text-sm font-semibold text-zinc-400 dark:text-zinc-500 pr-4 border-r-4 border-zinc-300 font-serif">
                                                              <strong>{{ __('السند') }}: </strong>{{ $hadith->sanad }}
                                                          </div>
                                                      @endif

                                                      @foreach($currentHadithLines as $line)
                                                          <div class="flex items-start gap-4 p-4 md:p-6 bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800/60 shadow-sm hover:shadow-md transition-shadow">
                                                              <span class="shrink-0 flex items-center justify-center size-8 rounded-xl bg-zinc-100 dark:bg-zinc-800 text-zinc-400 dark:text-zinc-500 font-extrabold text-sm shadow-sm">
                                                                  {{ $line->line_number }}
                                                              </span>
                                                              <div class="flex-1 text-base md:text-xl font-semibold text-zinc-500 dark:text-zinc-400 leading-relaxed text-right pr-4 border-r-4 border-zinc-300 dark:border-zinc-600 font-serif">
                                                                  {{ $line->text }}
                                                              </div>
                                                          </div>
                                                      @endforeach

                                                      @if ($hadith->ruling)
                                                          <div class="p-4 bg-zinc-50 dark:bg-zinc-900 rounded-xl text-sm font-bold text-zinc-400 dark:text-zinc-500 pr-4 border-r-4 border-zinc-300">
                                                              <strong>{{ __('حكم الحديث') }}: </strong>{{ $hadith->ruling }}
                                                          </div>
                                                      @endif
                                                 </div>
                                             @endforeach

                                             {{-- Current Hadiths --}}
                                             @foreach($hifzHadiths as $hadith)
                                                 <div class="space-y-4 text-zinc-800 dark:text-zinc-105">
                                                     {{-- Hadith Header (Name) if multiple --}}
                                                     <div class="text-lg font-bold text-rose-600 dark:text-rose-400 pb-2 border-b border-rose-100 dark:border-rose-900/50 font-serif">
                                                         {{ $hadith->name }}
                                                     </div>
                                                     
                                                     @if ($hadith->sanad)
                                                         <div class="p-4 bg-zinc-100 dark:bg-zinc-800 rounded-xl text-sm font-semibold text-zinc-650 dark:text-zinc-400 pr-4 border-r-4 border-zinc-400 font-serif">
                                                             <strong>{{ __('السند') }}: </strong>{{ $hadith->sanad }}
                                                         </div>
                                                     @endif

                                                     @php
                                                         $currentHadithLines = $hadith->lines;
                                                         if ($hm->memorize_type === 'lines') {
                                                             $currentHadithLines = $currentHadithLines->filter(function ($l) use ($hm) {
                                                                 return $l->line_number <= $hm->to_line_number;
                                                             });
                                                         }
                                                     @endphp

                                                     @foreach($currentHadithLines as $line)
                                                         <div class="flex items-start gap-4 p-4 md:p-6 bg-white dark:bg-zinc-900 rounded-2xl border border-rose-100/60 dark:border-rose-950/40 shadow-sm hover:shadow-md transition-shadow">
                                                             <span class="shrink-0 flex items-center justify-center size-8 rounded-xl bg-rose-100 dark:bg-rose-900/40 text-rose-700 dark:text-rose-300 font-extrabold text-sm shadow-sm">
                                                                 {{ $line->line_number }}
                                                             </span>
                                                             <div class="flex-1 text-base md:text-xl font-semibold text-zinc-800 dark:text-zinc-150 leading-relaxed text-right pr-4 border-r-4 border-rose-500 dark:border-rose-400 font-serif">
                                                                 {{ $line->text }}
                                                             </div>
                                                         </div>
                                                     @endforeach

                                                     @if ($hadith->ruling)
                                                         <div class="p-4 bg-rose-50 dark:bg-rose-955/30 rounded-xl text-sm font-bold text-rose-700 dark:text-rose-300 pr-4 border-r-4 border-rose-500">
                                                             <strong>{{ __('حكم الحديث') }}: </strong>{{ $hadith->ruling }}
                                                         </div>
                                                     @endif
                                                 </div>
                                             @endforeach
                                         </div>
                                     </div>

                                     {{-- Modal Footer --}}
                                     <div class="p-4 pb-[max(1rem,env(safe-area-inset-bottom))] border-t border-zinc-100 dark:border-zinc-900 bg-zinc-50/50 dark:bg-zinc-900/50 flex justify-end shrink-0">
                                         <flux:button type="button" @click="showTextModal = false" variant="ghost" class="text-zinc-700 dark:text-zinc-300">
                                             {{ __('إغلاق') }}
                                         </flux:button>
                                     </div>
                                </div>
                                </template>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            {{-- Standings Table. Its id is the one the plain dashboard's table
                 carries, so the sidebar's «المتصدرون» and the rank tile both land
                 here; the scroll margin keeps the heading clear of the pinned
                 stats bar, taller from lg up where the tab strip joins it. --}}
            <div id="leaderboard-standings" class="space-y-4 scroll-mt-24 lg:scroll-mt-40">
                <flux:heading size="lg" class="text-slate-900 flex items-center gap-2 font-black">
                    <svg class="size-6 text-amber-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v5m-3 0h6m-6-14h6M4 7c0 2 1.5 4 3.5 4h1M20 7c0 2-1.5 4-3.5 4h-1m-7.5-4h9v6a4.5 4.5 0 01-9 0V7z"></path>
                    </svg>
                    {{ __('لوحة المتصدرين') }}
                </flux:heading>

                @if(empty($leaderboardStandings))
                    <div class="text-center py-12 bg-slate-50 border border-slate-200 rounded-2xl">
                        <flux:icon icon="presentation-chart-line" class="size-10 mx-auto text-slate-400 mb-3" />
                        <h4 class="font-bold text-slate-500 text-sm">{{ __('لا توجد نتائج لعرضها') }}</h4>
                    </div>
                @elseif($standingsByTrack->isNotEmpty())
                    {{-- Standings divided into tracks, each with its own ranking --}}
                    @php
                        $myTrackKey = null;
                        foreach ($standingsByTrack as $gi => $g) {
                            foreach ($g['standings'] as $s) {
                                if ($s['student']->id === $student->id) { $myTrackKey = $gi; break 2; }
                            }
                        }
                    @endphp
                    <div class="space-y-3">
                        @foreach($standingsByTrack as $gi => $group)
                            <div x-data="{ open: {{ $gi === $myTrackKey ? 'true' : 'false' }} }" class="bg-white border {{ $gi === $myTrackKey ? 'border-team-primary' : 'border-slate-200' }} rounded-2xl overflow-hidden shadow-sm">
                                <button type="button" @click="open = !open" class="w-full flex items-center justify-between gap-3 p-4 hover:bg-slate-50/50">
                                    <div class="flex items-center gap-2.5 min-w-0">
                                        <div class="size-9 rounded-xl flex items-center justify-center shrink-0 {{ $gi === $myTrackKey ? 'bg-team-10 text-team-primary' : 'bg-slate-100 text-slate-500' }}"><flux:icon icon="flag" class="size-5" /></div>
                                        <div class="min-w-0 text-right">
                                            <div class="font-bold text-slate-900 flex items-center gap-2">
                                                <span class="truncate">{{ $group['name'] }}</span>
                                                @if($gi === $myTrackKey)<span class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-team-10 text-team-primary shrink-0">{{ __('مسارك') }}</span>@endif
                                            </div>
                                            @if($group['description'])<div class="text-xs text-slate-400 truncate">{{ $group['description'] }}</div>@endif
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-2 shrink-0">
                                        <span class="text-xs text-slate-400">{{ count($group['standings']) }}</span>
                                        <flux:icon icon="chevron-down" class="size-4 text-slate-400 transition-transform" x-bind:class="open ? 'rotate-180' : ''" />
                                    </div>
                                </button>
                                <div x-show="open" x-collapse x-cloak class="border-t border-slate-100 overflow-x-auto">
                                    <table class="w-full text-right">
                                        <tbody class="divide-y divide-slate-100">
                                            @foreach($group['standings'] as $standing)
                                                @php $rank = $standing['track_rank']; $isMe = $standing['student']->id === $student->id; @endphp
                                                <x-student.partials.leaderboard-row :standing="$standing" :rank="$rank" :is-me="$isMe" />
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <!-- Themed Leaderboard Table -->
                    <div class="bg-white border border-slate-200 rounded-2xl overflow-hidden shadow-sm">
                        <div class="overflow-x-auto">
                            <table class="w-full text-right">
                                <thead class="bg-slate-50/80 border-b border-slate-200">
                                    <tr>
                                        <th class="p-1 w-1 font-bold text-slate-500 text-center ">{{ __('') }}</th>
                                        <th class="p-4 font-bold text-slate-500">{{ __('اسم الطالب') }}</th>
                                        <th class="p-4 font-bold text-slate-500 text-center w-24">{{ __('مجموع النقاط') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    @foreach($leaderboardStandings as $index => $standing)
                                        @php $rank = $index + 1; $isMe = $standing['student']->id === $student->id; @endphp
                                        <x-student.partials.leaderboard-row :standing="$standing" :rank="$rank" :is-me="$isMe" />
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endif
            </div>
        </div>

        <!-- News / Daily digest Content -->
        {{-- data-news-max-id: the newest news drawn here, which the news badge
             marks as seen when the tab opens (gamification-news-badge). --}}
        <div x-show="currentTab === 'news'" x-cloak class="space-y-4"
            data-news-max-id="{{ (int) collect($dailyDigest)->flatten(1)->max('id') }}">
            @php
                $typeMeta = [
                    'level_up'            => ['label' => 'ارتقاء المستويات', 'icon' => 'arrow-trending-up', 'head' => 'bg-emerald-50 text-emerald-700', 'ic' => 'text-emerald-600', 'dot' => 'bg-emerald-400'],
                    'badge'               => ['label' => 'الأوسمة',          'icon' => 'trophy',            'head' => 'bg-amber-50 text-amber-700',     'ic' => 'text-amber-600',   'dot' => 'bg-amber-400'],
                    'activity_win'        => ['label' => 'الفعاليات والجولات','icon' => 'flag',             'head' => 'bg-indigo-50 text-indigo-700',   'ic' => 'text-indigo-600',  'dot' => 'bg-indigo-400'],
                    'team_attack'         => ['label' => 'خصومات الأسر',      'icon' => 'bolt',             'head' => 'bg-rose-50 text-rose-700',       'ic' => 'text-rose-600',    'dot' => 'bg-rose-400'],
                    'team_attack_blocked' => ['label' => 'هجمات مصدودة',      'icon' => 'shield-check',     'head' => 'bg-sky-50 text-sky-700',         'ic' => 'text-sky-600',     'dot' => 'bg-sky-400'],
                    'team_task'           => ['label' => 'تقييم مهام الأسر',  'icon' => 'clipboard-document-check', 'head' => 'bg-violet-50 text-violet-700', 'ic' => 'text-violet-600', 'dot' => 'bg-violet-400'],
                    'adjustment'          => ['label' => 'التسويات',          'icon' => 'adjustments-horizontal', 'head' => 'bg-slate-100 text-slate-700', 'ic' => 'text-slate-600',  'dot' => 'bg-slate-400'],
                ];
                $chipDates = collect([\Carbon\Carbon::today()->toDateString()])->merge($availableNewsDates)->unique()->take(10)->values();
            @endphp

            <div class="flex items-center gap-2">
                <flux:icon icon="newspaper" class="size-6 text-slate-800" />
                <h3 class="font-black text-slate-900 text-lg">{{ __('أخبار المسابقة') }}</h3>
            </div>

            {{-- Day selector --}}
            <div class="flex gap-2 overflow-x-auto scrollbar-thin pb-1">
                @foreach($chipDates as $d)
                    @php $isToday = $d === \Carbon\Carbon::today()->toDateString(); $isSelected = $d === $newsDate; @endphp
                    <button type="button" wire:click="setNewsDate('{{ $d }}')"
                        class="shrink-0 px-3.5 py-1.5 rounded-xl text-xs font-bold border transition-colors {{ $isSelected ? 'bg-team-primary text-white border-team-primary' : 'bg-white text-slate-600 border-slate-200 hover:bg-slate-50' }}">
                        {{ $isToday ? __('اليوم') : $d }}
                    </button>
                @endforeach
            </div>

            @if(empty($dailyDigest))
                <div class="text-center py-12 bg-slate-50 border border-slate-200 rounded-2xl">
                    <flux:icon icon="newspaper" class="size-10 mx-auto text-slate-400 mb-3" />
                    <h4 class="font-bold text-slate-500 text-sm">{{ __('لا توجد أخبار في هذا اليوم') }}</h4>
                </div>
            @else
                @foreach($typeMeta as $type => $meta)
                    @if(!empty($dailyDigest[$type]) && count($dailyDigest[$type]) > 0)
                        <div class="bg-white border border-slate-200 rounded-2xl overflow-hidden shadow-sm">
                            <div class="flex items-center gap-2 px-4 py-2.5 border-b border-slate-100 {{ $meta['head'] }}">
                                <flux:icon icon="{{ $meta['icon'] }}" class="size-4 {{ $meta['ic'] }}" />
                                <span class="font-bold text-sm">{{ $meta['label'] }}</span>
                                <span class="text-xs text-slate-400">({{ count($dailyDigest[$type]) }})</span>
                            </div>
                            <div class="divide-y divide-slate-50">
                                @foreach($dailyDigest[$type] as $news)
                                    @php $d = $news['data'] ?? []; @endphp
                                    <div class="flex items-start gap-2.5 px-4 py-2.5 text-sm text-slate-700">
                                        <span class="mt-1.5 size-1.5 rounded-full {{ $meta['dot'] }} shrink-0"></span>
                                        <span>
                                            @switch($type)
                                                @case('level_up')
                                                    {{ __('ارتقى') }} <span class="font-bold">{{ $d['student_name'] ?? '' }}</span> {{ __('إلى') }} <span class="font-bold">{{ $d['level_name'] ?? '' }}</span>
                                                    @break
                                                @case('badge')
                                                    {{ __('حصل') }} <span class="font-bold">{{ $d['student_name'] ?? '' }}</span> {{ __('على وسام') }} <span class="font-bold">{{ $d['badge_name'] ?? '' }}</span>
                                                    @break
                                                @case('activity_win')
                                                    {{ __('فاز') }} <span class="font-bold">{{ $d['team_name'] ?? '' }}</span> {{ __('بـ') }} <span class="font-bold">{{ $d['rank_name'] ?? '' }}</span> {{ __('في') }} {{ $d['activity_name'] ?? '' }} — {{ $d['round_name'] ?? '' }}
                                                    @break
                                                @case('team_attack')
                                                    {{ __('تعرّضت') }} <span class="font-bold">{{ $d['target_team_name'] ?? '' }}</span> {{ __('لهجوم خصم نقاط') }} <span class="font-bold text-rose-600">(-{{ $d['amount'] ?? 0 }})</span>
                                                    @break
                                                @case('team_attack_blocked')
                                                    {{ __('صدّت') }} <span class="font-bold">{{ $d['target_team_name'] ?? '' }}</span> {{ __('هجوماً على نقاطها بفضل درع الحماية') }}
                                                    @break
                                                @case('team_task')
                                                    {{ __('تم تقييم مهمة') }} <span class="font-bold">{{ $d['task_name'] ?? '' }}</span> {{ __('لـ') }} <span class="font-bold">{{ $d['team_name'] ?? '' }}</span> {{ __('بدرجة') }} {{ $d['grade'] ?? 0 }}%
                                                    @break
                                                @case('adjustment')
                                                    @php
                                                        $isAdd = ($d['action'] ?? 'add') === 'add';
                                                        $adjName = $d['target_name'] ?? null;
                                                        if (! $adjName) {
                                                            $adjName = ($d['target_type'] ?? 'individual') === 'team' ? __('إحدى الأسر') : __('أحد الطلاب');
                                                        }
                                                    @endphp
                                                    <span class="font-bold {{ $isAdd ? 'text-emerald-600' : 'text-rose-600' }}">{{ $isAdd ? __('إضافة') : __('خصم') }}</span>
                                                    {{ __('لـ') }} <span class="font-bold">{{ $adjName }}</span>:
                                                    @if(($d['xp'] ?? 0) > 0) {{ $d['xp'] }} {{ __('نقطة') }} @endif
                                                    @if(($d['coins'] ?? 0) > 0) {{ ($d['xp'] ?? 0) > 0 ? '،' : '' }} {{ $d['coins'] }} {{ __('عملة') }} @endif
                                                    @if(!empty($d['description'])) <span class="text-slate-400">— {{ $d['description'] }}</span> @endif
                                                    @break
                                            @endswitch
                                        </span>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                @endforeach
            @endif
        </div>

        <!-- Team Content -->
        @if($studentTeam)
        <div x-show="currentTab === 'team'" x-cloak class="space-y-6">
            <!-- Team Standings: rank, today's points, today's enthusiasm-day count -->
            @if(!empty($teamStandings))
                <div class="bg-white border border-slate-200 rounded-3xl p-5 shadow-sm">
                    <div class="flex items-center gap-2 mb-4">
                        <flux:icon icon="trophy" class="size-5 text-amber-500" />
                        <h3 class="font-black text-slate-900">{{ __('مراكز') }} {{ $theme['team_plural'] }}</h3>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-right text-sm">
                            <thead>
                                <tr class="text-[11px] text-slate-400 font-bold border-b border-slate-100">
                                    <th class="p-2 text-center w-8">#</th>
                                    <th class="p-2">{{ $theme['team_name'] }}</th>
                                    <th class="p-2 text-center">{{ __('النقاط') }}</th>
                                    <th class="p-2 text-center whitespace-nowrap">{{ __('نقاط اليوم') }}</th>
                                    <th class="p-2 text-center whitespace-nowrap">{{ __('حماسة اليوم') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-50">
                                @foreach($teamStandings as $row)
                                    @php $isMyTeam = $studentTeam && $row['team']->id === $studentTeam->id; @endphp
                                    <tr @class(['rounded-xl', 'bg-team-10' => $isMyTeam])>
                                        <td class="p-2 text-center font-black {{ $row['rank'] <= 3 ? 'text-amber-500' : 'text-slate-400' }}">{{ $row['rank'] }}</td>
                                        <td class="p-2">
                                            <div class="flex items-center gap-2 min-w-0">
                                                <span class="size-2.5 rounded-full shrink-0" style="background-color: {{ $row['team']->color ?? '#4f46e5' }}"></span>
                                                <span class="font-bold text-slate-800 truncate">{{ $row['team']->name }}</span>
                                                @if($isMyTeam)<span class="text-[9px] font-bold px-1.5 py-0.5 rounded-full bg-team-10 text-team-primary shrink-0">{{ __('فريقك') }}</span>@endif
                                            </div>
                                        </td>
                                        <td class="p-2 text-center font-black text-slate-900">{{ number_format($row['score']) }}</td>
                                        <td class="p-2 text-center font-bold {{ $row['points_today'] > 0 ? 'text-emerald-600' : 'text-slate-300' }}">+{{ number_format($row['points_today']) }}</td>
                                        <td class="p-2 text-center font-bold {{ $row['enthusiasm_today'] > 0 ? 'text-orange-500' : 'text-slate-300' }}">🔥 {{ $row['enthusiasm_today'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif

            <!-- Team Info Header Card -->
            <div @class([
                'bg-white border border-slate-200 rounded-3xl p-6 shadow-sm relative overflow-hidden',
                'team-shield-aura' => $teamShieldActiveToday,
            ])>
                <!-- Decorative background colored strip -->
                <div class="absolute top-0 right-0 left-0 h-2" style="background-color: {{ $teamColor }}"></div>
                
                <div class="flex flex-col md:flex-row md:items-center justify-between gap-6">
                    <div class="flex items-center gap-4">
                        <div class="size-16 rounded-2xl flex items-center justify-center border shadow-inner bg-slate-50 overflow-hidden" style="border-color: {{ $teamColor }}">
                            @if($studentTeam->logo_path)
                                <img src="{{ asset('storage/' . $studentTeam->logo_path) }}" class="size-12 object-contain" />
                            @else
                                <span class="text-3xl">{!! $this->renderEmoji($style['team_emoji'], 'size-8 inline-block align-middle') !!}</span>
                            @endif
                        </div>
                        <div>
                            <h3 class="font-black text-xl text-slate-900">{{ $studentTeam->name }}</h3>
                            @if($studentTeam->slogan)
                                <p class="text-xs italic text-slate-500 mt-1">"{{ $studentTeam->slogan }}"</p>
                            @endif
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                        <div class="bg-slate-50 border border-slate-100 rounded-2xl p-4 text-center shadow-inner">
                            <span class="text-xs text-slate-400 block font-bold mb-1">نقاط المجموعة</span>
                            <span class="text-2xl font-black text-slate-900">{{ $teamScore }} XP</span>
                        </div>
                        <div class="bg-slate-50 border border-slate-100 rounded-2xl p-4 text-center shadow-inner">
                            <span class="text-xs text-slate-400 block font-bold mb-1">خزينة المجموعة</span>
                            <span class="text-2xl font-black text-amber-600 flex items-center justify-center gap-1">
                                {{ $studentTeam->coins }} {!! $this->renderEmoji($style['coin_emoji'], 'size-6 inline-block align-middle') !!}
                            </span>
                        </div>
                        {{-- Both tiles read today's answers from with(), the same ones the aura and an attack use. --}}
                        <div data-team-multiplier-tile data-active="{{ $teamMultiplierActiveToday ? '1' : '0' }}" class="bg-slate-50 border border-slate-100 rounded-2xl p-4 text-center shadow-inner">
                            <span class="text-xs text-slate-400 block font-bold mb-1">مضاعف النقاط</span>
                            <span class="text-sm font-bold block mt-1">
                                @if($teamMultiplierActiveToday)
                                    <span class="text-emerald-600 inline-flex items-center gap-1">نشط <flux:icon icon="bolt" variant="mini" class="size-4" /></span>
                                @else
                                    <span class="text-slate-400">غير نشط</span>
                                @endif
                            </span>
                        </div>
                        <div data-team-shield-tile data-active="{{ $teamShieldActiveToday ? '1' : '0' }}" class="bg-slate-50 border border-slate-100 rounded-2xl p-4 text-center shadow-inner">
                            <span class="text-xs text-slate-400 block font-bold mb-1">درع الحماية</span>
                            <span class="text-sm font-bold block mt-1">
                                @if($teamShieldActiveToday)
                                    <span class="text-emerald-600 inline-flex items-center gap-1">نشط <flux:icon icon="shield-check" variant="mini" class="size-4" /></span>
                                @else
                                    <span class="text-slate-400">غير نشط</span>
                                @endif
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Team points multiplier (leader only) inside team tab -->
                @if($showTeamMultiplier)
                    <div class="mt-6 border-t border-slate-100 pt-6">
                        <div class="flex items-center justify-between gap-3 flex-wrap">
                            <div class="flex items-center gap-2">
                                <svg class="size-5 text-yellow-500 shrink-0" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                                    <path fill-rule="evenodd" d="M14.615 1.595a.75.75 0 0 1 .359.852L12.982 9.75h7.268a.75.75 0 0 1 .548 1.262l-10.5 11.25a.75.75 0 0 1-1.272-.71l1.992-7.302H3.75a.75.75 0 0 1-.548-1.262l10.5-11.25a.75.75 0 0 1 .913-.143Z" clip-rule="evenodd" />
                                </svg>
                                <span class="text-sm text-slate-700 font-bold">مضاعفة جميع نقاط الفريق ليوم واحد</span>
                            </div>
                            <flux:button variant="primary" wire:click="openDoublePointsModal('{{ \Carbon\Carbon::now('Asia/Riyadh')->addDay()->format('Y-m-d') }}', 'team')" size="sm" class="bg-team-primary hover:bg-team-primary-hover border-none font-bold !text-white shadow-sm" style="color: white !important;" icon="bolt">
                                {{ __('مضاعفة نقاط الفريق') }}
                            </flux:button>
                        </div>
                    </div>
                @endif

                <!-- Donation inside team tab; the allowance is read in with() -->
                @php
                    $hasDonation = $donationStatus['has_donation'];
                    $donationAllowed = min($gamificationState->coins ?? 0, $donationStatus['remaining']);
                @endphp
                @if($hasDonation && $donationClosedReason)
                    {{-- Outside the competition's dates the treasury takes no donation; say so instead of offering a button that refuses. --}}
                    <div data-donation-closed class="mt-6 border-t border-slate-100 pt-6 flex items-center gap-2">
                        <flux:icon icon="lock-closed" class="size-5 text-slate-400 shrink-0" />
                        <span class="text-sm text-slate-500 font-semibold">{{ $donationClosedReason }}</span>
                    </div>
                @elseif($hasDonation)
                    <div x-data="{ amount: 10, open: false, loading: false }" @donation-successful.window="open = false" @donation-finished.window="loading = false" class="mt-6 border-t border-slate-100 pt-6">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                <svg class="size-5 text-amber-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                </svg>
                                <span class="text-sm text-slate-700 font-bold">دعم ال{{ $theme['team_name'] }} بالتبرع بالعملات</span>
                            </div>
                            <button @click="open = !open" class="text-sm text-team-primary font-bold hover:underline flex items-center gap-1 bg-team-10 px-3 py-1.5 rounded-xl transition-all">
                                <span>{{ __('تبرع الآن') }}</span>
                                <flux:icon icon="chevron-down" class="size-3.5 transition-transform" ::class="open ? 'rotate-180' : ''" />
                            </button>
                        </div>

                        <div x-show="open" x-collapse x-cloak class="mt-4 bg-slate-50 p-4 rounded-2xl border border-slate-200/60 max-w-md space-y-3">
                            <div class="flex gap-2">
                                <input x-model="amount" type="number" min="1" max="{{ $donationAllowed }}" class="flex-1 text-sm rounded-xl border border-slate-200 bg-white px-3 py-2 text-slate-800 focus:outline-none focus:ring-1 focus:ring-team-primary focus:border-team-primary" />
                                <button type="button" @click="$wire.donateToTeam(amount)" wire:loading.attr="disabled" wire:target="donateToTeam" class="text-sm px-4 py-2 rounded-xl font-bold transition-all duration-150 bg-team-primary hover:bg-team-primary-hover text-white disabled:opacity-50">
                                    <span wire:loading.remove wire:target="donateToTeam">{{ __('تبرع') }}</span>
                                    <span wire:loading wire:target="donateToTeam" style="display: none;">{{ __('جاري التبرع...') }}</span>
                                </button>
                            </div>
                            <div class="flex gap-2 justify-start">
                                <button @click="amount = 10" class="text-[11px] text-slate-600 hover:text-slate-800 hover:border-slate-350 px-3 py-1 rounded-lg border border-slate-200 bg-white">10</button>
                                <button @click="amount = 25" class="text-[11px] text-slate-600 hover:text-slate-800 hover:border-slate-350 px-3 py-1 rounded-lg border border-slate-200 bg-white">25</button>
                                <button @click="amount = 50" class="text-[11px] text-slate-600 hover:text-slate-800 hover:border-slate-350 px-3 py-1 rounded-lg border border-slate-200 bg-white">50</button>
                                <button @click="amount = {{ $donationAllowed }}" class="text-[11px] text-slate-600 hover:text-slate-800 hover:border-slate-350 px-3 py-1 rounded-lg border border-slate-200 bg-white">{{ __('التبرع بالحد المسموح') }}</button>
                            </div>
                            <p class="text-[11px] text-slate-400 flex items-center gap-1">
                                <span>رصيدك المتاح للتبرع: {{ $gamificationState->coins ?? 0 }}</span>
                                {!! $this->renderEmoji($style['coin_emoji'], 'size-3.5 inline-block align-middle') !!}
                            </p>
                            <p class="text-[11px] text-slate-400">
                                المتبقي لك اليوم للتبرع: {{ $donationStatus['remaining'] }} من {{ $donationStatus['limit'] }} عملة
                                <span class="text-slate-300">({{ $donationStatus['percentage'] }}% من رصيد بداية اليوم: {{ $donationStatus['base'] }})</span>
                            </p>
                        </div>
                    </div>
                @endif
            </div>

            <!-- Team purchased products inventory (collapsible, collapsed by default) -->
            @if($teamPurchases->isNotEmpty())
                @php
                    $usedPurchasesCount = $teamPurchases->filter(fn ($p) => $this->teamPurchaseUsage($p)['used'])->count();
                    $unusedPurchasesCount = $teamPurchases->count() - $usedPurchasesCount;
                @endphp
                <div x-data="{ open: false }" class="bg-white border border-slate-200 rounded-3xl shadow-sm overflow-hidden">
                    <button type="button" @click="open = !open"
                        class="w-full flex items-center justify-between gap-3 p-5 text-start hover:bg-slate-50 transition-colors">
                        <div class="flex items-center gap-3 min-w-0">
                            <div class="size-10 rounded-2xl bg-slate-100 flex items-center justify-center shrink-0">
                                <flux:icon icon="shopping-bag" class="size-5 text-slate-700" />
                            </div>
                            <div class="min-w-0">
                                <h4 class="font-black text-slate-900 text-base flex items-center gap-2">
                                    {{ __('مشتريات ومنتجات ال') }}{{ $theme['team_name'] }}
                                    <flux:badge size="sm" color="zinc">{{ $teamPurchases->count() }}</flux:badge>
                                </h4>
                                <p class="text-[11px] text-slate-400 mt-0.5">
                                    <span class="text-emerald-600 font-bold">{{ $usedPurchasesCount }} {{ __('مُستخدَمة') }}</span>
                                    <span class="text-slate-300">·</span>
                                    <span class="text-blue-600 font-bold">{{ $unusedPurchasesCount }} {{ __('لم تُستخدم بعد') }}</span>
                                </p>
                            </div>
                        </div>
                        <flux:icon icon="chevron-down" class="size-5 text-slate-400 transition-transform shrink-0" ::class="open ? 'rotate-180' : ''" />
                    </button>

                    <div x-show="open" x-collapse x-cloak class="border-t border-slate-100">
                        <div class="p-4 space-y-2">
                            @foreach($teamPurchases as $purchase)
                                @php
                                    $usage = $this->teamPurchaseUsage($purchase);
                                    $typeIcon = match($purchase->item->item_type) {
                                        'multiplier' => 'bolt',
                                        'shield' => 'shield-check',
                                        'team_points' => 'plus-circle',
                                        'team_attack' => 'arrow-trending-down',
                                        default => 'shopping-bag',
                                    };
                                @endphp
                                <div class="flex items-center gap-3 p-3 rounded-2xl border {{ $usage['used'] ? 'bg-slate-50/60 border-slate-200/70' : 'bg-blue-50/40 border-blue-200/60' }}">
                                    <div class="size-9 rounded-xl bg-white flex items-center justify-center shrink-0 border {{ $usage['used'] ? 'border-slate-200' : 'border-blue-200' }}">
                                        <flux:icon :icon="$typeIcon" class="size-4 {{ $usage['used'] ? 'text-slate-500' : 'text-blue-500' }}" />
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <p class="font-bold text-slate-800 text-sm truncate">{{ $purchase->item->name }}</p>
                                        <p class="text-[11px] text-slate-400 flex items-center gap-2 flex-wrap mt-0.5">
                                            @if($purchase->target_date)
                                                <span class="flex items-center gap-1">
                                                    <flux:icon icon="calendar" class="size-3 shrink-0" />
                                                    <x-hijri-date :date="\Carbon\Carbon::parse($purchase->target_date)" />
                                                </span>
                                                <span class="text-slate-300">·</span>
                                            @endif
                                            <span>{{ __('شُريَ') }} {{ $purchase->created_at->diffForHumans() }}</span>
                                        </p>
                                    </div>
                                    <flux:badge size="sm" :color="$usage['color']" :icon="$usage['icon']" class="shrink-0">
                                        {{ $usage['label'] }}
                                    </flux:badge>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            @endif

            <!-- Group purchase voting section -->
            @if($pendingTeamPurchases->isNotEmpty())
                <div class="space-y-4">
                    <h4 class="font-black text-slate-900 text-lg flex items-center gap-2">
                        <flux:icon icon="shopping-bag" class="size-5 text-slate-800" />
                        {{ __('التصويت على مشتريات') }} {{ $theme['team_possessive_your'] }}
                    </h4>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        @foreach($pendingTeamPurchases as $purchase)
                            @php
                                $hasVoted = array_key_exists($purchase->id, $studentVotes);
                                $myVote = $hasVoted ? $studentVotes[$purchase->id] : null;
                                
                                $votesMap = $purchase->votes->pluck('vote', 'student_id')->toArray();
                                $assistants = $teamStudents->filter(fn($s) => $s->pivot->role === 'assistant');
                                $members = $teamStudents->filter(fn($s) => $s->pivot->role === 'member');
                            @endphp
                            <div class="bg-white border border-slate-200 rounded-2xl p-5 flex flex-col justify-between gap-4 shadow-sm hover:shadow-md transition-shadow">
                                <div>
                                    <div class="flex items-center justify-between mb-2">
                                        <flux:badge color="amber">{{ __('بانتظار تصويت ال') }}{{ $theme['team_name'] }}</flux:badge>
                                        <span class="text-xs text-slate-400">{{ $purchase->created_at->diffForHumans() }}</span>
                                    </div>
                                    <h4 class="font-bold text-slate-800 text-base">
                                        {{ __('طلب شراء ميزة:') }} <span class="text-team-primary">{{ $purchase->item->name }}</span>
                                    </h4>
                                    <p class="text-xs text-slate-500 mt-1 leading-relaxed flex items-center gap-1">
                                        <span>{{ __('صاحب الطلب:') }} {{ $purchase->student?->name ?? __('طالب محذوف') }} — {{ __('السعر من خزينة ال') }}{{ $theme['team_name'] }}: {{ $purchase->price_paid }}</span>
                                        {!! $this->renderEmoji($style['coin_emoji'], 'size-3.5 inline-block align-middle') !!}
                                    </p>

                                    <div class="mt-4 pt-3 border-t border-slate-100 space-y-3.5 text-xs text-right" dir="rtl">
                                        <div>
                                            <span class="font-bold text-slate-700 block mb-2">{{ __('متطلبات الموافقة:') }}</span>
                                            <div class="space-y-1.5 mr-2">
                                                <!-- Assistant vote requirement if item requires it and team has assistants -->
                                                @if($purchase->item->require_assistant_approval && $assistants->isNotEmpty())
                                                    @php
                                                        $assistantHasApproved = $purchase->votes->whereIn('student_id', $assistants->pluck('id'))->where('vote', true)->isNotEmpty();
                                                    @endphp
                                                    <div class="flex items-center gap-2 text-slate-600">
                                                        @if($assistantHasApproved)
                                                            <flux:icon icon="check-circle" class="size-4 text-emerald-600 shrink-0" />
                                                        @else
                                                            <flux:icon icon="clock" class="size-4 text-slate-400 shrink-0" />
                                                        @endif
                                                        <span>
                                                            {{ __('موافقة مساعد القائد (النائب)') }}
                                                        </span>
                                                    </div>
                                                @endif

                                                <!-- Member approval requirement -->
                                                @php
                                                    $threshold = $purchase->item->require_member_approval_count;
                                                    if ($threshold <= 0 && !$purchase->item->require_assistant_approval) {
                                                        $threshold = intval($teamStudents->count() / 2) + 1;
                                                    }
                                                    $yesVotesCount = $purchase->votes->where('vote', true)->count();
                                                    $noVotesCount = $purchase->votes->where('vote', false)->count();
                                                    $memberApprovalMet = $yesVotesCount >= $threshold;
                                                @endphp
                                                @if($threshold > 0)
                                                    <div class="flex items-center gap-2 text-slate-600">
                                                        @if($memberApprovalMet)
                                                            <flux:icon icon="check-circle" class="size-4 text-emerald-600 shrink-0" />
                                                        @else
                                                            <flux:icon icon="clock" class="size-4 text-slate-400 shrink-0" />
                                                        @endif
                                                        <span>
                                                            {{ __('موافقة :threshold من أعضاء الفريق (الحالي: :yesCount موافق / :noCount رافض)', ['threshold' => $threshold, 'yesCount' => $yesVotesCount, 'noCount' => $noVotesCount]) }}
                                                        </span>
                                                    </div>
                                                @endif
                                            </div>
                                        </div>

                                        <div class="pt-3 border-t border-slate-100/60">
                                            <span class="font-bold text-slate-700 block mb-2">{{ __('الأعضاء الذين صوتوا:') }}</span>
                                            <div class="space-y-1.5 mr-2">
                                                @forelse($purchase->votes as $vote)
                                                    @php
                                                        // A deleted voter is listed as «طالب محذوف»; see the same list above.
                                                        $voter = $vote->student;
                                                        $voterTeamStudent = $voter ? $teamStudents->firstWhere('id', $voter->id) : null;
                                                        $voterRole = $voterTeamStudent ? $voterTeamStudent->pivot->role : 'member';
                                                        $roleLabel = $voterRole === 'leader' ? __('القائد') : ($voterRole === 'assistant' ? __('النائب') : __('عضو'));
                                                    @endphp
                                                    <div class="flex items-center gap-2 text-slate-600">
                                                        @if($vote->vote)
                                                            <flux:icon icon="check" class="size-4 text-emerald-500 shrink-0" />
                                                        @else
                                                            <flux:icon icon="x-mark" class="size-4 text-rose-500 shrink-0" />
                                                        @endif
                                                        {{-- shrink-0: a long name beside it squashed the circle into an oval. --}}
                                                        @if($voter?->avatar_path)
                                                            <img src="{{ Storage::url($voter->avatar_path) }}" class="w-6 h-6 shrink-0 rounded-full object-cover border border-slate-200" />
                                                        @else
                                                            <div class="w-6 h-6 shrink-0 rounded-full flex items-center justify-center font-bold text-[10px] border border-slate-200" style="{{ $voter?->avatarStyle() }}">
                                                                {{ $voter?->initials() ?? '؟' }}
                                                            </div>
                                                        @endif
                                                        <span class="min-w-0 font-medium">
                                                            {{ $voter?->name ?? __('طالب محذوف') }}
                                                            @if($voter)
                                                                <span class="text-[10px] text-slate-400">({{ $roleLabel }})</span>
                                                            @endif
                                                        </span>
                                                    </div>
                                                @empty
                                                    <div class="text-slate-400 italic text-[11px] py-1">
                                                        {{ __('لا توجد أصوات مسجلة بعد.') }}
                                                    </div>
                                                @endforelse
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="flex items-center gap-2">
                                    @if($hasVoted)
                                        <div class="w-full flex items-center justify-center gap-2 py-2 rounded-xl bg-slate-100 border border-slate-200 text-xs font-semibold text-slate-500">
                                            <span>{{ __('تم تصويتك بالفعل:') }} {{ $myVote ? __('موافق') : __('رافض') }}</span>
                                            @if($myVote)
                                                <flux:icon icon="check" class="size-4 text-emerald-500 shrink-0" />
                                            @else
                                                <flux:icon icon="x-mark" class="size-4 text-rose-500 shrink-0" />
                                            @endif
                                        </div>
                                    @else
                                        <flux:button wire:click="votePurchase({{ $purchase->id }}, true)" size="sm" color="emerald" class="flex-1 font-bold" icon="check">
                                            {{ __('موافق') }}
                                        </flux:button>
                                        <flux:button wire:click="votePurchase({{ $purchase->id }}, false)" size="sm" color="red" class="flex-1 font-bold" icon="x-mark">
                                            {{ __('رافض') }}
                                        </flux:button>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            <!-- Group Tasks Section -->
            <div class="space-y-4">
                <h4 class="font-black text-slate-900 text-lg flex items-center gap-2">
                    <svg class="size-5 text-slate-600 dark:text-slate-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"></path>
                    </svg>
                    {{ __('مهام ال') }}{{ $theme['team_name'] }}
                </h4>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    @forelse($teamTasks as $assignment)
                        <div class="bg-white border border-slate-200 rounded-2xl p-5 flex flex-col justify-between gap-4 shadow-sm hover:shadow-md transition-shadow relative overflow-hidden">
                            <!-- Left border accent with team color -->
                            <div class="absolute right-0 top-0 bottom-0 w-1.5" style="background-color: {{ $teamColor }}"></div>
                            
                            <div class="space-y-2.5 pr-2">
                                <div class="flex items-center justify-between">
                                    <h5 class="font-black text-slate-800 text-base flex items-center gap-1.5">
                                        <span>{{ $assignment->task->name }}</span>
                                    </h5>
                                    <div>
                                        @if($assignment->status === 'completed')
                                            <span class="inline-flex items-center gap-1 bg-emerald-50 text-emerald-700 px-2.5 py-1 rounded-lg text-xs font-bold">
                                                <svg class="size-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"></path>
                                                </svg>
                                                <span>{{ __('تم التقييم') }}</span>
                                            </span>
                                        @else
                                            <span class="inline-flex items-center gap-1 bg-amber-50 text-amber-700 px-2.5 py-1 rounded-lg text-xs font-bold">
                                                <svg class="size-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                                </svg>
                                                <span>{{ __('قيد التنفيذ') }}</span>
                                            </span>
                                        @endif
                                    </div>
                                </div>
                                
                                @if($assignment->task->description)
                                    <p class="text-xs text-slate-500 leading-relaxed">{{ $assignment->task->description }}</p>
                                @endif
                                
                                @if($assignment->status === 'completed' && $assignment->scores->isNotEmpty())
                                    <div class="bg-slate-50 p-3 rounded-xl border border-slate-100/60 text-xs text-slate-600 space-y-2">
                                        <span class="font-bold text-slate-700 block mb-1 flex items-center gap-1">
                                            <svg class="size-3.5 text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                            </svg>
                                            <span>تفاصيل التقييم بالبنود:</span>
                                        </span>
                                        <div class="space-y-1.5">
                                            @foreach($assignment->scores as $score)
                                                <div class="flex justify-between items-center text-[11px]">
                                                    <span class="text-slate-600 font-semibold">{{ $score->criterion->name }}:</span>
                                                    <span class="font-bold text-slate-800">{{ $score->score }} / 10</span>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                @elseif($assignment->task->criteria->isNotEmpty())
                                    <div class="bg-slate-50 p-3 rounded-xl border border-slate-100/60 text-xs text-slate-600 space-y-2">
                                        <span class="font-bold text-slate-700 block mb-1 flex items-center gap-1">
                                            <svg class="size-3.5 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2" />
                                            </svg>
                                            <span>معايير التقييم:</span>
                                        </span>
                                        <div class="space-y-1.5">
                                            @foreach($assignment->task->criteria as $criterion)
                                                <div class="flex justify-between items-start text-[11px] gap-2">
                                                    <div class="text-right">
                                                        <span class="font-semibold text-slate-700 block">{{ $criterion->name }}</span>
                                                        @if($criterion->description)
                                                            <span class="text-slate-400 text-[10px]">{{ $criterion->description }}</span>
                                                        @endif
                                                    </div>
                                                    <span class="text-purple-600 shrink-0 font-medium">+{{ $criterion->coins_reward }} {{ $theme['currency_name'] }}</span>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                @elseif($assignment->task->evaluation_criteria)
                                    <div class="bg-slate-50 p-3 rounded-xl border border-slate-100/60 text-xs text-slate-600 space-y-1">
                                        <span class="font-bold text-slate-700 block mb-1 flex items-center gap-1">
                                            <svg class="size-3.5 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                            </svg>
                                            <span>معايير التقييم:</span>
                                        </span>
                                        <p class="whitespace-pre-line leading-relaxed">{{ $assignment->task->evaluation_criteria }}</p>
                                    </div>
                                @endif
                                
                                <div class="flex flex-wrap gap-4 text-xs text-slate-400 font-medium pt-1">
                                    <span class="flex items-center gap-1" title="فترة التكليف">
                                        <svg class="size-3.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                                        </svg>
                                        <span><x-hijri-date :date="$assignment->start_date" /> {{ __('إلى') }} <x-hijri-date :date="$assignment->end_date" /></span>
                                    </span>
                                </div>
                            </div>
                            
                            <div class="border-t border-slate-100 pt-3 flex items-center justify-between pr-2 text-xs">
                                <div>
                                    @if($assignment->grade !== null)
                                        <span class="font-bold text-slate-700 flex items-center gap-1">
                                            <svg class="size-4 text-yellow-500 fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
                                                <path fill-rule="evenodd" d="M10.788 3.21c.448-1.077 1.976-1.077 2.424 0l2.082 5.006 5.404.434c1.164.093 1.636 1.545.749 2.305l-4.117 3.527 1.257 5.273c.271 1.136-.964 2.033-1.96 1.425L12 18.354 7.373 21.18c-.996.608-2.231-.29-1.96-1.425l1.257-5.273-4.117-3.527c-.887-.76-.415-2.212.749-2.305l5.404-.434 2.082-5.005Z" clip-rule="evenodd" />
                                            </svg>
                                            <span>الدرجة المحققة:</span>
                                            <strong class="text-indigo-650 text-sm font-black">{{ $assignment->grade }} / 100</strong>
                                        </span>
                                        @if($assignment->notes)
                                            <p class="text-[10px] text-slate-400 mt-1 italic">"{{ $assignment->notes }}"</p>
                                        @endif
                                    @else
                                        <span class="text-slate-400 italic">
                                            @if($assignment->teacher)
                                                بانتظار التقييم والدرجة من المعلم: {{ $assignment->teacher->name }}
                                            @else
                                                بانتظار التقييم والدرجة من المشرف
                                            @endif
                                        </span>
                                    @endif
                                </div>
                                <div class="text-left font-bold text-slate-700 shrink-0">
                                    @if($assignment->grade !== null)
                                        @php
                                            $tx = $teamTaskTransactions->get($assignment->id);
                                            $awardedCoins = $tx ? $tx->amount : (int) round(($assignment->grade / 100) * $assignment->task->coins_reward);
                                            $awardedXp = $tx ? $tx->xp_amount : (int) round(($assignment->grade / 100) * $assignment->task->xp_reward);
                                        @endphp
                                        <div class="flex flex-col items-end gap-0.5">
                                            @if($awardedXp > 0)
                                                <span class="text-indigo-600 flex items-center gap-1">
                                                    +{{ $awardedXp }} XP
                                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-3.5 text-indigo-500 fill-indigo-500/10">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904L9 21l-1.813-5.096L2.091 14.09 7.187 12.28 9 7.187l1.813 5.093 5.096 1.813-5.096 1.811z" />
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.071 4.929l-.929 2.071-2.071.929 2.071.929.929 2.071.929-2.071 2.071-.929-2.071-.929z" />
                                                    </svg>
                                                </span>
                                            @endif
                                            @if($awardedCoins > 0)
                                                <span class="text-amber-600 flex items-center gap-1">
                                                    +{{ $awardedCoins }} {{ $theme['currency_name'] }}
                                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-3.5 text-amber-500 fill-amber-500/10">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v12m-3-2.818.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                                                    </svg>
                                                </span>
                                            @endif
                                        </div>
                                    @else
                                        <div class="flex flex-col items-end gap-0.5 text-slate-400 font-medium text-[10px]">
                                            <span>{{ __('المكافأة القصوى:') }}</span>
                                            <span class="text-slate-600 font-bold">+{{ $assignment->task->xp_reward }} XP / +{{ $assignment->task->coins_reward }} {{ $theme['currency_name'] }}</span>
                                        </div>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @empty
                        <div class="col-span-full text-center py-12 text-slate-500 bg-slate-50 rounded-2xl border border-dashed border-slate-200">
                            {{ __('لا توجد مهام موكلة للمجموعة حالياً.') }}
                        </div>
                    @endforelse
                </div>
            </div>

            <!-- Activities and Events Section -->
            <div class="space-y-4">
                <h4 class="font-black text-slate-900 text-lg flex items-center gap-2">
                    <svg class="size-5 text-slate-650 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.907c.961 0 1.36 1.251.588 1.81l-3.97 2.883a1 1 0 00-.364 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.971-2.883a1 1 0 00-1.178 0l-3.97 2.883c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.364-1.118L2.49 10.117c-.773-.559-.374-1.81.588-1.81h4.908a1 1 0 00.95-.69l1.518-4.674z" />
                    </svg>
                    {{ __('لوحة شرف الفعاليات والأنشطة المشتركة') }}
                </h4>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    @forelse($allActivityRounds as $round)
                        @php
                            $activityColor = $round->activity->color ?? '#10b981';
                            
                            if (!function_exists('darkenColor')) {
                                function darkenColor($hex, $percent = 20) {
                                    $hex = ltrim($hex, '#');
                                    if (strlen($hex) == 3) {
                                        $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
                                    }
                                    $r = hexdec(substr($hex, 0, 2));
                                    $g = hexdec(substr($hex, 2, 2));
                                    $b = hexdec(substr($hex, 4, 2));

                                    $r = max(0, min(255, $r - ($r * $percent / 100)));
                                    $g = max(0, min(255, $g - ($g * $percent / 100)));
                                    $b = max(0, min(255, $b - ($b * $percent / 100)));

                                    return sprintf("#%02x%02x%02x", $r, $g, $b);
                                }
                            }
                            
                            $darkerColor = darkenColor($activityColor, 35);

                            if (!function_exists('isLightColor')) {
                                function isLightColor($hex) {
                                    $hex = ltrim($hex, '#');
                                    if (strlen($hex) == 3) {
                                        $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
                                    }
                                    $r = hexdec(substr($hex, 0, 2));
                                    $g = hexdec(substr($hex, 2, 2));
                                    $b = hexdec(substr($hex, 4, 2));
                                    
                                    $yiq = (($r * 299) + ($g * 587) + ($b * 114)) / 1000;
                                    return $yiq > 140;
                                }
                            }
                            
                            $dateColor = isLightColor($activityColor) ? $darkerColor : '#ffffff';

                            $hijriDateStr = \App\Support\HijriDate::format($round->round_date, 'EEEE، d MMMM');

                            /*
                             * Each winner's podium place. The rank's name says it when
                             * it is one of the defaults («المركز الأول» ...); a rank the
                             * supervisor renamed («ذهبي») or left blank takes its order
                             * among the activity's ranks instead. Read by name alone, a
                             * renamed rank's winner showed as «-» here while the
                             * supervisor's screen listed it. Teams tied on a place are
                             * all named: only the first used to show.
                             */
                            $rankOrder = $round->activity->ranks->sortBy('id')->pluck('id')->values();
                            $placeOf = function ($rank) use ($rankOrder): ?int {
                                $name = (string) $rank?->name;
                                if (str_contains($name, 'أول') || str_contains($name, 'اول')) {
                                    return 1;
                                }
                                if (str_contains($name, 'ثاني')) {
                                    return 2;
                                }
                                if (str_contains($name, 'ثالث')) {
                                    return 3;
                                }
                                $position = $rank ? $rankOrder->search($rank->id) : false;

                                return $position === false ? null : $position + 1;
                            };
                            $winnersByPlace = $round->winners->groupBy(fn ($w) => $placeOf($w->rank) ?? 0);
                            $teamNamesAt = fn (int $place): string => $winnersByPlace->get($place, collect())
                                ->map(fn ($w) => $w->team?->name)
                                ->filter()
                                ->unique()
                                ->implode(' / ') ?: '-';

                            $team1Name = $teamNamesAt(1);
                            $team2Name = $teamNamesAt(2);
                            $team3Name = $teamNamesAt(3);
                        @endphp
                        {{--
                            The card is a size container, so the podium row reads the
                            card's own width, not the screen's: a phone's single column
                            and a laptop's two columns are both too narrow to hold the
                            date and the podium side by side, which pushed the 2nd place
                            past the card's edge. There the podium stacks over the date.
                        --}}
                        <div class="@container relative w-full rounded-3xl p-6 flex flex-col justify-between gap-6 shadow-md hover:shadow-lg transition-all duration-300 hover:-translate-y-1 overflow-hidden font-zain border-none" style="background-color: {{ $activityColor }}; min-height: 220px;">
                            @if($round->activity->icon_path)
                                <img src="{{ Storage::url($round->activity->icon_path) }}" class="absolute top-5 right-5 w-18 h-18 object-contain rounded-xl shadow-inner shadow-gray-800/30" />
                            @endif

                            <div class="text-center w-full px-12">
                                <h5 class="text-[26px] font-black text-white drop-shadow-sm tracking-wide leading-tight">
                                    {{ $round->activity->name }}
                                </h5>
                                @if($round->activity->description)
                                    <p class="text-[14px] text-white/90 font-bold mt-1 leading-normal">
                                        {{ $round->activity->description }}
                                    </p>
                                @endif
                            </div>

                            <div class="flex flex-col-reverse items-center gap-4 @sm:flex-row @sm:items-end @sm:justify-between w-full mt-auto">
                                <div class="flex flex-col justify-end min-w-0 items-center text-center @sm:items-start @sm:text-right">
                                    <span class="text-[20px] font-bold mb-1.5 leading-none" style="color: {{ $dateColor }}">{{ $hijriDateStr }}</span>
                                    <div class="px-4 py-1.5 rounded-xl text-white font-black text-[18px] @min-[400px]:text-[20px] shadow-sm text-center leading-none" style="background-color: {{ $darkerColor }}">
                                        {{ $round->name }}
                                    </div>
                                </div>

                                <div class="flex items-end gap-1.5 pb-1 shrink-0">
                                    <div class="flex flex-col items-center">
                                        <span class="text-[16px] font-black mb-1.5 text-center truncate max-w-[65px] leading-none" style="color: {{ $darkerColor }}" title="{{ $team3Name }}" data-podium-place="3">{{ $team3Name }}</span>
                                        <div class="w-11 h-10 rounded-t-md bg-white/20 backdrop-blur-xs flex items-center justify-center shadow-xs">
                                            <span class="text-[24px] font-black" style="color: {{ $darkerColor }}">٣</span>
                                        </div>
                                    </div>
                                    <div class="flex flex-col items-center">
                                        <span class="text-[18px] font-black mb-1.5 text-center truncate max-w-[75px] leading-none text-white drop-shadow-sm" title="{{ $team1Name }}" data-podium-place="1">{{ $team1Name }}</span>
                                        <div class="w-13 h-18 rounded-t-md bg-white/40 backdrop-blur-xs flex items-center justify-center shadow-sm">
                                            <span class="text-[28px] font-black" style="color: {{ $darkerColor }}">١</span>
                                        </div>
                                    </div>
                                    <div class="flex flex-col items-center">
                                        <span class="text-[16px] font-black mb-1.5 text-center truncate max-w-[65px] leading-none" style="color: {{ $darkerColor }}" title="{{ $team2Name }}" data-podium-place="2">{{ $team2Name }}</span>
                                        <div class="w-11 h-14 rounded-t-md bg-white/30 backdrop-blur-xs flex items-center justify-center shadow-xs">
                                            <span class="text-[24px] font-black" style="color: {{ $darkerColor }}">٢</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                    @empty
                        <div class="col-span-full text-center py-12 text-slate-500 bg-slate-50 rounded-2xl border border-dashed border-slate-200">
                            {{ __('لا توجد فعاليات مجدولة أو نتائج ريادية حالياً.') }}
                        </div>
                    @endforelse
                </div>

            <!-- Team Members List -->
            <div class="space-y-4">
                <h4 class="font-black text-slate-900 text-lg flex items-center gap-2">
                    <svg class="size-5 text-slate-600 dark:text-slate-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a3 3 0 11-6 0 3 3 0 016 0z"></path>
                    </svg>
                    {{ __('أعضاء ال') }}{{ $theme['team_name'] }}
                </h4>
                <div class="bg-white border border-slate-200 rounded-3xl overflow-hidden shadow-sm">
                    <div class="overflow-x-auto">
                        <table class="w-full text-right">
                            <thead class="bg-slate-50/80 border-b border-slate-200">
                                <tr>
                                    <th class="p-4 font-bold text-slate-500">{{ __('اسم الطالب') }}</th>
                                    <th class="p-4 font-bold text-slate-500 text-center w-28">{{ __('الدور') }}</th>
                                    <th class="p-4 font-bold text-slate-500 text-center w-28">{{ __('العملات') }}</th>
                                    <th class="p-4 font-bold text-slate-500 text-center w-28">{{ __('النقاط / XP') }}</th>
                                    <th class="p-4 font-bold text-slate-500 text-center w-24">{{ __('الحماسة') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach($teamStudents as $ts)
                                    @php
                                        $isMe = $ts->id === $student->id;
                                        $tsState = $teamStudentStates->get($ts->id);
                                        $tsScore = collect($leaderboardStandings)->firstWhere('student.id', $ts->id)['score'] ?? 0;
                                    @endphp
                                    <tr class="transition-colors duration-150 {{ $isMe ? 'bg-team-10 text-slate-900 font-bold' : 'text-slate-700 hover:bg-slate-50/50' }}">
                                        <td class="p-4">
                                            {{-- shrink-0: a long name beside it squashed the circle into an oval. --}}
                                            <div class="flex items-center gap-3">
                                                @if($ts->avatar_path)
                                                    <img src="{{ Storage::url($ts->avatar_path) }}" class="w-9 h-9 shrink-0 rounded-full object-cover border border-slate-200" />
                                                @else
                                                    <div class="w-9 h-9 shrink-0 rounded-full flex items-center justify-center font-bold text-sm border border-slate-200" style="{{ $ts->avatarStyle() }}">
                                                        {{ $ts->initials() }}
                                                    </div>
                                                @endif
                                                <div class="flex flex-col min-w-0">
                                                    <span>{{ $ts->name }}</span>
                                                    @if($isMe)
                                                        <span class="text-[9px] text-team-primary font-bold uppercase tracking-wider">{{ __('أنت') }}</span>
                                                    @endif
                                                </div>
                                            </div>
                                        </td>
                                        <td class="p-4 text-center">
                                            @php
                                                $role = $ts->pivot->role;
                                                $badgeColor = $role === 'leader' ? 'indigo' : ($role === 'assistant' ? 'amber' : 'zinc');
                                                $roleLabel = $role === 'leader' ? __('قائد') : ($role === 'assistant' ? __('مساعد') : __('عضو'));
                                            @endphp
                                            <flux:badge color="{{ $badgeColor }}" size="sm">{{ $roleLabel }}</flux:badge>
                                        </td>
                                        <td class="p-4 text-center font-bold text-slate-800">
                                            {{ $tsState->coins ?? 0 }} {!! $this->renderEmoji($style['coin_emoji'], 'size-4 inline-block align-middle') !!}
                                        </td>
                                        <td class="p-4 text-center font-bold text-slate-800">
                                            {{ $tsScore }} XP
                                        </td>
                                        <td class="p-4 text-center">
                                            <span class="font-bold text-amber-600 flex items-center justify-center gap-1">
                                                {{ $style['streak_emoji'] }} {{ $teamStudentStreaks[$ts->id] ?? 0 }}
                                            </span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        </div> <!-- Closes currentTab === team div -->
        @endif

        <!-- Extra bottom spacing to prevent bottom navbar overlay -->
        <div class="h-28"></div>
    </div>

    <!-- Double Points Purchase Modal -->
    <flux:modal class="md:max-w-md" wire:model="showDoublePointsModal">
        <div class="space-y-6 text-right" dir="rtl">
            <div>
                <flux:heading size="lg">
                    {{ $doublePointsType === 'team' ? 'شراء مضاعف النقاط للفريق ⚡' : 'شراء مضاعف النقاط الفردي ⚡' }}
                </flux:heading>
                <flux:subheading>
                    {{ $doublePointsType === 'team' ? 'ضاعف جميع نقاط الخبرة التي يحصّلها فريقك من كل المصادر (الحفظ، المراجعة، الحضور، المهام، الفعاليات، والتسويات اليدوية) في اليوم المختار.' : 'ضاعف جميع نقاط الحفظ والمراجعة والحضور الخاصة بك في اليوم المختار.' }}
                </flux:subheading>
            </div>

            <flux:field>
                <flux:label>تاريخ التفعيل (التاريخ الهجري)</flux:label>
                <livewire:shared.hijri-datepicker wire:model="doublePointsDate" :show-attendance-days="true" label="" />
            </flux:field>

            <div class="bg-slate-50 p-4 rounded-xl border border-slate-100 flex items-center justify-between">
                <span class="text-sm font-bold text-slate-600">
                    {{ $doublePointsType === 'team' ? 'تكلفة الشراء (خزينة الفريق):' : 'تكلفة الشراء (رصيدك الفردي):' }}
                </span>
                <span class="font-black text-amber-600">{{ $doublePointsPrice }} {!! $this->renderEmoji($style['coin_emoji'] ?? '🪙', 'size-5 inline-block align-middle') !!}</span>
            </div>

            <div class="flex gap-2">
                <flux:button wire:click="purchaseDoublePoints" variant="primary" class="flex-1 bg-team-primary hover:bg-team-primary-hover border-none font-bold !text-white" style="color: white !important;">
                    تأكيد الشراء ويتمم ⚡
                </flux:button>
                <flux:modal.close>
                    <flux:button variant="ghost">
                        إلغاء
                    </flux:button>
                </flux:modal.close>
            </div>
        </div>
    </flux:modal>

    <!-- Streak Freeze Purchase Modal -->
    <flux:modal class="md:max-w-md" wire:model="showFreezeModal">
        <div class="space-y-6 text-right" dir="rtl">
            <div class="flex items-center gap-3">
                <div class="p-3 bg-blue-50 text-blue-600 rounded-2xl">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="size-6 text-blue-600">
                        <line x1="12" y1="2" x2="12" y2="22"></line>
                        <line x1="2" y1="12" x2="22" y2="12"></line>
                        <line x1="4.93" y1="4.93" x2="19.07" y2="19.07"></line>
                        <line x1="19.07" y1="4.93" x2="4.93" y2="19.07"></line>
                        <path d="m20 8-2 2-2-2"></path>
                        <path d="m4 16 2-2 2 2"></path>
                        <path d="m8 4 2 2-2 2"></path>
                        <path d="m16 20-2-2 2-2"></path>
                        <path d="m8 20 2-2-2-2"></path>
                        <path d="m16 4-2 2 2 2"></path>
                        <path d="m4 8 2 2-2 2"></path>
                        <path d="m20 16-2-2 2 2"></path>
                    </svg>
                </div>
                <div>
                    <flux:heading size="lg">
                        شراء وتفعيل تجميد الحماسة ❄️
                    </flux:heading>
                    {{-- Filled from the state openFreezeModal() sets, which draws nothing. --}}
                    <flux:subheading>
                        حماية شعلة حماستك ليوم <span data-freeze-day x-text="$wire.freezeDayName">{{ $freezeDayName }}</span> (<span data-freeze-hijri x-text="$wire.freezeHijriDate">{{ $freezeHijriDate }}</span>).
                    </flux:subheading>
                </div>
            </div>

            <div class="bg-blue-50/50 p-4 rounded-xl border border-blue-100/60 text-xs text-blue-800 space-y-2 leading-relaxed">
                <div class="flex items-center gap-1.5 font-bold">
                    <flux:icon icon="information-circle" class="size-4 text-blue-600" />
                    <span>معلومات أيام التجميد لمستواك:</span>
                </div>
                @php
                    $maxPrevDays = $maxFreezeDays;
                    $freezeRules = $activeGamification->settings['streak_freeze_levels'] ?? [];
                    usort($freezeRules, fn ($a, $b) => ($a['level'] ?? 0) <=> ($b['level'] ?? 0));
                @endphp
                <p>
                    أنت الآن في المستوى <span class="font-bold text-blue-900">{{ $studentLevel }}</span>، ويُسمح لك بتجميد اليوم الحالي وحتى <span class="font-bold text-blue-900">{{ $maxPrevDays }}</span> من الأيام السابقة.
                </p>
                @if($nextFreezeUpgrade)
                    <div class="mt-2 pt-2 border-t border-blue-200/50 text-[11px] text-indigo-700 font-semibold flex items-center gap-1">
                        <flux:icon icon="sparkles" class="size-3.5 text-indigo-500 animate-pulse" />
                        <span>ترقية قادمة: عند وصولك للمستوى {{ $nextFreezeUpgrade['level'] }}، ستتمكن من تجميد {{ $nextFreezeUpgrade['days'] }} من الأيام السابقة!</span>
                    </div>
                @endif
                @if(!empty($freezeRules))
                    <div class="mt-2 pt-2 border-t border-blue-200/30 space-y-1">
                        <span class="text-[10px] text-slate-500 block font-bold">خطة ترقيات التجميد المتاحة:</span>
                        <div class="flex flex-wrap gap-1.5">
                            @foreach($freezeRules as $rule)
                                <span class="px-2 py-0.5 bg-blue-100/80 rounded text-[10px] font-semibold text-blue-950 border border-blue-200/30">
                                    المستوى {{ $rule['level'] }}: تجميد {{ $rule['days'] }} أيام سابقة
                                </span>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>

            <div class="bg-slate-50 p-4 rounded-xl border border-slate-100 flex items-center justify-between">
                <span class="text-sm font-bold text-slate-600">
                    تكلفة الشراء (رصيدك الفردي):
                </span>
                <span class="font-black text-amber-600"><span data-freeze-price x-text="$wire.freezePrice">{{ $freezePrice }}</span> {!! $this->renderEmoji($style['coin_emoji'] ?? '🪙', 'size-5 inline-block align-middle') !!}</span>
            </div>

            <div class="flex gap-2">
                <flux:button wire:click="purchaseFreeze" variant="primary" class="flex-1 bg-gradient-to-r from-blue-500 to-cyan-500 hover:from-blue-600 hover:to-cyan-600 border-none font-bold !text-white" style="color: white !important;">
                    شراء وتجميد اليوم ❄️
                </flux:button>
                <flux:modal.close>
                    <flux:button variant="ghost">
                        إلغاء
                    </flux:button>
                </flux:modal.close>
            </div>
        </div>
    </flux:modal>
</div>
@endif

@script
<script>
    // Fly XP particles from the claim button to the level meter in the top bar.
    window.gamLaunchXp = function (fromEl, count = 10) {
        const target = document.getElementById('gam-level-meter');
        if (! fromEl || ! target) return;
        const f = fromEl.getBoundingClientRect();
        const t = target.getBoundingClientRect();
        const sx = f.left + f.width / 2, sy = f.top + f.height / 2;
        const ex = t.left + t.width / 2, ey = t.top + t.height / 2;

        for (let i = 0; i < count; i++) {
            const p = document.createElement('div');
            p.className = 'gam-xp-particle';
            p.style.left = sx + 'px';
            p.style.top = sy + 'px';
            document.body.appendChild(p);

            const dx = ex - sx, dy = ey - sy;
            const mx = dx * 0.5 + (Math.random() - 0.5) * 140;
            const my = dy * 0.5 - (70 + Math.random() * 90);

            const anim = p.animate([
                { transform: 'translate(0,0) scale(' + (0.5 + Math.random() * 0.7) + ')', opacity: 1, offset: 0 },
                { transform: 'translate(' + mx + 'px,' + my + 'px) scale(1)', opacity: 1, offset: 0.6 },
                { transform: 'translate(' + dx + 'px,' + dy + 'px) scale(0.25)', opacity: 0.15, offset: 1 },
            ], { duration: 750 + Math.random() * 250, delay: i * 45, easing: 'cubic-bezier(.4,0,.2,1)', fill: 'forwards' });
            anim.onfinish = function () { p.remove(); };
        }

        // Flash the meter roughly when the particles land.
        setTimeout(window.gamPulseMeter, count * 45 + 780);
    };

    window.gamPulseMeter = function () {
        const fill = document.getElementById('gam-level-fill');
        if (! fill) return;
        fill.classList.remove('gam-flash');
        void fill.offsetWidth;
        fill.classList.add('gam-flash');
        setTimeout(function () { fill.classList.remove('gam-flash'); }, 650);
    };

    window.gamPulseValue = function (id) {
        const el = document.getElementById(id);
        if (! el) return;
        el.classList.remove('gam-pop');
        void el.offsetWidth;
        el.classList.add('gam-pop');
        setTimeout(function () { el.classList.remove('gam-pop'); }, 550);
    };

    // After the server confirms the claim (XP/level/rank already re-rendered), the
    // meter fill width transitions to its new value; pop the numbers to match.
    $wire.on('reward-claimed', function () {
        requestAnimationFrame(function () {
            window.gamPulseMeter();
            window.gamPulseValue('gam-xp-value');
            window.gamPulseValue('gam-level-value');
            window.gamPulseValue('gam-rank-value');
        });
    });
</script>
@endscript
</div>
