<?php

namespace App\Models;

use App\Support\ArabicDigits;
use Database\Factories\ScheduleWeekFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One week of one track. `week_number` counts from 1 at the programme's start
 * date, so the dates are derived rather than stored. `memo` holds the week's
 * memorisation: the wird text, the poem, its unit («البيتان»), the repetition
 * count, and the verses for each weekday keyed 0 = Sunday … 6 = Saturday.
 */
#[Fillable(['schedule_track_id', 'week_number', 'title', 'note', 'is_draft', 'memo'])]
class ScheduleWeek extends Model
{
    /** @use HasFactory<ScheduleWeekFactory> */
    use HasFactory;

    private const ORDINALS = [
        'الأول', 'الثاني', 'الثالث', 'الرابع', 'الخامس', 'السادس', 'السابع', 'الثامن', 'التاسع', 'العاشر',
        'الحادي عشر', 'الثاني عشر', 'الثالث عشر', 'الرابع عشر', 'الخامس عشر', 'السادس عشر', 'السابع عشر',
        'الثامن عشر', 'التاسع عشر', 'العشرون',
    ];

    protected function casts(): array
    {
        return [
            'week_number' => 'integer',
            'is_draft' => 'boolean',
            'memo' => 'array',
        ];
    }

    public function track(): BelongsTo
    {
        return $this->belongsTo(ScheduleTrack::class, 'schedule_track_id');
    }

    public function cells(): HasMany
    {
        return $this->hasMany(ScheduleCell::class)->orderBy('weekday')->orderBy('position');
    }

    /**
     * @param  Builder<ScheduleWeek>  $query
     */
    public function scopePublished(Builder $query): void
    {
        $query->where('is_draft', false);
    }

    public static function ordinalName(int $weekNumber): string
    {
        return 'الأسبوع '.(self::ORDINALS[$weekNumber - 1] ?? $weekNumber);
    }

    public function displayTitle(): string
    {
        return $this->title ?: self::ordinalName($this->week_number);
    }

    /**
     * The memorisation lines for one weekday, ready to print.
     *
     * @return array{wird: string, line: string, reps: string}
     */
    public function memoFor(int $weekday): array
    {
        $memo = $this->memo ?? [];
        $verses = $memo['verses'][$weekday] ?? $memo['verses'][(string) $weekday] ?? null;
        $poem = $memo['poem'] ?? '';
        $reps = $memo['reps'] ?? '';
        $count = (int) ArabicDigits::toWestern($reps);

        return [
            'wird' => $memo['wird'] ?? '',
            'line' => $verses ? ($poem ? $poem.': ' : '').($memo['unit'] ?? 'البيتان').' ('.$verses.')' : '',
            'reps' => $reps !== '' ? 'التكرار: '.$reps.' '.($count >= 3 && $count <= 10 ? 'مرات' : 'مرة') : '',
        ];
    }
}
