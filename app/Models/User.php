<?php

namespace App\Models;

use App\Models\Concerns\HasProfile;
use App\Support\DisciplineWindow;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;

/**
 * The single wide table backing all 5 role guards (manager/supervisor/teacher/
 * student/guardian). A user's role(s) are tracked in `user_roles`, not
 * here — a person can hold multiple roles (e.g. approved teacher + pending
 * supervisor) simultaneously. The Manager/Supervisor/Teacher/Student/Guardian
 * subclasses are thin: they add a global scope restricting to their one
 * role via `App\Models\Concerns\BelongsToRole`. All relationships below are
 * defined here on the base class rather than duplicated per subclass, even
 * though some (e.g. memorization stats) semantically only apply to students.
 */
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasProfile, Notifiable, TwoFactorAuthenticatable;

    /**
     * Explicit — without it, Eloquent would guess each subclass's table name
     * from its own class name (managers/supervisors/...) instead of
     * inheriting this one shared table.
     */
    protected $table = 'users';

    protected static function booted(): void
    {
        // When a student is deleted, release any form responses linked to them so
        // they return to the unprocessed pool (and can be re-created or re-linked).
        static::deleting(function (User $user) {
            FormResponse::where('student_id', $user->id)
                ->update(['student_id' => null, 'is_processed' => false]);
        });
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'access_token',
        'permissions',
        'circle_id',
        'guardian_id',
        'stage_id',
        'national_id',
        'nationality',
        'birth_date',
        'joined_at',
        'status',
        'avatar_path',
        // Not real columns — relayed to the per-role `user_roles` row by
        // App\Models\Concerns\BelongsToRole's accessors/mutators. Must stay
        // fillable so create()/fill() actually invoke those mutators.
        'is_approved',
        'approved_by',
        'is_rejected',
        'rejected_at',
        'rejected_by',
        'is_data_completed',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'permissions' => 'array',
            'birth_date' => 'date',
            'joined_at' => 'date',
        ];
    }

    /** @return HasMany<UserRole, $this> */
    public function roles(): HasMany
    {
        return $this->hasMany(UserRole::class, 'user_id');
    }

    public function hasRole(string $role): bool
    {
        return $this->roles->contains('role', $role);
    }

    public function roleState(string $role): ?UserRole
    {
        return $this->roles->firstWhere('role', $role);
    }

    /** @return BelongsToMany<Circle, $this> */
    public function circles(): BelongsToMany
    {
        return $this->belongsToMany(Circle::class, 'circle_teacher', 'teacher_id', 'circle_id');
    }

    /** @return HasMany<Attendance, $this> */
    public function attendancesAsTeacher(): HasMany
    {
        return $this->hasMany(Attendance::class, 'teacher_id');
    }

    /** @return HasMany<StudentPlan, $this> */
    public function plansAsTeacher(): HasMany
    {
        return $this->hasMany(StudentPlan::class, 'teacher_id');
    }

    /** @return BelongsToMany<Stage, $this> */
    public function stages(): BelongsToMany
    {
        return $this->belongsToMany(Stage::class, 'stage_supervisor', 'supervisor_id', 'stage_id');
    }

    /** @return BelongsTo<Circle, $this> */
    public function circle(): BelongsTo
    {
        return $this->belongsTo(Circle::class);
    }

    /** @return BelongsTo<Stage, $this> */
    public function stage(): BelongsTo
    {
        return $this->belongsTo(Stage::class);
    }

    /**
     * The student's effective stage id. The circle's stage always wins when the
     * student is assigned to a circle; the direct stage_id only applies to
     * circle-less (still registering) students.
     */
    public function getEffectiveStageIdAttribute(): ?int
    {
        return $this->circle?->stage_id ?? $this->stage_id;
    }

    /**
     * Whether this student's stage memorises the mutun — the hadith texts.
     *
     * A stage switches it off from the supervisor's settings, and then it is
     * gone from the teacher's tasmeeh and the student's own pages alike. A
     * student with no stage keeps it: there is no one to have turned it off.
     */
    public function memorisesHadith(): bool
    {
        return $this->effectiveStage()?->hadith_enabled ?? true;
    }

    /**
     * The teacher's circles whose stage memorises the odes. A circle outside
     * any stage keeps them, as a student with no stage does.
     *
     * @return BelongsToMany<Circle, $this>
     */
    public function odeCircles(): BelongsToMany
    {
        return $this->circles()->where(fn ($query) => $query
            ->whereNull('circles.stage_id')
            ->orWhereHas('stage', fn ($stage) => $stage->where('odes_enabled', true)));
    }

    /**
     * Whether any of the teacher's circles memorises the odes. Their odes pages
     * go once every stage they teach in has switched them off.
     */
    public function teachesOdes(): bool
    {
        return $this->odeCircles()->exists();
    }

    /** Whether this student's stage memorises the odes. */
    public function memorisesOdes(): bool
    {
        return $this->effectiveStage()?->odes_enabled ?? true;
    }

    /**
     * The stage itself, by the same precedence as effective_stage_id: the
     * circle's stage first, the student's own only when there is no circle.
     */
    private function effectiveStage(): ?Stage
    {
        if ($this->circle_id) {
            return $this->circle?->stage;
        }

        return $this->stage_id ? $this->stage : null;
    }

    /** @return BelongsTo<Guardian, $this> */
    public function guardian(): BelongsTo
    {
        return $this->belongsTo(Guardian::class, 'guardian_id');
    }

    /** @return HasMany<Student, $this> */
    public function students(): HasMany
    {
        return $this->hasMany(Student::class, 'guardian_id');
    }

    /** @return HasMany<Attendance, $this> */
    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class, 'student_id');
    }

    /** @return HasMany<StudentPlan, $this> */
    public function plans(): HasMany
    {
        return $this->hasMany(StudentPlan::class, 'student_id');
    }

    /** @return HasMany<StudentOdePlan, $this> */
    public function odePlans(): HasMany
    {
        return $this->hasMany(StudentOdePlan::class, 'student_id');
    }

    /** @return HasMany<FormResponse, $this> */
    public function formResponses(): HasMany
    {
        return $this->hasMany(FormResponse::class, 'student_id');
    }

    /** @return HasMany<StudentStatusHistory, $this> */
    public function statusHistories(): HasMany
    {
        return $this->hasMany(StudentStatusHistory::class, 'student_id')->orderBy('start_date', 'desc');
    }

    /** @return BelongsToMany<GamificationTeam, $this> */
    public function gamificationTeams(): BelongsToMany
    {
        return $this->belongsToMany(GamificationTeam::class, 'gamification_team_student', 'student_id', 'team_id')
            ->withPivot('role');
    }

    /** @return BelongsToMany<GamificationTrack, $this> */
    public function gamificationTracks(): BelongsToMany
    {
        return $this->belongsToMany(GamificationTrack::class, 'gamification_track_student', 'student_id', 'track_id');
    }

    /** @return HasMany<GamificationTransaction, $this> */
    public function gamificationTransactions(): HasMany
    {
        return $this->hasMany(GamificationTransaction::class, 'student_id');
    }

    /** @return BelongsToMany<GamificationBadge, $this> */
    public function gamificationBadges(): BelongsToMany
    {
        return $this->belongsToMany(GamificationBadge::class, 'gamification_badge_student', 'student_id', 'badge_id');
    }

    /** @return HasMany<GamificationStudentState, $this> */
    public function gamificationStates(): HasMany
    {
        return $this->hasMany(GamificationStudentState::class, 'student_id');
    }

    /**
     * The canonical set of teacher permission keys with their default (enabled) state.
     * New keys added here are enabled by default, even for teachers whose stored
     * override predates the key, because effectivePermissions() merges over these.
     *
     * @return array<string, bool>
     */
    public static function defaultPermissions(): array
    {
        return [
            'can_manage_students' => true,
            'can_change_student_status' => true,
            'can_create_students' => true,
            'can_manage_hadith_paths' => true,
            'can_manage_ode_paths' => true,
            'can_manage_gamification_tracks' => true,
        ];
    }

    /**
     * Returns the effective permissions for this teacher.
     *
     * Layered so that any key missing from an older stored override falls back to
     * the global default, and any key missing from both falls back to the canonical
     * default. This keeps newly-introduced permissions "enabled by default" without
     * having to backfill every teacher row.
     *
     * @return array<string, bool>
     */
    public function effectivePermissions(): array
    {
        $defaults = self::defaultPermissions();

        $global = Setting::getVal('default_teacher_permissions');
        if (is_string($global)) {
            $global = json_decode($global, true);
        }
        $base = is_array($global) ? array_merge($defaults, $global) : $defaults;

        if ($this->permissions !== null) {
            return array_merge($base, $this->permissions);
        }

        return $base;
    }

    /**
     * Whether this teacher has a custom permission override (vs inheriting global defaults).
     */
    public function hasOverriddenPermissions(): bool
    {
        return $this->permissions !== null;
    }

    public function getAbsencesInPeriodCount($date = null): int
    {
        return $this->countInDisciplineWindow('absent', $date);
    }

    public function getLatenessInPeriodCount($date = null): int
    {
        return $this->countInDisciplineWindow('late', $date);
    }

    /**
     * Records of one status in the window the limits are counted over, up to
     * the given day (today by default).
     */
    private function countInDisciplineWindow(string $status, $date): int
    {
        $window = DisciplineWindow::for($date);

        return $this->attendances()
            ->where('status', $status)
            ->whereDate('date', '>=', $window['from'])
            ->whereDate('date', '<=', $window['to'])
            ->count();
    }

    public function hasExceededAbsenceLimit($date = null): bool
    {
        $limit = (int) Setting::getVal('absence_limit', 3);

        return $this->getAbsencesInPeriodCount($date) >= $limit;
    }

    public function hasExceededLatenessLimit($date = null): bool
    {
        $limit = (int) Setting::getVal('lateness_limit', 5);

        return $this->getLatenessInPeriodCount($date) >= $limit;
    }

    public function getAbsencesInLast30DaysCount($date = null): int
    {
        return $this->getAbsencesInPeriodCount($date);
    }

    public function getGuardianPhoneAttribute()
    {
        return $this->guardian?->phone;
    }

    /**
     * Consecutive days of present/late attendance, counted backward from the most
     * recent activity day. The streak is considered broken (0) if the student has
     * no attendance record today or yesterday (yesterday allowed so a streak isn't
     * lost purely because today's session hasn't happened yet).
     */
    public function currentAttendanceStreakDays(): int
    {
        $dateSet = $this->attendances()
            ->whereIn('status', ['present', 'late'])
            ->pluck('date')
            ->map(fn ($date) => Carbon::parse($date)->toDateString())
            ->unique()
            ->flip();

        if ($dateSet->isEmpty()) {
            return 0;
        }

        $today = now()->startOfDay();

        if (isset($dateSet[$today->toDateString()])) {
            $cursor = $today->copy();
        } elseif (isset($dateSet[$today->copy()->subDay()->toDateString()])) {
            $cursor = $today->copy()->subDay();
        } else {
            return 0;
        }

        $streak = 0;
        while (isset($dateSet[$cursor->toDateString()])) {
            $streak++;
            $cursor = $cursor->subDay();
        }

        return $streak;
    }

    public function getMemorizedRange(): ?array
    {
        $latestPlan = StudentPlan::where('student_id', $this->id)
            ->where('is_approved', true)
            ->latest('start_date')
            ->first();

        if (! $latestPlan) {
            return null;
        }

        $direction = $latestPlan->direction;

        $days = StudentPlanDay::whereHas('plan', fn ($q) => $q->where('student_id', $this->id)->where('is_approved', true))
            ->where(function ($q) {
                $q->where(function ($sq) {
                    $sq->where('hifz_achievement', '>=', 2)
                        ->whereNotNull('from_ayah_id')
                        ->whereNotNull('to_ayah_id');
                })->orWhere(function ($sq) {
                    $sq->where('review_achievement', '>=', 2)
                        ->whereNotNull('review_from_ayah_id')
                        ->whereNotNull('review_to_ayah_id');
                });
            })
            ->get();

        if ($days->isEmpty()) {
            return null;
        }

        $furthestAyah = $direction === 'reverse' ? 6236 : 1;

        foreach ($days as $day) {
            if ($day->hifz_achievement >= 2 && $day->from_ayah_id && $day->to_ayah_id) {
                if ($direction === 'reverse') {
                    $furthestAyah = min($furthestAyah, $day->from_ayah_id, $day->to_ayah_id);
                } else {
                    $furthestAyah = max($furthestAyah, $day->from_ayah_id, $day->to_ayah_id);
                }
            }
            if ($day->review_achievement >= 2 && $day->review_from_ayah_id && $day->review_to_ayah_id) {
                if ($direction === 'reverse') {
                    $furthestAyah = min($furthestAyah, $day->review_from_ayah_id, $day->review_to_ayah_id);
                } else {
                    $furthestAyah = max($furthestAyah, $day->review_from_ayah_id, $day->review_to_ayah_id);
                }
            }
        }

        if ($direction === 'reverse') {
            return ['min' => $furthestAyah, 'max' => 6236];
        }

        return ['min' => 1, 'max' => $furthestAyah];
    }

    public function memorizedPagesCount(): int
    {
        $range = $this->getMemorizedRange();
        if (! $range) {
            return 0;
        }

        if ($range['max'] === 6236 && $range['min'] !== 1) {
            $page = Ayah::find($range['min'])?->page_number ?? 604;

            return 604 - $page + 1;
        }

        $page = Ayah::find($range['max'])?->page_number ?? 1;

        return $page;
    }

    public function memorizationPercentage(): float
    {
        return round($this->memorizedPagesCount() / 604 * 100, 1);
    }

    public function memorizationText(): string
    {
        $range = $this->getMemorizedRange();
        if (! $range) {
            return 'لا يوجد سجل محفوظ';
        }

        if ($range['min'] === 1 && $range['max'] === 6236) {
            return 'القرآن كاملاً';
        }

        $startAyah = Ayah::with('surah')->find($range['min']);
        $endAyah = Ayah::with('surah')->find($range['max']);

        if (! $startAyah || ! $endAyah) {
            return 'لا يوجد سجل محفوظ';
        }

        if ($startAyah->surah_id === $endAyah->surah_id) {
            return 'سورة '.$startAyah->surah->name_arabic;
        }

        return 'من سورة '.$startAyah->surah->name_arabic.' إلى '.$endAyah->surah->name_arabic;
    }
}
