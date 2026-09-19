<?php

namespace App\Livewire\Public;

use App\Models\BusBooking;
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
 * The bus officer's page, reached by link and never signed into.
 *
 * He receives the buses back, decides what the state they came back in means
 * for the stage that took them, and takes the fee from a stage already paying
 * for the last time. Those three are one job, so they are one screen: a ruling
 * made on a different page from the evidence is a ruling made from memory.
 */
class BusOfficer extends Component
{
    public string $token = '';

    /** waiting | upcoming | all */
    public string $filter = 'waiting';

    // ── the booking whose checklist is open ─────────────────────────────────
    public ?int $receivingId = null;

    /** @var array<int, int|string> */
    public array $doneItems = [];

    // ── the ruling being made, if any ───────────────────────────────────────
    public ?int $rulingBookingId = null;

    public ?int $rulingStageId = null;

    public string $rulingStanding = StageBusStanding::PREPAY;

    public string $rulingReason = '';

    public function mount(string $token): void
    {
        abort_unless(hash_equals(BusBookingSettings::officerToken(), $token), 404);

        $this->token = $token;
    }

    public function setFilter(string $filter): void
    {
        $this->filter = in_array($filter, ['waiting', 'upcoming', 'all'], true) ? $filter : 'waiting';
        $this->closePanels();
    }

    // ── الاستلام ────────────────────────────────────────────────────────────

    /**
     * Step one: open the checklist. Nothing is ticked to begin with — the
     * officer says what was done, rather than clearing what was not.
     */
    public function startReceive(int $bookingId): void
    {
        $booking = $this->booking($bookingId);

        if (! $booking || ! $this->canReceive($booking)) {
            return;
        }

        $this->closePanels();
        $this->receivingId = $booking->id;
        $this->doneItems = [];
    }

    public function saveReceive(BusBookingService $bookings): void
    {
        $booking = $this->booking($this->receivingId);

        if (! $booking) {
            return;
        }

        try {
            $bookings->receive($booking, array_map('intval', $this->doneItems));
        } catch (\RuntimeException $e) {
            Flux::toast($e->getMessage(), variant: 'danger');

            return;
        }

        $failed = $bookings->failedItems($booking->fresh());

        $this->receivingId = null;
        $this->doneItems = [];

        if ($failed->isEmpty()) {
            Flux::toast(__('استُلم الباص، وكل البنود منجزة.'), variant: 'success');

            return;
        }

        // Straight into the ruling, with the reason already written from what
        // failed — the officer is standing in front of the bus, not hunting for
        // a screen.
        $this->rulingBookingId = $booking->id;
        $this->rulingStanding = StageBusStanding::PREPAY;
        $this->rulingReason = 'لم يُنجَز: '.$failed->implode('، ');

        Flux::toast(__('استُلم الباص مع مخالفات. قرارك؟'), variant: 'warning');
    }

    // ── القرار ──────────────────────────────────────────────────────────────

    /**
     * Ruling on a stage, whether it follows a handover or stands on its own.
     */
    public function openRuling(int $stageId): void
    {
        abort_unless(Stage::whereKey($stageId)->exists(), 404);

        $this->closePanels();
        $this->rulingBookingId = null;
        $this->rulingStageId = $stageId;
        $this->rulingStanding = StageBusStanding::PREPAY;
        $this->rulingReason = '';
    }

    public function saveRuling(BusBookingService $bookings): void
    {
        $booking = $this->rulingBookingId ? $this->booking($this->rulingBookingId) : null;
        $stage = $booking?->stage ?? Stage::find($this->rulingStageId);

        if (! $stage) {
            return;
        }

        try {
            $bookings->rule($stage, $this->rulingStanding, trim($this->rulingReason) ?: null, $booking);
        } catch (\RuntimeException $e) {
            Flux::toast($e->getMessage(), variant: 'danger');

            return;
        }

        $this->closePanels();

        Flux::toast(__('سُجّل القرار لمرحلة «'.$stage->name.'».'), variant: 'success');
    }

    /**
     * Leave the violation on the record without punishing it. The officer's call
     * to make, and the reason a person rules here instead of the system.
     */
    public function overlook(): void
    {
        $this->closePanels();

        Flux::toast(__('سُجّلت المخالفة دون عقوبة.'), variant: 'success');
    }

    // ── الرسوم والإلغاء ─────────────────────────────────────────────────────

    public function markPaid(int $bookingId, BusBookingService $bookings): void
    {
        $booking = $this->booking($bookingId);

        if (! $booking) {
            return;
        }

        try {
            $bookings->confirmPayment($booking);
        } catch (\RuntimeException $e) {
            Flux::toast($e->getMessage(), variant: 'danger');

            return;
        }

        Flux::toast(__('أُكِّد الحجز بعد استلام الرسوم.'), variant: 'success');
    }

    /**
     * The officer may call off anything, at any time — including a booking the
     * supervisor can no longer touch.
     */
    public function cancel(int $bookingId, BusBookingService $bookings): void
    {
        $booking = $this->booking($bookingId);

        if (! $booking) {
            return;
        }

        $bookings->cancel($booking, 'officer');
        $this->closePanels();

        Flux::toast(__('أُلغي الحجز.'), variant: 'success');
    }

    public function closePanels(): void
    {
        $this->receivingId = null;
        $this->rulingBookingId = null;
        $this->rulingStageId = null;
        $this->doneItems = [];
        $this->rulingReason = '';
    }

    /**
     * A bus can only be received once the day it went out has come.
     */
    public function canReceive(BusBooking $booking): bool
    {
        return ! in_array($booking->status, [BusBooking::RECEIVED, BusBooking::CANCELLED], true)
            && $booking->date->format('Y-m-d') <= now('Asia/Riyadh')->format('Y-m-d');
    }

    private function booking(?int $id): ?BusBooking
    {
        return $id ? BusBooking::with(['stage', 'buses'])->find($id) : null;
    }

    public function hijri(string $date): string
    {
        return HijriDate::withWeekday(Carbon::parse($date));
    }

    public function render(BusBookingService $bookings)
    {
        $today = now('Asia/Riyadh')->format('Y-m-d');

        $query = BusBooking::with(['stage:id,name', 'buses:id,name', 'checks'])
            ->when($this->filter === 'waiting', fn ($q) => $q
                ->whereNotIn('status', [BusBooking::RECEIVED, BusBooking::CANCELLED])
                ->whereDate('date', '<=', $today))
            ->when($this->filter === 'upcoming', fn ($q) => $q
                ->where('status', '!=', BusBooking::CANCELLED)
                ->whereDate('date', '>', $today));

        // Resolved here, not looked up in the list: a booking just received drops
        // out of the "waiting" filter, and the ruling panel would name nobody.
        $rulingStage = $this->rulingBookingId
            ? $this->booking($this->rulingBookingId)?->stage
            : Stage::find($this->rulingStageId);

        return view('livewire.public.bus-officer', [
            'rulingStage' => $rulingStage,
            'bookings' => $query->orderByDesc('date')->limit(60)->get(),
            'items' => BusHandoverItem::active()->get(),
            'stages' => Stage::orderBy('name')->get(['id', 'name'])
                ->each(fn (Stage $stage) => $stage->bus_standing = $bookings->standingFor($stage)),
            'waitingCount' => BusBooking::whereNotIn('status', [BusBooking::RECEIVED, BusBooking::CANCELLED])
                ->whereDate('date', '<=', $today)->count(),
            'today' => $today,
        ])->layout('layouts.blank');
    }
}
