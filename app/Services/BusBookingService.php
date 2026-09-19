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
     * it — eight days, both ends included — so a trip on the turning day can be
     * arranged a week ahead rather than only on the morning itself.
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
            max($today->format('Y-m-d'), $saturday->format('Y-m-d')),
            $saturday->copy()->addDays(7)->format('Y-m-d'),
        ];
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
     * Buses a stage could ask for that day.
     *
     * A pending booking holds nothing, so a bus somebody is waiting to pay for
     * is still offered — with `pending_elsewhere` set, so the page can say so
     * rather than let two stages discover the clash on the morning itself.
     *
     * @return Collection<int, Bus>
     */
    public function availableBuses(string $date, ?int $ignoreBookingId = null): Collection
    {
        return Bus::where('is_active', true)
            ->orderBy('name')
            ->get()
            ->reject(fn (Bus $bus) => $bus->isTakenOn($date, $ignoreBookingId))
            ->each(function (Bus $bus) use ($date, $ignoreBookingId) {
                $bus->pending_elsewhere = $bus->bookings()
                    ->whereDate('bus_bookings.date', $date)
                    ->where('bus_bookings.status', BusBooking::PENDING)
                    ->when($ignoreBookingId, fn ($q) => $q->where('bus_bookings.id', '!=', $ignoreBookingId))
                    ->exists();
            })
            ->values();
    }

    /**
     * Record a booking.
     *
     * A stage on prepayment gets a pending booking that holds nothing: the fee
     * has to reach the officer before the buses are actually theirs.
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

        $buses = Bus::where('is_active', true)->whereIn('id', $busIds)->get();

        if ($buses->isEmpty()) {
            throw new \RuntimeException('اختر باصاً واحداً على الأقل.');
        }

        $taken = $buses->filter(fn (Bus $bus) => $bus->isTakenOn($date));

        if ($taken->isNotEmpty()) {
            throw new \RuntimeException('حُجز قبل قليل: '.$taken->pluck('name')->implode('، '));
        }

        $prepaying = $standing === StageBusStanding::PREPAY;

        return DB::transaction(function () use ($stage, $date, $buses, $prepaying) {
            $booking = BusBooking::create([
                'stage_id' => $stage->id,
                'date' => $date,
                'status' => $prepaying ? BusBooking::PENDING : BusBooking::CONFIRMED,
                'fee_total' => $prepaying ? $buses->sum('fee_amount') : 0,
                'confirmed_at' => $prepaying ? null : now(),
            ]);

            foreach ($buses as $bus) {
                $booking->buses()->attach($bus->id, ['fee_amount' => $prepaying ? $bus->fee_amount : 0]);
            }

            return $booking;
        });
    }

    /**
     * The officer has the fee in hand: the booking becomes real.
     *
     * The buses are checked again here, because a pending booking held none of
     * them and somebody else may have taken one in the meantime. The officer is
     * told which, and settles it himself — that was his call to make.
     */
    public function confirmPayment(BusBooking $booking): void
    {
        if (! $booking->isPending()) {
            throw new \RuntimeException('هذا الحجز ليس بانتظار الدفع.');
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
