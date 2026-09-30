<?php

namespace App\Console\Commands;

use App\Services\BusBookingService;
use Illuminate\Console\Command;

/**
 * Cancel the bus bookings whose prepayment never arrived.
 *
 * The buses are already free by the time this runs — a booking stops holding
 * them the moment its deadline passes — so this exists to make the record say
 * so, rather than leave a supervisor reading «بانتظار الدفع» about a booking
 * that is gone.
 */
class ExpireUnpaidBusBookings extends Command
{
    protected $signature = 'bus:expire-unpaid';

    protected $description = 'إلغاء حجوزات الباصات التي لم تُدفع رسومها في موعدها';

    public function handle(BusBookingService $bookings): int
    {
        $count = $bookings->expireUnpaid();

        $this->info($count === 0
            ? 'لا حجوزات انقضى موعد دفعها.'
            : "أُلغي {$count} حجزاً لعدم دفع الرسوم في موعدها.");

        return self::SUCCESS;
    }
}
