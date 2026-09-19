<?php

use App\Models\Bus;
use App\Models\BusBooking;
use App\Models\BusBookingSettings;
use App\Models\BusHandoverItem;
use App\Models\Setting;
use App\Models\Stage;
use App\Models\StageBusStanding;
use App\Services\BusBookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    // A Thursday, so "tomorrow" is a Friday and the week is easy to reason about.
    Carbon\Carbon::setTestNow('2026-09-17 09:00:00');

    $this->stage = Stage::factory()->create(['name' => 'الرواد']);
    $this->other = Stage::factory()->create(['name' => 'السنابل']);

    $this->hiace = Bus::create(['name' => 'هايس ١', 'type' => 'hiace', 'fee_amount' => 100]);
    $this->coaster = Bus::create(['name' => 'كوستر ١', 'type' => 'coaster', 'fee_amount' => 150]);

    $this->service = new BusBookingService;
    $this->date = '2026-09-20';
});

it('books freely for a stage with nothing against it', function () {
    $booking = $this->service->book($this->stage, $this->date, [$this->hiace->id]);

    expect($booking->status)->toBe(BusBooking::CONFIRMED);
    expect($booking->fee_total)->toBe(0);
    expect($booking->confirmed_at)->not->toBeNull();
});

it('takes more than one bus in a single booking', function () {
    $booking = $this->service->book($this->stage, $this->date, [$this->hiace->id, $this->coaster->id]);

    expect($booking->buses)->toHaveCount(2);
});

it('refuses a stage that has been banned', function () {
    $this->service->rule($this->stage, StageBusStanding::BANNED, 'تكرار المخالفة');

    expect(fn () => $this->service->book($this->stage, $this->date, [$this->hiace->id]))
        ->toThrow(RuntimeException::class);

    expect(BusBooking::count())->toBe(0);
});

it('charges a prepaying stage each bus it takes', function () {
    $this->service->rule($this->stage, StageBusStanding::PREPAY, 'رجع الباص غير نظيف');

    $booking = $this->service->book($this->stage, $this->date, [$this->hiace->id, $this->coaster->id]);

    expect($booking->status)->toBe(BusBooking::PENDING);
    expect($booking->fee_total)->toBe(250);
});

it('keeps the fee a stage was quoted even after the price changes', function () {
    $this->service->rule($this->stage, StageBusStanding::PREPAY);

    $booking = $this->service->book($this->stage, $this->date, [$this->hiace->id]);

    $this->hiace->update(['fee_amount' => 400]);

    expect($booking->buses()->first()->pivot->fee_amount)->toBe(100);
});

it('leaves a bus on offer while somebody is waiting to pay for it', function () {
    $this->service->rule($this->stage, StageBusStanding::PREPAY);
    $this->service->book($this->stage, $this->date, [$this->hiace->id]);

    $available = $this->service->availableBuses($this->date);

    expect($available->pluck('id'))->toContain($this->hiace->id);
    expect($available->firstWhere('id', $this->hiace->id)->pending_elsewhere)->toBeTrue();
});

it('takes a bus off the list once a booking is confirmed', function () {
    $this->service->book($this->stage, $this->date, [$this->hiace->id]);

    expect($this->service->availableBuses($this->date)->pluck('id'))->not->toContain($this->hiace->id);
});

it('refuses to book a bus another stage already holds', function () {
    $this->service->book($this->other, $this->date, [$this->hiace->id]);

    expect(fn () => $this->service->book($this->stage, $this->date, [$this->hiace->id]))
        ->toThrow(RuntimeException::class);
});

it('tells the officer who took the bus when a payment arrives too late', function () {
    $this->service->rule($this->stage, StageBusStanding::PREPAY);
    $pending = $this->service->book($this->stage, $this->date, [$this->hiace->id]);

    // A stage with nothing against it books the same bus meanwhile.
    $this->service->book($this->other, $this->date, [$this->hiace->id]);

    expect(fn () => $this->service->confirmPayment($pending->fresh()))
        ->toThrow(RuntimeException::class, 'هايس ١');

    expect($pending->fresh()->status)->toBe(BusBooking::PENDING);
});

it('confirms a payment when the bus is still free', function () {
    $this->service->rule($this->stage, StageBusStanding::PREPAY);
    $booking = $this->service->book($this->stage, $this->date, [$this->hiace->id]);

    $this->service->confirmPayment($booking);

    expect($booking->fresh()->status)->toBe(BusBooking::CONFIRMED);
    expect($booking->fresh()->fee_paid_at)->not->toBeNull();
    expect($this->service->availableBuses($this->date)->pluck('id'))->not->toContain($this->hiace->id);
});

