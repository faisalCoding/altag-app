<?php

use App\Livewire\Supervisor\Settings;
use App\Models\Circle;
use App\Models\CircleTurn;
use App\Models\Stage;
use App\Models\Student;
use App\Models\Supervisor;
use App\Models\Teacher;
use App\Services\TurnBooking;
use App\Support\TurnBookingWindow;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-07-08 13:30:00'); // Wednesday, 16:30 in Riyadh.

    $this->stage = Stage::create(['name' => 'المتوسطة']);
    $this->otherStage = Stage::create(['name' => 'الثانوية']);
    $this->circle = Circle::create(['name' => 'حلقة الفجر', 'stage_id' => $this->stage->id]);

    $this->supervisor = Supervisor::factory()->create();
    $this->supervisor->stages()->attach($this->stage->id);

    $this->teacher = Teacher::factory()->create();
    $this->teacher->circles()->attach($this->circle->id);

    $this->student = Student::factory()->create(['circle_id' => $this->circle->id, 'status' => 'active', 'is_approved' => true]);
});

function bookingSettings()
{
    test()->actingAs(test()->supervisor, 'supervisor');

    return Livewire::test(Settings::class);
}

it('sets one booking window for every stage the supervisor holds, and no other', function () {
    $second = Stage::create(['name' => 'الابتدائية']);
    $this->supervisor->stages()->attach($second->id);

    bookingSettings()
        ->set('turnBookingEnabled', true)
        ->set('turnBookingDays', ['0', '3', '1'])
        ->set('turnBookingStartsAt', '16:00')
        ->set('turnBookingEndsAt', '17:30')
        ->call('saveTurnBooking')
        ->assertHasNoErrors()
        ->assertSet('turnBookingDays', ['0', '1', '3']);

    foreach ([$this->stage, $second] as $stage) {
        $stage->refresh();

        expect($stage->turn_booking_enabled)->toBeTrue()
            ->and($stage->turn_booking_days)->toBe([0, 1, 3])
            ->and([$stage->turn_booking_starts_at, $stage->turn_booking_ends_at])->toBe(['16:00', '17:30']);
    }

    expect($this->otherStage->fresh()->turn_booking_enabled)->toBeFalse();
});

it('shows the supervisor the window already set', function () {
    $this->stage->update([
        'turn_booking_enabled' => true, 'turn_booking_days' => [2, 4],
        'turn_booking_starts_at' => '15:00', 'turn_booking_ends_at' => '15:45',
    ]);

    bookingSettings()
        ->assertSet('turnBookingEnabled', true)
        ->assertSet('turnBookingDays', ['2', '4'])
        ->assertSet('turnBookingStartsAt', '15:00')
        ->assertSet('turnBookingEndsAt', '15:45');
});

it('refuses a window that ends before it starts, or opens on no day', function () {
    bookingSettings()
        ->set('turnBookingEnabled', true)
        ->set('turnBookingDays', [])
        ->set('turnBookingStartsAt', '18:00')
        ->set('turnBookingEndsAt', '17:00')
        ->call('saveTurnBooking')
        ->assertHasErrors(['turnBookingDays', 'turnBookingEndsAt']);

    expect($this->stage->fresh()->turn_booking_enabled)->toBeFalse();
});

it('opens on the days and between the hours set, in Riyadh time', function () {
    $this->stage->update([
        'turn_booking_enabled' => true, 'turn_booking_days' => [3],
        'turn_booking_starts_at' => '16:00', 'turn_booking_ends_at' => '17:00',
    ]);

    $window = TurnBookingWindow::forStage($this->stage->fresh());

    expect($window->opensToday())->toBeTrue()
        ->and($window->isOpenNow())->toBeTrue()
        ->and($window->hours())->toBe('4:00 م - 5:00 م');

    Carbon::setTestNow('2026-07-08 14:00:00'); // 17:00, the window's end.
    expect($window->isOpenNow())->toBeFalse();

    Carbon::setTestNow('2026-07-09 13:30:00'); // Thursday.
    expect($window->opensToday())->toBeFalse();

    $this->stage->update(['turn_booking_enabled' => false]);
    expect(TurnBookingWindow::forStage($this->stage->fresh()))->toBeNull();
});

it('lets a student give a turn back only while booking is open', function () {
    $this->stage->update([
        'turn_booking_enabled' => true, 'turn_booking_days' => [3],
        'turn_booking_starts_at' => '16:00', 'turn_booking_ends_at' => '17:00',
    ]);

    expect(TurnBooking::reserve($this->student)[0])->toBe('booked');

    Carbon::setTestNow('2026-07-08 14:30:00'); // 17:30, closed.

    expect(TurnBooking::cancel($this->student))->toBeFalse()
        ->and(TurnBooking::reserve($this->student)[0])->toBe('closed')
        ->and(CircleTurn::count())->toBe(1);
});

it('has no queue for a student outside any circle', function () {
    openTurnBooking($this->circle);
    $loose = Student::factory()->create(['circle_id' => null, 'stage_id' => $this->stage->id]);

    expect(TurnBooking::windowFor($loose))->toBeNull()
        ->and(TurnBooking::reserve($loose)[0])->toBe('closed');
});

it('sends the app the turns of the teacher\'s circles, and none of another circle', function () {
    $other = Circle::create(['name' => 'حلقة أخرى', 'stage_id' => $this->stage->id]);
    $stranger = Student::factory()->create(['circle_id' => $other->id]);
    $today = now('Asia/Riyadh')->toDateString();

    CircleTurn::create(['circle_id' => $this->circle->id, 'student_id' => $this->student->id, 'date' => $today, 'turn_number' => 3]);
    CircleTurn::create(['circle_id' => $other->id, 'student_id' => $stranger->id, 'date' => $today, 'turn_number' => 1]);

    $token = $this->teacher->createToken('phone')->plainTextToken;

    $this->withToken($token)->getJson('/api/v1/teacher/sync')
        ->assertSuccessful()
        ->assertJsonCount(1, 'data.turns')
        ->assertJsonPath('data.turns.0', [
            'circle_id' => $this->circle->id,
            'student_id' => $this->student->id,
            'date' => $today,
            'number' => 3,
        ]);
});
