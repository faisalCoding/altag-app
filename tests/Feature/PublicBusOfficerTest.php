<?php

use App\Livewire\Public\BusOfficer as Officer;
use App\Models\Bus;
use App\Models\BusBooking;
use App\Models\BusBookingSettings;
use App\Models\BusHandoverItem;
use App\Models\Stage;
use App\Models\StageBusStanding;
use App\Services\BusBookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon\Carbon::setTestNow('2026-09-17 09:00:00');

    $this->stage = Stage::factory()->create(['name' => 'الرواد']);
    $this->bus = Bus::create(['name' => 'هايس ١', 'fee_amount' => 100]);

    $this->fuel = BusHandoverItem::create(['label' => 'البنزين فل', 'position' => 0]);
    $this->clean = BusHandoverItem::create(['label' => 'الباص نظيف', 'position' => 1]);

    $this->token = BusBookingSettings::officerToken();
    $this->service = new BusBookingService;
});

it('refuses a link that is not the officer one', function () {
    Livewire::test(Officer::class, ['token' => 'nope'])->assertStatus(404);
    Livewire::test(Officer::class, ['token' => BusBookingSettings::supervisorToken()])->assertStatus(404);
});

it('stops working the moment the link is regenerated', function () {
    $old = $this->token;
    BusBookingSettings::regenerate(BusBookingSettings::OFFICER_TOKEN);

    Livewire::test(Officer::class, ['token' => $old])->assertStatus(404);
});

it('offers receiving only once the day has come', function () {
    $future = $this->service->book($this->stage, '2026-09-20', [$this->bus->id]);
    $today = $this->service->book(Stage::factory()->create(), '2026-09-17', [Bus::create(['name' => 'كوستر'])->id]);

    $component = Livewire::test(Officer::class, ['token' => $this->token]);

    expect($component->instance()->canReceive($future))->toBeFalse();
    expect($component->instance()->canReceive($today))->toBeTrue();
});

it('records the checklist in two steps', function () {
    $booking = $this->service->book($this->stage, '2026-09-17', [$this->bus->id]);

    Livewire::test(Officer::class, ['token' => $this->token])
        // Step one opens the list with nothing ticked.
        ->call('startReceive', $booking->id)
        ->assertSet('receivingId', $booking->id)
        ->assertSet('doneItems', [])
        // Step two ticks what was actually done.
        ->set('doneItems', [$this->fuel->id])
        ->call('saveReceive');

    $booking->refresh();

    expect($booking->status)->toBe(BusBooking::RECEIVED);
    expect($booking->checks()->where('is_done', true)->pluck('label')->all())->toBe(['البنزين فل']);
    expect($booking->checks()->where('is_done', false)->pluck('label')->all())->toBe(['الباص نظيف']);
});

it('opens the ruling with the reason already written when something failed', function () {
    $booking = $this->service->book($this->stage, '2026-09-17', [$this->bus->id]);

    Livewire::test(Officer::class, ['token' => $this->token])
        ->call('startReceive', $booking->id)
        ->set('doneItems', [$this->fuel->id])
        ->call('saveReceive')
        ->assertSet('rulingBookingId', $booking->id)
        ->assertSet('rulingStanding', StageBusStanding::PREPAY)
        ->assertSet('rulingReason', 'لم يُنجَز: الباص نظيف');
});

it('asks for no ruling when everything was done', function () {
    $booking = $this->service->book($this->stage, '2026-09-17', [$this->bus->id]);

    Livewire::test(Officer::class, ['token' => $this->token])
        ->call('startReceive', $booking->id)
        ->set('doneItems', [$this->fuel->id, $this->clean->id])
        ->call('saveReceive')
        ->assertSet('rulingBookingId', null);

    expect(StageBusStanding::count())->toBe(0);
});

