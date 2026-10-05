<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;

class TurnReservation extends Model
{
    //
    protected $fillable = [
        'turn_reservation_session_id',
        'student_id',
        'date',
        'turn_number',
    ];

    public function session()
    {
        return $this->belongsTo(TurnReservationSession::class);
    }

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    /**
     * Book the student the next turn in a session's queue for the day, or
     * return the booking they already hold.
     *
     * The next number is the day's highest plus one, read and then written. When
     * the booking window opens, several students tap at the same moment, read the
     * same highest number and all write the next one; the unique index on
     * (session, date, turn_number) lets one through and refuses the rest, which
     * used to reach the student as an error page and no booking. A refused write
     * now reads the queue again and tries the number after. lockForUpdate would
     * not serialise this: the app runs on SQLite, where it does nothing.
     *
     * Returns null only when every attempt lost the race. wasRecentlyCreated
     * tells a new booking from one the student already had.
     */
    public static function reserveNext(int $sessionId, int $studentId, string $date, int $attempts = 5): ?self
    {
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $existing = self::where('turn_reservation_session_id', $sessionId)
                ->whereDate('date', $date)
                ->where('student_id', $studentId)
                ->first();

            if ($existing) {
                return $existing;
            }

            $highestTurn = (int) self::where('turn_reservation_session_id', $sessionId)
                ->whereDate('date', $date)
                ->max('turn_number');

            try {
                return self::create([
                    'turn_reservation_session_id' => $sessionId,
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
