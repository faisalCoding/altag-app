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

it('holds the bus for a prepaying stage from the moment it books', function () {
    $this->service->rule($this->stage, StageBusStanding::PREPAY);
    $booking = $this->service->book($this->stage, $this->date, [$this->hiace->id]);

    expect($booking->status)->toBe(BusBooking::PENDING);
    expect($booking->holdsBuses())->toBeTrue();
    expect($this->service->availableBuses($this->date)->pluck('id'))->not->toContain($this->hiace->id);

    // And it is the holding that matters, not the paying: nobody may book over it.
    expect(fn () => $this->service->book($this->other, $this->date, [$this->hiace->id]))
        ->toThrow(RuntimeException::class);
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

it('dates the fee to the managers weekday inside the week of the trip', function () {
    // Wednesday by default. 2026-09-26 is the Saturday closing the week that
    // opened on the 19th, so its Wednesday is the 23rd.
    expect($this->service->paymentDeadline('2026-09-26'))->toBe('2026-09-23');

    // A trip earlier than that Wednesday is its own deadline — no fee falls due
    // after the bus has already gone out.
    expect($this->service->paymentDeadline('2026-09-20'))->toBe('2026-09-20');

    // The manager may move the day.
    Setting::setVal(BusBookingSettings::FEE_DEADLINE_WEEKDAY, 2); // الاثنين
    expect($this->service->paymentDeadline('2026-09-26'))->toBe('2026-09-21');
});

it('stamps the deadline on the booking so moving the setting cannot move it', function () {
    $this->service->rule($this->stage, StageBusStanding::PREPAY);
    $booking = $this->service->book($this->stage, '2026-09-26', [$this->hiace->id]);

    expect($booking->fee_due_on->format('Y-m-d'))->toBe('2026-09-23');

    Setting::setVal(BusBookingSettings::FEE_DEADLINE_WEEKDAY, 1); // الأحد

    expect($booking->fresh()->fee_due_on->format('Y-m-d'))->toBe('2026-09-23');
});

it('frees the bus again when the deadline goes by unpaid', function () {
    $this->service->rule($this->stage, StageBusStanding::PREPAY);
    $pending = $this->service->book($this->stage, '2026-09-26', [$this->hiace->id]);

    // The Thursday after the Wednesday it was due.
    Carbon\Carbon::setTestNow('2026-09-24 09:00:00');

    expect($pending->fresh()->hasLapsed())->toBeTrue();
    expect($pending->fresh()->holdsBuses())->toBeFalse();
    expect($this->service->availableBuses('2026-09-26')->pluck('id'))->toContain($this->hiace->id);

    // Another stage takes it, and the late payment is refused.
    $this->service->book($this->other, '2026-09-26', [$this->hiace->id]);

    expect(fn () => $this->service->confirmPayment($pending->fresh()))
        ->toThrow(RuntimeException::class, 'انقضى موعد دفع هذا الحجز');
});

it('cancels what lapsed and leaves alone what has not', function () {
    $this->service->rule($this->stage, StageBusStanding::PREPAY);
    $this->service->rule($this->other, StageBusStanding::PREPAY);

    $lapsing = $this->service->book($this->stage, '2026-09-26', [$this->hiace->id]);   // due the 23rd
    $living = $this->service->book($this->other, '2026-09-26', [$this->coaster->id]);

    Carbon\Carbon::setTestNow('2026-09-23 09:00:00'); // the day it is due, not past it
    expect($this->service->expireUnpaid())->toBe(0);

    $this->service->confirmPayment($living->fresh());

    Carbon\Carbon::setTestNow('2026-09-24 09:00:00');
    expect($this->service->expireUnpaid())->toBe(1);

    expect($lapsing->fresh()->status)->toBe(BusBooking::CANCELLED);
    expect($lapsing->fresh()->cancelled_by)->toBe('system');
    expect($living->fresh()->status)->toBe(BusBooking::CONFIRMED);
});

it('finds nothing to report when every bus is held once', function () {
    $this->service->book($this->stage, $this->date, [$this->hiace->id]);
    $this->service->book($this->other, $this->date, [$this->coaster->id]);

    expect($this->service->allConflicts())->toBeEmpty();
});

it('reports a bus two stages ended up holding on the same day', function () {
    $mine = $this->service->book($this->stage, $this->date, [$this->hiace->id]);
    $theirs = $this->service->book($this->other, $this->date, [$this->coaster->id]);

    // Forced past the guards, the way a clash could only ever arise: by writing
    // straight to the pivot rather than through the service.
    $theirs->buses()->attach($this->hiace->id, ['fee_amount' => 0]);

    $conflicts = $this->service->allConflicts();

    expect($conflicts)->toHaveCount(1);
    expect($conflicts->first()['bus']->id)->toBe($this->hiace->id);
    expect($conflicts->first()['date'])->toBe($this->date);
    expect($conflicts->first()['bookings']->pluck('id'))->toContain($mine->id, $theirs->id);

    // And the booking itself knows it is not alone.
    expect($this->service->conflictsFor($mine->fresh())->pluck('id'))->toContain($this->hiace->id);
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

it('has a command the scheduler runs to close out what lapsed', function () {
    $this->service->rule($this->stage, StageBusStanding::PREPAY);
    $lapsing = $this->service->book($this->stage, '2026-09-26', [$this->hiace->id]);

    Carbon\Carbon::setTestNow('2026-09-24 09:00:00');

    $this->artisan('bus:expire-unpaid')
        ->expectsOutputToContain('أُلغي')
        ->assertSuccessful();

    expect($lapsing->fresh()->status)->toBe(BusBooking::CANCELLED);
});
