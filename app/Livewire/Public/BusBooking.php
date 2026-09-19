<?php

namespace App\Livewire\Public;

use App\Models\Bus;
use App\Models\BusBooking as Booking;
use App\Models\BusBookingSettings;
use App\Models\BusHandoverItem;
use App\Models\Stage;
use App\Models\StageBusStanding;
use App\Services\BusBookingService;
use App\Support\HijriDate;
use Flux\Flux;
use Illuminate\Support\Carbon;
use Livewire\Component;

/**
 * Booking a bus, for supervisors who reach this by link and never sign in.
 *
 * A walk rather than a form: the stage, then whether it may book at all, then
 * the day, then what is free that day, then what it is agreeing to. Each answer
 * decides what the next question can be, and a page that asked all five at once
 * would have to refuse most of its own combinations.
 */
class BusBooking extends Component
{
    public string $token = '';

    public int $step = 1;

    public ?int $stageId = null;

    public string $date = '';

    /** @var array<int, int|string> */
    public array $busIds = [];

    public bool $agreed = false;

    /** The booking just made, while its success screen is up. */
    public ?int $bookedId = null;

    public function mount(string $token, BusBookingService $bookings): void
    {
        abort_unless(hash_equals(BusBookingSettings::supervisorToken(), $token), 404);

        $this->token = $token;
    }

    public function chooseStage(int $stageId): void
    {
        abort_unless(Stage::whereKey($stageId)->exists(), 404);

        $this->stageId = $stageId;
        $this->step = 2;
        $this->reset(['date', 'busIds', 'agreed', 'bookedId']);
    }

    public function toStep(int $step): void
    {
        // Only ever backwards, or one forward from a step that is satisfied.
        // Forward jumps are refused so the guards below cannot be stepped over.
        if ($step < $this->step) {
            $this->step = max(1, $step);
            $this->bookedId = null;
        }
    }

    /**
     * From the success screen back to where the stage's bookings are listed.
     */
    public function backToStatus(): void
    {
        $this->reset(['date', 'busIds', 'agreed', 'bookedId']);
        $this->step = 2;
    }

    public function startDate(BusBookingService $bookings): void
    {
        if ($this->standing() === StageBusStanding::BANNED) {
            return;
        }

        $this->step = 3;
    }

    public function chooseDate(BusBookingService $bookings): void
    {
        $this->validate(['date' => 'required|date'], ['date.required' => 'اختر اليوم.']);

        if (! $bookings->isBookableDate($this->date)) {
            $this->addError('date', 'الحجز غير متاح في هذا اليوم.');

            return;
        }

        $this->busIds = [];
        $this->step = 4;
    }

    public function chooseBuses(): void
    {
        if ($this->busIds === []) {
            $this->addError('busIds', 'اختر باصاً واحداً على الأقل.');

            return;
        }

        $this->step = 5;
    }

    public function confirm(BusBookingService $bookings): void
    {
        if (! $this->agreed) {
            $this->addError('agreed', 'أقرّ بالبنود قبل التأكيد.');

            return;
        }

        $stage = Stage::find($this->stageId);

        if (! $stage) {
            abort(404);
        }

        try {
            // Re-checked here rather than trusted from the walk: the steps are
            // held in the browser, and a bus can be taken while they are open.
            $booking = $bookings->book($stage, $this->date, array_map('intval', $this->busIds));
        } catch (\RuntimeException $e) {
            Flux::toast($e->getMessage(), variant: 'danger');
            $this->step = 3;

            return;
        }

        // Nobody is shown a success screen on the strength of a write that did
        // not throw. The booking is read back from the database and its buses
        // checked against everyone else's before the word «تم» appears.
        $booking = Booking::with('buses')->find($booking->id);

        if (! $booking || ! $booking->holdsBuses() || $bookings->conflictsFor($booking)->isNotEmpty()) {
            if ($booking) {
                $bookings->cancel($booking, 'system');
            }

            Flux::toast(__('تعذّر إتمام الحجز — تعارض في الباصات. جرّب يوماً أو باصاً آخر.'), variant: 'danger');
            $this->step = 3;

            return;
        }

        $this->bookedId = $booking->id;
        $this->step = 6;
    }

    /**
     * A supervisor may call off a booking of their own stage, while the lock
     * still allows it. Anything else is the officer's to undo.
     */
    public function cancel(int $bookingId, BusBookingService $bookings): void
    {
        $booking = Booking::where('stage_id', $this->stageId)->find($bookingId);

        if (! $booking || ! $booking->isCancellableBySupervisor()) {
            Flux::toast(__('لا يمكن إلغاء هذا الحجز الآن. تواصل مع المسؤول.'), variant: 'danger');

            return;
        }

        $bookings->cancel($booking, 'supervisor');

        Flux::toast(__('أُلغي الحجز.'), variant: 'success');
    }

    public function standing(): string
    {
        $stage = Stage::find($this->stageId);

        return $stage ? app(BusBookingService::class)->standingFor($stage) : StageBusStanding::OK;
    }

    public function render(BusBookingService $bookings)
    {
        $stage = Stage::find($this->stageId);

        return view('livewire.public.bus-booking', [
            'stages' => Stage::orderBy('name')->get(['id', 'name']),
            'stage' => $stage,
            'standing' => $stage ? $bookings->standingFor($stage) : null,
            'ruling' => $stage ? $bookings->latestRulingFor($stage) : null,
            'officerPhone' => BusBookingSettings::officerPhone(),
            'weekdays' => BusBookingSettings::weekdays(),
            'windowFrom' => $bookings->bookingWindow()[0],
            'windowTo' => $bookings->bookingWindow()[1],
            'lockDays' => BusBookingSettings::lockDays(),
            'penaltyText' => BusBookingSettings::penaltyText(),
            'items' => BusHandoverItem::active()->get(),
            'booked' => $this->bookedId ? Booking::with('buses:id,name')->find($this->bookedId) : null,
            'buses' => $this->step === 4 && $this->date ? $bookings->availableBuses($this->date) : collect(),
            'chosen' => $this->step === 5 ? Bus::whereIn('id', $this->busIds)->get() : collect(),
            'feeDueOn' => $this->date !== '' ? $bookings->paymentDeadline($this->date) : null,
            'upcoming' => $stage
                ? Booking::with('buses:id,name')
                    ->where('stage_id', $stage->id)
                    ->whereDate('date', '>=', now('Asia/Riyadh')->format('Y-m-d'))
                    ->where('status', '!=', Booking::CANCELLED)
                    ->orderBy('date')
                    ->get()
                : collect(),
            'today' => now('Asia/Riyadh')->format('Y-m-d'),
        ])->layout('layouts.blank');
    }

    /**
     * The fee this stage would owe for what it has picked — nothing at all
     * unless it is on prepayment.
     */
    public function feeTotal(): int
    {
        if ($this->standing() !== StageBusStanding::PREPAY) {
            return 0;
        }

        return (int) Bus::whereIn('id', $this->busIds)->sum('fee_amount');
    }

    public function hijri(string $date): string
    {
        return HijriDate::withWeekday(Carbon::parse($date));
    }

    /**
     * Weekday and day-month without the year — short enough that both ends of a
     * range fit on a phone, and the year adds nothing inside a single week.
     */
    public function hijriShort(string $date): string
    {
        $carbon = Carbon::parse($date);

        return HijriDate::weekday($carbon).' '.HijriDate::dayMonth($carbon);
    }
}
