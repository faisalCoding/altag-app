<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['name', 'type', 'fee_amount', 'is_active'])]
class Bus extends Model
{
    use HasFactory;

    protected $casts = [
        'fee_amount' => 'integer',
        'is_active' => 'boolean',
    ];

    /** @return BelongsToMany<BusBooking, $this> */
    public function bookings(): BelongsToMany
    {
        return $this->belongsToMany(BusBooking::class, 'bus_booking_bus')->withPivot('fee_amount');
    }

    /**
     * Whether a confirmed booking already holds this bus that day.
     *
     * Only confirmed ones count: a booking awaiting its prepayment holds nothing,
     * so the bus stays offered to everyone until somebody's booking is settled.
     */
    public function isTakenOn(string $date, ?int $ignoreBookingId = null): bool
    {
        return $this->bookings()
            ->whereDate('bus_bookings.date', $date)
            ->whereIn('bus_bookings.status', [BusBooking::CONFIRMED, BusBooking::RECEIVED])
            ->when($ignoreBookingId, fn ($q) => $q->where('bus_bookings.id', '!=', $ignoreBookingId))
            ->exists();
    }
}
