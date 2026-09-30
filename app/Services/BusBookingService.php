<?php

namespace App\Services;

use App\Models\Bus;
use App\Models\BusBooking;
use App\Models\BusBookingSettings;
use App\Models\BusHandoverItem;
use App\Models\Stage;
use App\Models\StageBusStanding;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The rules of booking a bus, in one place.
 *
 * Two pages reach these without an account — the supervisors' and the officer's
 * — so nothing here trusts what it is handed: the date, the stage's standing and
 * the buses are all checked again at the moment of writing.
 */
class BusBookingService
{
    /**
     * Where a stage currently stands. Nothing recorded means it books freely.
     */
    public function standingFor(Stage $stage): string
    {
        return StageBusStanding::where('stage_id', $stage->id)
            ->orderByDesc('id')
            ->value('standing') ?? StageBusStanding::OK;
    }

    public function latestRulingFor(Stage $stage): ?StageBusStanding
    {
        return StageBusStanding::where('stage_id', $stage->id)->orderByDesc('id')->first();
    }

    /**
     * The span a trip may fall in: from today to whenever booking closes.
     *
     * Unbounded at the far end unless the academy confines booking to the week
     * in progress. That week opens on a Saturday and reaches the Saturday after
     * it. The opening Saturday is itself out: booking opens that morning for the
     * days to come, not for the day it opens on — a bus for today had to be
     * arranged before today.
     *
     * @return array{0: string, 1: string|null}
     */
    public function bookingWindow(): array
    {
        $today = now('Asia/Riyadh');

        if (! BusBookingSettings::sameWeekOnly()) {
            return [$today->format('Y-m-d'), null];
        }

        $saturday = $today->copy()->startOfWeek(CarbonInterface::SATURDAY);

        return [
            max($today->format('Y-m-d'), $saturday->copy()->addDay()->format('Y-m-d')),
            $saturday->copy()->addDays(7)->format('Y-m-d'),
        ];
    }

    /**
     * The day the fee for a trip on this date is due.
     *
     * The manager names a weekday; the deadline is that weekday inside the week
     * the trip belongs to — one date for every booking in that week, which is how
     * an officer collecting money actually thinks about it. A trip that falls
     * before that weekday is its own deadline: no fee is due after the bus has
     * already gone out.
     */
    public function paymentDeadline(string $tripDate): string
    {
        $trip = Carbon::parse($tripDate, 'Asia/Riyadh')->startOfDay();

        // Stepping back a day first: on a Saturday trip, that Saturday closes the
        // week rather than opening it, and its deadline belongs to the week gone.
        $opening = $trip->copy()->subDay()->startOfWeek(CarbonInterface::SATURDAY);

        // 1=Sunday … 7=Saturday, and the week's days are opening+1 … opening+7,
        // so the offset is the weekday number itself.
        $due = $opening->addDays(BusBookingSettings::feeDeadlineWeekday());

        return min($due->format('Y-m-d'), $trip->format('Y-m-d'));
    }

    /**
     * Whether a trip may fall on this day: an allowed weekday, inside the window,
     * and not already past.
     */
    public function isBookableDate(string $date): bool
    {
        $day = Carbon::parse($date, 'Asia/Riyadh')->startOfDay();
        [$from, $to] = $this->bookingWindow();

        // Compared as calendar dates: the window is made of days, not instants.
        if ($day->format('Y-m-d') < $from || ($to !== null && $day->format('Y-m-d') > $to)) {
            return false;
        }

        $allowed = BusBookingSettings::weekdays();

        // Empty means no restriction; otherwise 1=Sunday … 7=Saturday, matching
        // how the attendance periods already number their days.
        return $allowed === [] || in_array($day->dayOfWeek + 1, $allowed, true);
    }

    /**
     * Buses a stage could ask for that day — the ones nobody is holding.
     *
     * A booking awaiting its fee holds its buses too, so what this returns is
     * genuinely free rather than free-for-now.
     *
     * @return Collection<int, Bus>
     */
    public function availableBuses(string $date, ?int $ignoreBookingId = null): Collection
    {
        return Bus::where('is_active', true)
            ->orderBy('name')
            ->get()
            ->reject(fn (Bus $bus) => $bus->isTakenOn($date, $ignoreBookingId))
            ->values();
    }

