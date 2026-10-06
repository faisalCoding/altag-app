<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * A student's turn in their circle's tasmeeh queue on a day. Each circle
 * numbers its own queue from one; the teachers of the circle call the turns.
 *
 * `date` is left uncast and always written as Y-m-d, so the unique keys on the
 * day never see two spellings of it.
 */
class CircleTurn extends Model
{
    protected $fillable = [
        'circle_id',
        'student_id',
        'date',
        'turn_number',
    ];

    protected $casts = [
        'turn_number' => 'integer',
    ];

    /** @return BelongsTo<Circle, $this> */
    public function circle(): BelongsTo
    {
        return $this->belongsTo(Circle::class);
    }

    /** @return BelongsTo<Student, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /**
     * Book the student the next turn in their circle's queue for the day, or
     * return the turn they already hold.
     *
     * The next number is the day's highest plus one, read and then written.
     * When booking opens, several students tap at the same moment, read the
     * same highest number and all write the next one; the unique key on
     * (circle, date, turn_number) lets one through and refuses the rest, and a
     * refused write reads the queue again and tries the number after.
     * lockForUpdate would not serialise this: the app runs on SQLite, where it
     * does nothing.
     *
     * Returns null only when every attempt lost the race. wasRecentlyCreated
     * tells a new turn from one the student already had.
     */
    public static function reserveNext(int $circleId, int $studentId, string $date, int $attempts = 5): ?self
    {
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $existing = self::where('circle_id', $circleId)
                ->whereDate('date', $date)
                ->where('student_id', $studentId)
                ->first();

            if ($existing) {
                return $existing;
            }

            $highestTurn = (int) self::where('circle_id', $circleId)
                ->whereDate('date', $date)
                ->max('turn_number');

            try {
                return self::create([
                    'circle_id' => $circleId,
                    'student_id' => $studentId,
                    'date' => $date,
                    'turn_number' => $highestTurn + 1,
                ]);
            } catch (UniqueConstraintViolationException) {
                // Another booking took that number first; read the queue again.
            }
        }

        return null;
    }
}
