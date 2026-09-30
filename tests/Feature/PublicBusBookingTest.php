<?php

use App\Livewire\Public\BusBooking as Wizard;
use App\Models\Bus;
use App\Models\BusBooking;
use App\Models\BusBookingSettings;
use App\Models\BusHandoverItem;
use App\Models\Setting;
use App\Models\Stage;
use App\Models\StageBusStanding;
use App\Services\BusBookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon\Carbon::setTestNow('2026-09-17 09:00:00');

    $this->stage = Stage::factory()->create(['name' => 'الرواد']);
    $this->hiace = Bus::create(['name' => 'هايس ١', 'type' => 'هايس', 'fee_amount' => 100]);
    $this->coaster = Bus::create(['name' => 'كوستر ١', 'type' => 'كوستر', 'fee_amount' => 150]);

    BusHandoverItem::create(['label' => 'البنزين فل', 'position' => 0]);

    $this->token = BusBookingSettings::supervisorToken();
    $this->date = '2026-09-20';
    $this->service = new BusBookingService;
});

it('refuses a link that is not the supervisors one', function () {
    Livewire::test(Wizard::class, ['token' => 'wrong-token'])->assertStatus(404);
});

it('refuses the officer link', function () {
    Livewire::test(Wizard::class, ['token' => BusBookingSettings::officerToken()])->assertStatus(404);
});

it('stops working the moment the link is regenerated', function () {
    $old = $this->token;
    BusBookingSettings::regenerate(BusBookingSettings::SUPERVISOR_TOKEN);

    Livewire::test(Wizard::class, ['token' => $old])->assertStatus(404);
});

it('walks a clear stage all the way to a booking', function () {
    Livewire::test(Wizard::class, ['token' => $this->token])
        ->call('chooseStage', $this->stage->id)
        ->assertSet('step', 2)
        ->call('startDate')
        ->assertSet('step', 3)
        ->set('date', $this->date)
        ->call('chooseDate')
        ->assertSet('step', 4)
        ->set('busIds', [$this->hiace->id])
        ->call('chooseBuses')
        ->assertSet('step', 5)
        ->set('agreed', true)
        ->call('confirm')
        ->assertSet('step', 6)
        ->assertSee('تم تأكيد الحجز')
        ->assertSee('لا تعارض مع أي مرحلة أخرى في هذا اليوم.')
        ->assertSee('هايس ١')
        ->assertSee('العودة إلى حالة المرحلة');

    $booking = BusBooking::first();

    expect($booking->stage_id)->toBe($this->stage->id);
    expect($booking->status)->toBe(BusBooking::CONFIRMED);
    expect($booking->buses)->toHaveCount(1);
});

it('returns from the success screen to the stages standing', function () {
    Livewire::test(Wizard::class, ['token' => $this->token])
        ->call('chooseStage', $this->stage->id)
        ->call('startDate')
        ->set('date', $this->date)
        ->call('chooseDate')
        ->set('busIds', [$this->hiace->id])
        ->call('chooseBuses')
        ->set('agreed', true)
        ->call('confirm')
        ->call('backToStatus')
        ->assertSet('step', 2)
        ->assertSet('bookedId', null)
        ->assertSet('date', '')
        ->assertSet('agreed', false)
        // The booking it just made is listed there.
        ->assertSee('حجوزات هذه المرحلة');
});

it('shows a prepaying stage that the buses are held and when the fee is due', function () {
    $this->service->rule($this->stage, StageBusStanding::PREPAY);

    Livewire::test(Wizard::class, ['token' => $this->token])
        ->call('chooseStage', $this->stage->id)
        ->call('startDate')
        ->set('date', '2026-09-26')
        ->call('chooseDate')
        ->set('busIds', [$this->hiace->id])
        ->call('chooseBuses')
        // Spelled out before agreeing, not after.
        ->assertSee('أُلغي الحجز تلقائياً')
        ->set('agreed', true)
        ->call('confirm')
        ->assertSet('step', 6)
        ->assertSee('حُجزت الباصات لمرحلتكم')
        ->assertSee('وإلا أُلغي الحجز وعادت الباصات للجميع.');

    $booking = BusBooking::first();

    expect($booking->status)->toBe(BusBooking::PENDING);
    expect($booking->holdsBuses())->toBeTrue();
    expect($booking->fee_due_on->format('Y-m-d'))->toBe('2026-09-23');
});