    /**
     * Record a booking.
     *
     * A stage on prepayment holds its buses from this moment like anybody else.
     * What it does not get is forever: the fee has a day it is due, and the hold
     * dies with it.
     *
     * @param  array<int, int>  $busIds
     */
    public function book(Stage $stage, string $date, array $busIds): BusBooking
    {
        $standing = $this->standingFor($stage);

        if ($standing === StageBusStanding::BANNED) {
            throw new \RuntimeException('هذه المرحلة محرومة من حجز الباصات حالياً.');
        }

        if (! $this->isBookableDate($date)) {
            throw new \RuntimeException('لا يمكن الحجز في هذا اليوم.');
        }

        $prepaying = $standing === StageBusStanding::PREPAY;

        return DB::transaction(function () use ($stage, $date, $busIds, $prepaying) {
            // Locked before they are read: two supervisors tapping «تأكيد» in the
            // same second would otherwise both find the bus free and both take it.
            // On SQLite this is a no-op, but SQLite serialises writers anyway.
            $buses = Bus::where('is_active', true)
                ->whereIn('id', $busIds)
                ->lockForUpdate()
                ->get();

            if ($buses->isEmpty()) {
                throw new \RuntimeException('اختر باصاً واحداً على الأقل.');
            }

            $taken = $buses->filter(fn (Bus $bus) => $bus->isTakenOn($date));

            if ($taken->isNotEmpty()) {
                throw new \RuntimeException('حُجز قبل قليل: '.$taken->pluck('name')->implode('، '));
            }

            $booking = BusBooking::create([
                'stage_id' => $stage->id,
                'date' => $date,
                'status' => $prepaying ? BusBooking::PENDING : BusBooking::CONFIRMED,
                'fee_total' => $prepaying ? $buses->sum('fee_amount') : 0,
                'fee_due_on' => $prepaying ? $this->paymentDeadline($date) : null,
                'confirmed_at' => $prepaying ? null : now(),
            ]);

            foreach ($buses as $bus) {
                $booking->buses()->attach($bus->id, ['fee_amount' => $prepaying ? $bus->fee_amount : 0]);
            }

            // Read back after the write, inside the same transaction: the check
            // above looked at the world before this booking existed, and only a
            // look afterwards can prove nothing else slipped in beside it.
            $clash = $this->conflictsFor($booking);

            if ($clash->isNotEmpty()) {
                throw new \RuntimeException('حُجز قبل قليل: '.$clash->pluck('name')->implode('، '));
            }

            return $booking;
        });
    }

    /**
     * Buses this booking holds that somebody else is holding too.
     *
     * Nothing should ever come back from this. It runs all the same, before a
     * supervisor is told his booking went through, because "the write did not
     * throw" and "the bus is his" are not the same claim.
     *
     * @return Collection<int, Bus>
     */
    public function conflictsFor(BusBooking $booking): Collection
    {
        if (! $booking->holdsBuses()) {
            return collect();
        }

        $date = $booking->date->format('Y-m-d');

        return $booking->buses->filter(fn (Bus $bus) => $bus->isTakenOn($date, $booking->id))->values();
    }

    /**
     * Every clash across the whole calendar, for the manager to see and settle.
     *
     * @return Collection<int, array{bus: Bus, date: string, bookings: Collection<int, BusBooking>}>
     */
    public function allConflicts(?string $from = null): Collection
    {
        $from ??= now('Asia/Riyadh')->format('Y-m-d');

        // Narrowed in SQL first. A day where one bus appears twice is the only
        // thing that could be a clash, and on a healthy calendar there are none
        // — so the work below almost always runs over an empty list.
        $suspects = DB::table('bus_booking_bus')
            ->join('bus_bookings', 'bus_bookings.id', '=', 'bus_booking_bus.bus_booking_id')
            ->whereDate('bus_bookings.date', '>=', $from)
            ->groupBy('bus_booking_bus.bus_id', 'bus_bookings.date')
            ->havingRaw('count(*) > 1')
            ->select('bus_booking_bus.bus_id', 'bus_bookings.date')
            ->get();

        if ($suspects->isEmpty()) {
            return collect();
        }

        $buses = Bus::whereIn('id', $suspects->pluck('bus_id')->unique())->get()->keyBy('id');

        return $suspects
            ->map(function ($row) use ($buses) {
                $bus = $buses->get((int) $row->bus_id);
                $date = Carbon::parse($row->date)->format('Y-m-d');

                // Two rows are not two holds: one of them may be cancelled, or a
                // prepayment whose deadline went by.
                return $bus ? ['bus' => $bus, 'date' => $date, 'bookings' => $bus->holdersOn($date)] : null;
            })
            ->filter(fn (?array $row) => $row && $row['bookings']->count() > 1)
            ->sortBy('date')
            ->values();
    }

