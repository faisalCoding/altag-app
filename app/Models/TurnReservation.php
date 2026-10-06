<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A turn booked in a teacher's session, before each circle kept its own queue
 * (CircleTurn). Kept as a record, no longer read or written.
 */
class TurnReservation extends Model
{
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
}
