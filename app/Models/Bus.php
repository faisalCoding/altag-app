<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;

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
     * Whether a booking already holds this bus that day.
     *
     * A booking awaiting its prepayment holds it too — that is the point of the
     * fee deadline, which frees the bus again if the money never comes.
     */
    public function isTakenOn(string $date, ?int $ignoreBookingId = null): bool
    {
        return $this->bookings()
            ->whereDate('bus_bookings.date', $date)
            ->holding()
            ->when($ignoreBookingId, fn ($q) => $q->where('bus_bookings.id', '!=', $ignoreBookingId))
            ->exists();
    }

    /**
     * The bookings holding this bus that day — more than one means a clash.
     *
     * @return Collection<int, BusBooking>
     */
    public function holdersOn(string $date): Collection
    {
        return $this->bookings()
            ->whereDate('bus_bookings.date', $date)
            ->holding()
            ->with('stage:id,name')
            ->get();
    }
}
