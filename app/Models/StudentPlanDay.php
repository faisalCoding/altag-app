<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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
    ];

    protected $casts = [
        'date' => 'date',
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

    public function formatRange($type = 'hifz', $reverseFill = true)
    {
        $from = $type === 'review' ? $this->reviewFromAyah : $this->fromAyah;
        $to = $type === 'review' ? $this->reviewToAyah : $this->toAyah;

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
        // Determine logical direction from ID progression
        // In reverse fill, fromSurah->id > toSurah->id
        $isForward = $fromSurah->id < $toSurah->id;

        $isFromFullStart = ($vFrom == 1);
        $isToFullEnd = ($vTo == $toSurah->verses_count);

        if ($isFromFullStart && $isToFullEnd) {
            return $fromSurah->name_arabic.' - '.$toSurah->name_arabic;
        }

        $fromEndVerse = $fromSurah->verses_count;
        $firstPart = $fromSurah->name_arabic.' '.$vFrom.'-'.$fromEndVerse;
        if ($isFromFullStart) {
            $firstPart = $fromSurah->name_arabic;
        }

        $nextSurahId = $isForward ? $fromSurah->id + 1 : $fromSurah->id - 1;
        $nextSurah = Surah::find($nextSurahId);

        if ($nextSurah) {
            if ($nextSurah->id === $toSurah->id) {
                $endPart = 'و '.$toSurah->name_arabic.' 1-'.$vTo;
                if ($isToFullEnd) {
                    $endPart = 'و '.$toSurah->name_arabic;
                }

                return $firstPart.' '.$endPart;
            } else {
                $endPart = 'و من '.$nextSurah->name_arabic.' الى '.$toSurah->name_arabic;
                if (! $isToFullEnd) {
                    $endPart .= ' '.$vTo;
                }

                return $firstPart.' '.$endPart;
            }
        }

        return $fromSurah->name_arabic.' '.$vFrom.' الى '.$toSurah->name_arabic.' '.$vTo;
    }
}
