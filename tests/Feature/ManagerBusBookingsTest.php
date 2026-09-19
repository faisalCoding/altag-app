<?php

use App\Livewire\Manager\BusBookings as Screen;
use App\Models\Bus;
use App\Models\BusBookingSettings;
use App\Models\BusHandoverItem;
use App\Models\Manager;
use App\Models\Setting;
use App\Models\Stage;
use App\Services\BusBookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon\Carbon::setTestNow('2026-09-17 09:00:00');
    $this->actingAs(Manager::factory()->create(), 'manager');
});

it('adds a bus with its fee', function () {
    Livewire::test(Screen::class)
        ->set('busName', 'هايس ١')
        ->set('busType', 'هايس')
        ->set('busFee', 100)
        ->call('saveBus')
        ->assertHasNoErrors();

    $bus = Bus::first();

    expect($bus->name)->toBe('هايس ١');
    expect($bus->fee_amount)->toBe(100);
    expect($bus->is_active)->toBeTrue();
});

it('will not add a bus with no name', function () {
    Livewire::test(Screen::class)
        ->set('busName', '')
        ->call('saveBus')
        ->assertHasErrors('busName');

    expect(Bus::count())->toBe(0);
});

it('edits a bus rather than adding a second one', function () {
    $bus = Bus::create(['name' => 'هايس ١', 'fee_amount' => 100]);

    Livewire::test(Screen::class)
        ->call('editBus', $bus->id)
        ->set('busFee', 120)
        ->call('saveBus');

    expect(Bus::count())->toBe(1);
    expect($bus->fresh()->fee_amount)->toBe(120);
});

it('retires a bus that has bookings instead of deleting it', function () {
    $bus = Bus::create(['name' => 'هايس ١', 'fee_amount' => 100]);
    $stage = Stage::factory()->create();
    (new BusBookingService)->book($stage, '2026-09-20', [$bus->id]);

    Livewire::test(Screen::class)->call('deleteBus', $bus->id);

    // Deleting would take the booking — and the record of it — with it.
    expect(Bus::find($bus->id))->not->toBeNull();
    expect($bus->fresh()->is_active)->toBeFalse();
});

it('deletes a bus nothing refers to', function () {
    $bus = Bus::create(['name' => 'هايس ١']);

    Livewire::test(Screen::class)->call('deleteBus', $bus->id);

    expect(Bus::find($bus->id))->toBeNull();
});

it('saves the settings the two public pages read', function () {
    Livewire::test(Screen::class)
        ->set('weekdays', [1, 3])
        ->set('lockDays', 2)
        ->set('officerPhone', '05 55 123 456')
        ->set('penaltyText', 'الهايس ١٠٠ ﷼')
        ->call('saveSettings')
        ->assertHasNoErrors();

    expect(BusBookingSettings::weekdays())->toBe([1, 3]);
    expect(BusBookingSettings::lockDays())->toBe(2);
    expect(BusBookingSettings::penaltyText())->toBe('الهايس ١٠٠ ﷼');
    // Stripped to digits, because it is handed straight to a wa.me link.
    expect(BusBookingSettings::officerPhone())->toBe('0555123456');
});

it('adds handover items in order', function () {
    $screen = Livewire::test(Screen::class);

    foreach (['البنزين فل', 'الباص نظيف'] as $label) {
        $screen->set('itemLabel', $label)->call('saveItem');
    }

    expect(BusHandoverItem::active()->pluck('label')->all())->toBe(['البنزين فل', 'الباص نظيف']);
});

it('moves an item up the list', function () {
    $first = BusHandoverItem::create(['label' => 'أول', 'position' => 0]);
    $second = BusHandoverItem::create(['label' => 'ثانٍ', 'position' => 1]);

    Livewire::test(Screen::class)->call('moveItem', $second->id, -1);

    expect(BusHandoverItem::active()->pluck('label')->all())->toBe(['ثانٍ', 'أول']);
});