    /**
     * Cancel the bookings whose fee never came, freeing their buses.
     *
     * The hold is already gone by the time this runs — holdsBuses() reads the
     * deadline directly — so this writes down what is true rather than deciding
     * it. That keeps the buses right even on a morning the scheduler did not run.
     *
     * @return int how many lapsed
     */
    public function expireUnpaid(): int
    {
        $today = now('Asia/Riyadh')->format('Y-m-d');

        $lapsed = BusBooking::where('status', BusBooking::PENDING)
            ->whereNotNull('fee_due_on')
            ->whereDate('fee_due_on', '<', $today)
            ->get();

        foreach ($lapsed as $booking) {
            $booking->update([
                'status' => BusBooking::CANCELLED,
                'cancelled_at' => now(),
                'cancelled_by' => 'system',
            ]);
        }

        return $lapsed->count();
    }

    /**
     * The officer has the fee in hand: the booking is settled for good.
     *
     * The buses are checked once more, because a booking whose deadline ran out
     * stopped holding them and somebody else may have taken one since. The
     * officer is told which, and settles it himself — that was his call to make.
     */
    public function confirmPayment(BusBooking $booking): void
    {
        if (! $booking->isPending()) {
            throw new \RuntimeException('هذا الحجز ليس بانتظار الدفع.');
        }

        // Refused here as well as by the nightly sweep, so the answer does not
        // depend on whether the sweep has run yet this morning.
        if ($booking->hasLapsed()) {
            throw new \RuntimeException('انقضى موعد دفع هذا الحجز، ولم يعد قائماً.');
        }

        $date = $booking->date->format('Y-m-d');

        $taken = $booking->buses->filter(fn (Bus $bus) => $bus->isTakenOn($date, $booking->id));

        if ($taken->isNotEmpty()) {
            throw new \RuntimeException(
                'تعذّر التأكيد — حُجزت هذه الباصات لمرحلة أخرى في نفس اليوم: '.$taken->pluck('name')->implode('، ')
            );
        }

        $booking->update([
            'status' => BusBooking::CONFIRMED,
            'fee_paid_at' => now(),
            'confirmed_at' => now(),
        ]);
    }

    /**
     * The buses came back. Each item is written down as it read at the time.
     *
     * @param  array<int, int>  $doneItemIds
     */
    public function receive(BusBooking $booking, array $doneItemIds): void
    {
        if ($booking->isCancelled()) {
            throw new \RuntimeException('هذا الحجز ملغى.');
        }

        DB::transaction(function () use ($booking, $doneItemIds) {
            $booking->checks()->delete();

            foreach (BusHandoverItem::active()->get() as $item) {
                $booking->checks()->create([
                    'bus_handover_item_id' => $item->id,
                    'label' => $item->label,
                    'is_done' => in_array($item->id, array_map('intval', $doneItemIds), true),
                ]);
            }

            $booking->update(['status' => BusBooking::RECEIVED, 'received_at' => now()]);
        });
    }

    /**
     * Items the stage failed on, for the ruling the officer is about to make.
     *
     * @return Collection<int, string>
     */
    public function failedItems(BusBooking $booking): Collection
    {
        return $booking->checks()->where('is_done', false)->pluck('label');
    }

    /**
     * Rule on how a stage may book from here on. The officer's decision, not
     * the system's: a missing photograph is not the same as a missing bus, and
     * only a person standing there can tell the difference.
     */
    public function rule(Stage $stage, string $standing, ?string $reason = null, ?BusBooking $booking = null): StageBusStanding
    {
        if (! in_array($standing, [StageBusStanding::OK, StageBusStanding::PREPAY, StageBusStanding::BANNED], true)) {
            throw new \RuntimeException('حالة غير معروفة.');
        }

        return StageBusStanding::create([
            'stage_id' => $stage->id,
            'standing' => $standing,
            'reason' => $reason,
            'bus_booking_id' => $booking?->id,
        ]);
    }

    public function cancel(BusBooking $booking, string $by): void
    {
        if ($booking->isCancelled()) {
            return;
        }

        $booking->update([
            'status' => BusBooking::CANCELLED,
            'cancelled_at' => now(),
            'cancelled_by' => $by,
        ]);
    }
}