it('puts a stage on prepayment, and the next booking waits for the fee', function () {
    $booking = $this->service->book($this->stage, '2026-09-17', [$this->bus->id]);

    Livewire::test(Officer::class, ['token' => $this->token])
        ->call('startReceive', $booking->id)
        ->set('doneItems', [])
        ->call('saveReceive')
        ->call('saveRuling');

    expect($this->service->standingFor($this->stage))->toBe(StageBusStanding::PREPAY);

    $next = $this->service->book($this->stage, '2026-09-20', [$this->bus->id]);

    expect($next->status)->toBe(BusBooking::PENDING);
    expect($next->fee_total)->toBe(100);
});

it('lets the officer overlook a violation without punishing it', function () {
    $booking = $this->service->book($this->stage, '2026-09-17', [$this->bus->id]);

    Livewire::test(Officer::class, ['token' => $this->token])
        ->call('startReceive', $booking->id)
        ->set('doneItems', [])
        ->call('saveReceive')
        ->call('overlook')
        ->assertSet('rulingBookingId', null);

    // The failure stays on the record; only the punishment is declined.
    expect(StageBusStanding::count())->toBe(0);
    expect($booking->fresh()->checks()->where('is_done', false)->count())->toBe(2);
});

it('rules on a stage without any booking in front of it', function () {
    Livewire::test(Officer::class, ['token' => $this->token])
        ->call('openRuling', $this->stage->id)
        ->set('rulingStanding', StageBusStanding::BANNED)
        ->set('rulingReason', 'تكرار المخالفة')
        ->call('saveRuling');

    expect($this->service->standingFor($this->stage))->toBe(StageBusStanding::BANNED);
    expect($this->service->latestRulingFor($this->stage)->reason)->toBe('تكرار المخالفة');
});

it('lifts a ban again', function () {
    $this->service->rule($this->stage, StageBusStanding::BANNED, 'قديم');

    Livewire::test(Officer::class, ['token' => $this->token])
        ->call('openRuling', $this->stage->id)
        ->set('rulingStanding', StageBusStanding::OK)
        ->set('rulingReason', 'سُوِّيت')
        ->call('saveRuling');

    expect($this->service->standingFor($this->stage))->toBe(StageBusStanding::OK);
});

it('confirms a booking once the fee is in hand', function () {
    $this->service->rule($this->stage, StageBusStanding::PREPAY);
    $booking = $this->service->book($this->stage, '2026-09-20', [$this->bus->id]);

    Livewire::test(Officer::class, ['token' => $this->token])->call('markPaid', $booking->id);

    expect($booking->fresh()->status)->toBe(BusBooking::CONFIRMED);
    expect($booking->fresh()->fee_paid_at)->not->toBeNull();
});

it('will not confirm a payment for a bus somebody else now holds', function () {
    $this->service->rule($this->stage, StageBusStanding::PREPAY);
    $pending = $this->service->book($this->stage, '2026-09-20', [$this->bus->id]);
    $this->service->book(Stage::factory()->create(), '2026-09-20', [$this->bus->id]);

    Livewire::test(Officer::class, ['token' => $this->token])->call('markPaid', $pending->id);

    expect($pending->fresh()->status)->toBe(BusBooking::PENDING);
});

it('cancels a booking the supervisor no longer can', function () {
    // Tomorrow, so the supervisor's own lock has closed.
    $booking = $this->service->book($this->stage, '2026-09-18', [$this->bus->id]);

    expect($booking->isCancellableBySupervisor())->toBeFalse();

    Livewire::test(Officer::class, ['token' => $this->token])->call('cancel', $booking->id);

    expect($booking->fresh()->status)->toBe(BusBooking::CANCELLED);
});

it('shows what is waiting and hides what is not', function () {
    $this->service->book($this->stage, '2026-09-17', [$this->bus->id]);
    $later = Stage::factory()->create(['name' => 'السنابل']);
    $this->service->book($later, '2026-09-25', [Bus::create(['name' => 'كوستر ٢'])->id]);

    // Asserted on the buses, not the stages: every stage is listed at the top of
    // the page whatever the filter, so its name proves nothing about the rows.
    Livewire::test(Officer::class, ['token' => $this->token])
        ->assertSee('هايس ١')
        ->assertDontSee('كوستر ٢')
        ->call('setFilter', 'upcoming')
        ->assertSee('كوستر ٢')
        ->assertDontSee('هايس ١');
});