it('does nothing when an item is moved off the end', function () {
    $only = BusHandoverItem::create(['label' => 'وحيد', 'position' => 0]);

    Livewire::test(Screen::class)->call('moveItem', $only->id, -1);

    expect(BusHandoverItem::active()->pluck('label')->all())->toBe(['وحيد']);
});

it('retires an item without disturbing rulings already made', function () {
    $item = BusHandoverItem::create(['label' => 'البنزين فل']);

    Livewire::test(Screen::class)->call('retireItem', $item->id);

    expect($item->fresh()->is_active)->toBeFalse();
    expect(BusHandoverItem::find($item->id))->not->toBeNull();
});

it('shows both links and mints them on first sight', function () {
    expect(Setting::where('key', BusBookingSettings::SUPERVISOR_TOKEN)->exists())->toBeFalse();

    Livewire::test(Screen::class)
        ->assertSee('/bus-booking/'.BusBookingSettings::supervisorToken())
        ->assertSee('/bus-officer/'.BusBookingSettings::officerToken());
});

it('retires the old link when one is regenerated', function () {
    $before = BusBookingSettings::supervisorToken();

    Livewire::test(Screen::class)->call('regenerateLink', 'supervisor');

    $after = BusBookingSettings::supervisorToken();

    expect($after)->not->toBe($before);
    expect(strlen($after))->toBe(32);
    // The officer's link is a separate key and must not move with it.
    expect(BusBookingSettings::officerToken())->not->toBe($after);
});

it('turns the same-week limit on and off', function () {
    Livewire::test(Screen::class)
        ->set('sameWeekOnly', true)
        ->call('saveSettings')
        ->assertHasNoErrors();

    expect(BusBookingSettings::sameWeekOnly())->toBeTrue();

    Livewire::test(Screen::class)
        ->set('sameWeekOnly', false)
        ->call('saveSettings');

    expect(BusBookingSettings::sameWeekOnly())->toBeFalse();
});

it('sets the weekday a prepaying stage must pay by', function () {
    // Wednesday until the manager says otherwise.
    expect(BusBookingSettings::feeDeadlineWeekday())->toBe(4);

    Livewire::test(Screen::class)
        ->set('feeDeadlineWeekday', 2)
        ->call('saveSettings')
        ->assertHasNoErrors();

    expect(BusBookingSettings::feeDeadlineWeekday())->toBe(2);
});

it('refuses a weekday that is not one', function () {
    Livewire::test(Screen::class)
        ->set('feeDeadlineWeekday', 9)
        ->call('saveSettings')
        ->assertHasErrors('feeDeadlineWeekday');
});

it('says nothing about conflicts while there are none', function () {
    $stage = Stage::factory()->create(['name' => 'الرواد']);
    $bus = Bus::create(['name' => 'هايس ١', 'fee_amount' => 0]);
    (new BusBookingService)->book($stage, '2026-09-20', [$bus->id]);

    Livewire::test(Screen::class)->assertDontSee('تعارض في الباصات');
});

it('puts a bus two stages hold on the same day in front of the manager', function () {
    $service = new BusBookingService;
    $mine = Stage::factory()->create(['name' => 'الرواد']);
    $theirs = Stage::factory()->create(['name' => 'السنابل']);

    $hiace = Bus::create(['name' => 'هايس ١', 'fee_amount' => 0]);
    $coaster = Bus::create(['name' => 'كوستر ١', 'fee_amount' => 0]);

    $service->book($mine, '2026-09-20', [$hiace->id]);
    $other = $service->book($theirs, '2026-09-20', [$coaster->id]);

    // Only reachable by writing past the service, which is the point: the panel
    // exists for a clash the guards were supposed to have made impossible.
    $other->buses()->attach($hiace->id, ['fee_amount' => 0]);

    Livewire::test(Screen::class)
        ->assertSee('تعارض في الباصات')
        ->assertSee('هايس ١')
        ->assertSee('الرواد')
        ->assertSee('السنابل');
});