it('only allows the weekdays the manager opened', function () {
    // 2026-09-20 is a Sunday; allow only Monday.
    BusBookingSettings::setWeekdays([2]);

    expect($this->service->isBookableDate('2026-09-20'))->toBeFalse();
    expect($this->service->isBookableDate('2026-09-21'))->toBeTrue();
});

it('allows every day when the manager set no restriction', function () {
    BusBookingSettings::setWeekdays([]);

    expect($this->service->isBookableDate('2026-09-20'))->toBeTrue();
});

it('refuses a day that has already gone', function () {
    expect($this->service->isBookableDate('2026-09-16'))->toBeFalse();
});

it('writes the checklist as it read on the day', function () {
    $clean = BusHandoverItem::create(['label' => 'الباص نظيف', 'position' => 1]);
    $fuel = BusHandoverItem::create(['label' => 'البنزين فل', 'position' => 2]);

    $booking = $this->service->book($this->stage, $this->date, [$this->hiace->id]);
    $this->service->receive($booking, [$clean->id]);

    expect($booking->fresh()->status)->toBe(BusBooking::RECEIVED);
    expect($this->service->failedItems($booking->fresh())->all())->toBe(['البنزين فل']);

    // Reworded afterwards, the ruling still reads as it was made.
    $fuel->update(['label' => 'خزان الوقد ممتلئ']);

    expect($this->service->failedItems($booking->fresh())->all())->toBe(['البنزين فل']);
});

it('lets a supervisor call off a booking until the lock closes', function () {
    Setting::setVal(BusBookingSettings::LOCK_DAYS, 1);

    $soon = $this->service->book($this->stage, '2026-09-18', [$this->hiace->id]);
    $later = $this->service->book($this->stage, '2026-09-20', [$this->coaster->id]);

    // Tomorrow is inside the lock; three days out is not.
    expect($soon->isCancellableBySupervisor())->toBeFalse();
    expect($later->isCancellableBySupervisor())->toBeTrue();
});

it('frees the bus again when a booking is cancelled', function () {
    $booking = $this->service->book($this->stage, $this->date, [$this->hiace->id]);

    $this->service->cancel($booking, 'officer');

    expect($this->service->availableBuses($this->date)->pluck('id'))->toContain($this->hiace->id);
});

it('opens the whole future when the week is not the limit', function () {
    Setting::setVal(BusBookingSettings::SAME_WEEK_ONLY, 0);

    [$from, $to] = $this->service->bookingWindow();

    expect($from)->toBe('2026-09-17');
    expect($to)->toBeNull();
    expect($this->service->isBookableDate('2027-01-01'))->toBeTrue();
});

it('confines booking to the week in progress, which turns over on Saturday', function () {
    Setting::setVal(BusBookingSettings::SAME_WEEK_ONLY, 1);

    // 2026-09-17 is a Thursday; its week began on Saturday the 12th and reaches
    // Saturday the 19th. The days already gone cannot be booked, so it opens today.
    [$from, $to] = $this->service->bookingWindow();

    expect($from)->toBe('2026-09-17');
    expect($to)->toBe('2026-09-19');

    expect($this->service->isBookableDate('2026-09-18'))->toBeTrue();
    // The Saturday the week closes on is itself bookable.
    expect($this->service->isBookableDate('2026-09-19'))->toBeTrue();
    expect($this->service->isBookableDate('2026-09-20'))->toBeFalse();
});

it('opens the week ahead on Saturday without opening that Saturday itself', function () {
    Carbon\Carbon::setTestNow('2026-09-19 08:00:00'); // Saturday
    Setting::setVal(BusBookingSettings::SAME_WEEK_ONLY, 1);

    [$from, $to] = $this->service->bookingWindow();

    // The morning booking opens is for the days after it, up to the next Saturday.
    expect($from)->toBe('2026-09-20');
    expect($to)->toBe('2026-09-26');

    expect($this->service->isBookableDate('2026-09-19'))->toBeFalse();
    expect($this->service->isBookableDate('2026-09-26'))->toBeTrue();
    expect($this->service->isBookableDate('2026-09-27'))->toBeFalse();
});

it('refuses a booking outside the week even when the weekday is allowed', function () {
    Carbon\Carbon::setTestNow('2026-09-19 08:00:00');
    Setting::setVal(BusBookingSettings::SAME_WEEK_ONLY, 1);
    BusBookingSettings::setWeekdays([]);

    expect(fn () => $this->service->book($this->stage, '2026-09-30', [$this->hiace->id]))
        ->toThrow(RuntimeException::class);
});
