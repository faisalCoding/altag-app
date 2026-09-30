<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['bus_booking_id', 'bus_handover_item_id', 'label', 'is_done'])]
class BusBookingCheck extends Model
{
    protected $casts = ['is_done' => 'boolean'];

    /** @return BelongsTo<BusBooking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(BusBooking::class, 'bus_booking_id');
    }
}