it('refuses to call a booking done when its buses are held elsewhere', function () {
    $other = Stage::factory()->create(['name' => 'السنابل']);

    $page = Livewire::test(Wizard::class, ['token' => $this->token])
        ->call('chooseStage', $this->stage->id)
        ->call('startDate')
        ->set('date', $this->date)
        ->call('chooseDate')
        ->set('busIds', [$this->hiace->id])
        ->call('chooseBuses')
        ->set('agreed', true);

    // Taken between the last step opening and the tap on «تأكيد».
    $this->service->book($other, $this->date, [$this->hiace->id]);

    $page->call('confirm')
        ->assertSet('step', 3)
        ->assertSet('bookedId', null)
        ->assertDontSee('تم تأكيد الحجز');

    // And nothing of this stage's was written.
    expect(BusBooking::where('stage_id', $this->stage->id)->count())->toBe(0);
});

it('stops a banned stage at the second step and offers the officer', function () {
    $this->service->rule($this->stage, StageBusStanding::BANNED, 'تكرار المخالفة');
    Setting::setVal(BusBookingSettings::OFFICER_PHONE, '966555123456');

    Livewire::test(Wizard::class, ['token' => $this->token])
        ->call('chooseStage', $this->stage->id)
        ->assertSee('محرومة')
        ->assertSee('تكرار المخالفة')
        ->assertSee('wa.me/966555123456', false)
        // The next step refuses to open however it is asked for.
        ->call('startDate')
        ->assertSet('step', 2);
});

it('tells a prepaying stage what it owes before it confirms', function () {
    $this->service->rule($this->stage, StageBusStanding::PREPAY, 'رجع الباص غير نظيف');

    Livewire::test(Wizard::class, ['token' => $this->token])
        ->call('chooseStage', $this->stage->id)
        ->assertSee('رسوم تُدفع مقدماً')
        ->call('startDate')
        ->set('date', $this->date)
        ->call('chooseDate')
        ->set('busIds', [$this->hiace->id, $this->coaster->id])
        ->call('chooseBuses')
        // 100 + 150, each bus counted — shown in Arabic-Indic digits, as the
        // Hijri dates beside it are.
        ->assertSee('٢٥٠')
        ->set('agreed', true)
        ->call('confirm');

    expect(BusBooking::first()->status)->toBe(BusBooking::PENDING);
    expect(BusBooking::first()->fee_total)->toBe(250);
});

it('will not confirm without the declaration', function () {
    Livewire::test(Wizard::class, ['token' => $this->token])
        ->call('chooseStage', $this->stage->id)
        ->call('startDate')
        ->set('date', $this->date)
        ->call('chooseDate')
        ->set('busIds', [$this->hiace->id])
        ->call('chooseBuses')
        ->call('confirm')
        ->assertHasErrors('agreed');

    expect(BusBooking::count())->toBe(0);
});

it('refuses a day the manager did not open', function () {
    // 2026-09-20 is a Sunday; only Monday is allowed.
    BusBookingSettings::setWeekdays([2]);

    Livewire::test(Wizard::class, ['token' => $this->token])
        ->call('chooseStage', $this->stage->id)
        ->call('startDate')
        ->set('date', $this->date)
        ->call('chooseDate')
        ->assertHasErrors('date')
        ->assertSet('step', 3);
});

it('will not move on without picking a bus', function () {
    Livewire::test(Wizard::class, ['token' => $this->token])
        ->call('chooseStage', $this->stage->id)
        ->call('startDate')
        ->set('date', $this->date)
        ->call('chooseDate')
        ->call('chooseBuses')
        ->assertHasErrors('busIds')
        ->assertSet('step', 4);
});

it('cannot be jumped forward past a step it has not satisfied', function () {
    Livewire::test(Wizard::class, ['token' => $this->token])
        ->call('chooseStage', $this->stage->id)
        ->call('toStep', 5)
        ->assertSet('step', 2);
});

it('catches a bus taken while the steps were open', function () {
    $component = Livewire::test(Wizard::class, ['token' => $this->token])
        ->call('chooseStage', $this->stage->id)
        ->call('startDate')
        ->set('date', $this->date)
        ->call('chooseDate')
        ->set('busIds', [$this->hiace->id])
        ->call('chooseBuses');

    // Somebody else books it while this page sits on the last step.
    $this->service->book(Stage::factory()->create(), $this->date, [$this->hiace->id]);

    $component->set('agreed', true)->call('confirm');

    expect(BusBooking::where('stage_id', $this->stage->id)->count())->toBe(0);
});

