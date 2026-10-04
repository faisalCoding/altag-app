<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ExamLevel extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'direction',
        'start_ayah_id',
        'end_ayah_id',
        'previous_level_id',
    ];

    public function startAyah(): BelongsTo
    {
        return $this->belongsTo(Ayah::class, 'start_ayah_id');
    }

    public function endAyah(): BelongsTo
    {
        return $this->belongsTo(Ayah::class, 'end_ayah_id');
    }

    public function studentExams(): HasMany
    {
        return $this->hasMany(StudentExam::class);
    }

    public function previousLevel(): BelongsTo
    {
        return $this->belongsTo(ExamLevel::class, 'previous_level_id');
    }

    public function nextLevel(): HasOne
    {
        return $this->hasOne(ExamLevel::class, 'previous_level_id');
    }

    /**
     * How many juz the level covers from where it starts — the number teachers
     * call it by ("اختبار جزء النبأ والملك" is 2). Levels run from الناس by
     * default, so the count is taken back from juz 30. Null without an end.
     */
    public function juzCount(): ?int
    {
        $end = $this->endAyah;

        if ($end === null || $end->juz_number === null) {
            return null;
        }

        return $this->direction === 'baqarah_to_nas' ? (int) $end->juz_number : 31 - (int) $end->juz_number;
    }

    /**
     * The noun said after a juz count — "٣ أجزاء"، "١٥ جزءاً" — worded as the
     * teacher app words it. The number itself is shown apart.
     */
    public static function juzWord(int $count): string
    {
        return match (true) {
            $count === 1 => 'جزء',
            $count === 2 => 'جزآن',
            $count >= 3 && $count <= 10 => 'أجزاء',
            default => 'جزءاً',
        };
    }
}
