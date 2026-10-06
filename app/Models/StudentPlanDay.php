<?php

namespace App\Models;

use App\Services\GamificationService;
use App\Support\RecitationGrade;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StudentPlanDay extends Model
{
    protected $fillable = [
        'student_plan_id',
        'date',
        'day_name',
        'from_ayah_id',
        'to_ayah_id',
        'review_from_ayah_id',
        'review_to_ayah_id',
        'hifz_achievement',
        'review_achievement',
        'hifz_graded_at',
        'review_graded_at',
        'hifz_recited_from_ayah_id',
        'hifz_recited_to_ayah_id',
        'hifz_recorded_by',
        'review_recited_from_ayah_id',
        'review_recited_to_ayah_id',
        'review_recorded_by',
    ];

    protected static function booted(): void
    {
        // Drop any gamification points tied to this day when it is deleted, so no
        // orphaned transactions inflate student/team scores.
        static::deleting(function (StudentPlanDay $day) {
            GamificationService::clearTransactionsForReference(self::class, $day->id);
        });
    }

    protected $casts = [
        'date' => 'date',
        'hifz_graded_at' => 'datetime',
        'review_graded_at' => 'datetime',
        'hifz_achievement' => 'integer',
        'review_achievement' => 'integer',
    ];

    public function plan()
    {
        return $this->belongsTo(StudentPlan::class, 'student_plan_id');
    }

    public function fromAyah()
    {
        return $this->belongsTo(Ayah::class, 'from_ayah_id');
    }

    public function toAyah()
    {
        return $this->belongsTo(Ayah::class, 'to_ayah_id');
    }

    public function reviewFromAyah()
    {
        return $this->belongsTo(Ayah::class, 'review_from_ayah_id');
    }

    public function reviewToAyah()
    {
        return $this->belongsTo(Ayah::class, 'review_to_ayah_id');
    }

    /** @return BelongsTo<Ayah, $this> */
    public function hifzRecitedFromAyah(): BelongsTo
    {
        return $this->belongsTo(Ayah::class, 'hifz_recited_from_ayah_id');
    }

    /** @return BelongsTo<Ayah, $this> */
    public function hifzRecitedToAyah(): BelongsTo
    {
        return $this->belongsTo(Ayah::class, 'hifz_recited_to_ayah_id');
    }

    /** @return BelongsTo<Ayah, $this> */
    public function reviewRecitedFromAyah(): BelongsTo
    {
        return $this->belongsTo(Ayah::class, 'review_recited_from_ayah_id');
    }

    /** @return BelongsTo<Ayah, $this> */
    public function reviewRecitedToAyah(): BelongsTo
    {
        return $this->belongsTo(Ayah::class, 'review_recited_to_ayah_id');
    }

    /**
     * Every session a part of the day was recited in. The day's own grade
     * columns hold the latest of them.
     *
     * @return HasMany<PlanDayAttempt, $this>
     */
    public function attempts(): HasMany
    {
        return $this->hasMany(PlanDayAttempt::class);
    }

    /** @return BelongsTo<User, $this> */
    public function hifzRecorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'hifz_recorded_by');
    }

    /** @return BelongsTo<User, $this> */
    public function reviewRecorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'review_recorded_by');
    }

    /**
     * Whether the part was heard and graded (مقبول, جيد or ممتاز). «لم يسمع» (0)
     * is a grade but not a recitation.
     */
    public function isRecited(string $part): bool
    {
        return RecitationGrade::isRecited($this->{"{$part}_achievement"});
    }

    /**
     * Days whose part was recited.
     *
     * @param  Builder<StudentPlanDay>  $query
     */
    public function scopeRecited(Builder $query, string $part): void
    {
        $query->where("{$part}_achievement", '>=', 1);
    }

    /**
     * The day points are dated by: when a recited part was graded, else when
     * anything was. A «لم يسمع» earns nothing, so it never dates the points.
     */
    public function gamificationDate(): CarbonInterface
    {
        if ($this->isRecited('hifz') && $this->hifz_graded_at) {
            return $this->hifz_graded_at;
        }

        if ($this->isRecited('review') && $this->review_graded_at) {
            return $this->review_graded_at;
        }

        return $this->hifz_graded_at ?? $this->review_graded_at ?? $this->date;
    }

    /**
     * What the student actually recited for a part, when the teacher recorded
     * something other than the scheduled wird. Null when it went as scheduled.
     */
    public function formatRecitedRange(string $part = 'hifz'): ?string
    {
        return $part === 'review'
            ? self::formatAyahRange($this->reviewRecitedFromAyah, $this->reviewRecitedToAyah)
            : self::formatAyahRange($this->hifzRecitedFromAyah, $this->hifzRecitedToAyah);
    }

    public function formatRange($type = 'hifz', $reverseFill = true)
    {
        $from = $type === 'review' ? $this->reviewFromAyah : $this->fromAyah;
        $to = $type === 'review' ? $this->reviewToAyah : $this->toAyah;

        return self::formatAyahRange($from, $to);
    }

    /**
     * A range read the way teachers write it: "الملك 3-12", "النمل" for a whole
     * surah, "القلم 39 الى الملك 2" across surahs.
     */
    public static function formatAyahRange(?Ayah $from, ?Ayah $to): ?string
    {
        if (! $from || ! $to) {
            return null;
        }

        $fromSurah = $from->surah;
        $toSurah = $to->surah;

        $vFrom = $from->verse_number;
        $vTo = $to->verse_number;

        if ($fromSurah->id === $toSurah->id) {
            if ($vFrom == 1 && $vTo == $fromSurah->verses_count) {
                return $fromSurah->name_arabic;
            }

            return $fromSurah->name_arabic.' '.$vFrom.'-'.$vTo;
        }

        // Multiple surahs
        $isFromFullStart = ($vFrom == 1);
        $isToFullEnd = ($vTo == $toSurah->verses_count);

        if ($isFromFullStart && $isToFullEnd) {
            return $fromSurah->name_arabic.' - '.$toSurah->name_arabic;
        }

        $firstPart = $fromSurah->name_arabic;
        if (! $isFromFullStart) {
            $firstPart .= ' '.$vFrom;
        }

        $endPart = $toSurah->name_arabic;
        if (! $isToFullEnd) {
            $endPart .= ' '.$vTo;
        }

        return $firstPart.' الى '.$endPart;
    }
}