it('does not offer a bus a prepaying stage is holding', function () {
    $prepaying = Stage::factory()->create(['name' => 'السنابل']);
    $this->service->rule($prepaying, StageBusStanding::PREPAY);
    $this->service->book($prepaying, $this->date, [$this->hiace->id]);

    Livewire::test(Wizard::class, ['token' => $this->token])
        ->call('chooseStage', $this->stage->id)
        ->call('startDate')
        ->set('date', $this->date)
        ->call('chooseDate')
        // Theirs until their deadline, so it is not on the list at all.
        ->assertDontSee('هايس ١')
        ->assertSee('كوستر ١');
});

it('lets a stage call off its own booking while the lock allows', function () {
    $booking = $this->service->book($this->stage, $this->date, [$this->hiace->id]);

    Livewire::test(Wizard::class, ['token' => $this->token])
        ->call('chooseStage', $this->stage->id)
        ->call('cancel', $booking->id);

    expect($booking->fresh()->status)->toBe(BusBooking::CANCELLED);
});

it('refuses to cancel once the booking is fixed', function () {
    $booking = $this->service->book($this->stage, '2026-09-18', [$this->hiace->id]);

    Livewire::test(Wizard::class, ['token' => $this->token])
        ->call('chooseStage', $this->stage->id)
        ->call('cancel', $booking->id);

    expect($booking->fresh()->status)->toBe(BusBooking::CONFIRMED);
});

it('refuses to cancel another stage booking', function () {
    $other = Stage::factory()->create();
    $booking = $this->service->book($other, $this->date, [$this->hiace->id]);

    Livewire::test(Wizard::class, ['token' => $this->token])
        ->call('chooseStage', $this->stage->id)
        ->call('cancel', $booking->id);

    expect($booking->fresh()->status)->toBe(BusBooking::CONFIRMED);
});

it('hands the picker the same rule the service enforces', function () {
    BusBookingSettings::setWeekdays([1, 3]);
    Setting::setVal(BusBookingSettings::SAME_WEEK_ONLY, 1);

    // Thursday: the week it belongs to closes on Saturday the 19th.
    Livewire::test(Wizard::class, ['token' => $this->token])
        ->call('chooseStage', $this->stage->id)
        ->call('startDate')
        ->assertViewHas('windowFrom', '2026-09-17')
        ->assertViewHas('windowTo', '2026-09-19')
        ->assertViewHas('weekdays', [1, 3])
        ->assertSee('الحجز متاح من');
});

it('says nothing about a week when booking is not confined to one', function () {
    Livewire::test(Wizard::class, ['token' => $this->token])
        ->call('chooseStage', $this->stage->id)
        ->call('startDate')
        ->assertViewHas('windowTo', null)
        ->assertDontSee('الحجز متاح من');
});

it('still refuses a day outside the week if one is submitted anyway', function () {
    Setting::setVal(BusBookingSettings::SAME_WEEK_ONLY, 1);

    Livewire::test(Wizard::class, ['token' => $this->token])
        ->call('chooseStage', $this->stage->id)
        ->call('startDate')
        // The picker would not offer it; the step refuses it regardless.
        ->set('date', '2026-10-05')
        ->call('chooseDate')
        ->assertHasErrors('date')
        ->assertSet('step', 3);
});

it('tells the stage when its own unpaid booking has lapsed', function () {
    $this->service->rule($this->stage, StageBusStanding::PREPAY);
    $this->service->book($this->stage, '2026-09-26', [$this->hiace->id]);

    Livewire::test(Wizard::class, ['token' => $this->token])
        ->call('chooseStage', $this->stage->id)
        ->assertSee('بانتظار');

    // Past the Wednesday it was due, and before the nightly sweep has run.
    Carbon\Carbon::setTestNow('2026-09-24 09:00:00');

    Livewire::test(Wizard::class, ['token' => $this->token])
        ->call('chooseStage', $this->stage->id)
        ->assertSee('انقضى موعد الدفع')
        ->assertDontSee('بانتظار');
});
