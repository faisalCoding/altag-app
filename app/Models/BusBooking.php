<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'stage_id', 'date', 'status', 'fee_total', 'fee_paid_at',
    'confirmed_at', 'received_at', 'cancelled_at', 'cancelled_by', 'notes',
])]
class BusBooking extends Model
{
    /** Awaiting the prepayment fee. Holds no bus. */
    public const PENDING = 'pending';

    /** Holds its buses for the day. */
    public const CONFIRMED = 'confirmed';

    /** The buses came back and were checked. */
    public const RECEIVED = 'received';

    public const CANCELLED = 'cancelled';

    protected $casts = [
        'date' => 'date',
        'fee_total' => 'integer',
        'fee_paid_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'received_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    /** @return BelongsTo<Stage, $this> */
    public function stage(): BelongsTo
    {
        return $this->belongsTo(Stage::class);
    }

    /** @return BelongsToMany<Bus, $this> */
    public function buses(): BelongsToMany
    {
        return $this->belongsToMany(Bus::class, 'bus_booking_bus')->withPivot('fee_amount');
    }

    /** @return HasMany<BusBookingCheck, $this> */
    public function checks(): HasMany
    {
        return $this->hasMany(BusBookingCheck::class);
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::CANCELLED;
    }

    /**
     * Whether the supervisor may still call this off.
     *
     * Closes a configurable number of days ahead — the buses have to be planned
     * around, and a stage dropping out the night before leaves one standing idle.
     * The officer is not bound by this; he may cancel anything at any time.
     */
    public function isCancellableBySupervisor(): bool
    {
        if (! in_array($this->status, [self::PENDING, self::CONFIRMED], true)) {
            return false;
        }

        // Compared as calendar dates, not as instants: the column carries no zone,
        // so measuring it against a Riyadh midnight shifts it three hours and a
        // trip tomorrow reads as still open right up until it isn't.
        //
        // A lock of one day means the booking is fixed from the day before the
        // trip onwards, so calling it off has to happen earlier than that.
        $lastOpenDay = now('Asia/Riyadh')->addDays(BusBookingSettings::lockDays())->format('Y-m-d');

        return $this->date->format('Y-m-d') > $lastOpenDay;
    }
}
