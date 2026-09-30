<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A ruling on how a stage may book from here on.
 *
 * Kept as a history rather than a column, because the interesting question is
 * never only "may they book" but "who decided that, when, and over what".
 */
#[Fillable(['stage_id', 'standing', 'reason', 'bus_booking_id'])]
class StageBusStanding extends Model
{
    /** Books freely. */
    public const OK = 'ok';

    /** May book, but the booking stays pending until the fee is received. */
    public const PREPAY = 'prepay';

    /** May not book at all. */
    public const BANNED = 'banned';

    /** @return BelongsTo<Stage, $this> */
    public function stage(): BelongsTo
    {
        return $this->belongsTo(Stage::class);
    }

    /** @return BelongsTo<BusBooking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(BusBooking::class, 'bus_booking_id');
    }
}
