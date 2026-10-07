<?php

use App\Jobs\SendGuardianWhatsappJob;
use App\Livewire\Manager\Settings;
use App\Livewire\Shared\TeacherAttendance as Screen;
use App\Livewire\Shared\TeacherAttendanceReport as Report;
use App\Models\Circle;
use App\Models\Manager;
use App\Models\Setting;
use App\Models\Stage;
use App\Models\Supervisor;
use App\Models\Teacher;
use App\Models\TeacherAttendance;
use App\Models\TeacherAttendanceRevision;
use App\Support\TeacherAttendanceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon\Carbon::setTestNow('2026-09-17 09:00:00'); // A Thursday.

    $this->stage = Stage::factory()->create();
    $circle = Circle::factory()->create(['stage_id' => $this->stage->id]);

    $this->supervisor = Supervisor::factory()->create();
    $this->supervisor->stages()->attach($this->stage->id);

    $this->teacher = Teacher::factory()->create(['name' => 'أستاذ أحمد', 'phone' => '0551234567']);
    $this->teacher->circles()->attach($circle->id);

    $this->cover = Teacher::factory()->create(['name' => 'أستاذ خالد']);
    $this->cover->circles()->attach($circle->id);

    $this->actingAs($this->supervisor, 'supervisor');
});

// ─────────── Old days locked ───────────

it('locks a supervisor out of days past the window', function () {
    Livewire::test(Screen::class)
        ->set('date', '2026-09-09') // Eight days back: past the default seven.
        ->assertSet('locked', true)
        ->assertSee('هذا اليوم مقفل')
        ->call('mark', $this->teacher->id, 'absent')
        ->call('markRemainingPresent');

    expect(TeacherAttendance::count())->toBe(0);
});

it('keeps the last days open to the supervisor', function () {
    Livewire::test(Screen::class)
        ->set('date', '2026-09-10') // Seven days back.
        ->assertSet('locked', false)
        ->call('mark', $this->teacher->id, 'absent');

    expect(TeacherAttendance::count())->toBe(1);
});

it('keeps old days in the manager hands', function () {
    $this->actingAs(Manager::factory()->create(), 'manager');

    Livewire::test(Screen::class, ['role' => 'manager'])
        ->set('date', '2026-08-31')
        ->assertSet('locked', false)
        ->call('mark', $this->teacher->id, 'absent');

    expect(TeacherAttendance::count())->toBe(1);
});

it('leaves every day open when the manager sets no window', function () {
    Setting::setVal('teacher_attendance_lock_days', 0);

    Livewire::test(Screen::class)
        ->set('date', '2026-08-31')
        ->call('mark', $this->teacher->id, 'absent');

    expect(TeacherAttendance::count())->toBe(1);
});

it('keeps a locked day from being cleared', function () {
    TeacherAttendance::create(['teacher_id' => $this->teacher->id, 'stage_id' => $this->stage->id, 'date' => '2026-09-01', 'status' => 'absent']);

    Livewire::test(Screen::class)->set('date', '2026-09-01')->call('clearDay');

    expect(TeacherAttendance::count())->toBe(1);
});

// ─────────── Substitute ───────────

it('records who covered for an absent teacher', function () {
    Livewire::test(Screen::class)
        ->call('mark', $this->teacher->id, 'absent')
        ->call('saveNote', $this->teacher->id, 'مريض', null, $this->cover->id)
        ->assertSee('البديل: أستاذ خالد');

    expect(TeacherAttendance::first()->substitute_teacher_id)->toBe($this->cover->id);

    $line = TeacherAttendanceRevision::latest('id')->first();
    expect($line->substituteChanged())->toBeTrue();
    expect($line->new_substitute_id)->toBe($this->cover->id);
});

it('refuses a substitute outside the roll call, or the teacher themselves', function () {
    $stranger = Teacher::factory()->create();
    $stranger->circles()->attach(Circle::factory()->create(['stage_id' => Stage::factory()->create()->id])->id);

    Livewire::test(Screen::class)
        ->call('mark', $this->teacher->id, 'absent')
        ->call('saveNote', $this->teacher->id, '', null, $stranger->id)
        ->call('saveNote', $this->teacher->id, '', null, $this->teacher->id);

    expect(TeacherAttendance::first()->substitute_teacher_id)->toBeNull();
});

it('drops the substitute once the teacher is no longer away', function () {
    Livewire::test(Screen::class)
        ->call('mark', $this->teacher->id, 'excused')
        ->call('saveNote', $this->teacher->id, '', null, $this->cover->id)
        ->call('mark', $this->teacher->id, 'present');

    expect(TeacherAttendance::first()->substitute_teacher_id)->toBeNull();
});

it('names the substitute in the report', function () {
    TeacherAttendance::create([
        'teacher_id' => $this->teacher->id,
        'stage_id' => $this->stage->id,
        'date' => '2026-09-16',
        'status' => 'absent',
        'substitute_teacher_id' => $this->cover->id,
    ]);

    Livewire::test(Report::class, ['role' => 'supervisor'])
        ->assertSee('البديل: أستاذ خالد');
});

// ─────────── WhatsApp notice ───────────

it('sends nothing until the manager turns the notice on', function () {
    Queue::fake();

    Livewire::test(Screen::class)->call('mark', $this->teacher->id, 'absent');

    Queue::assertNotPushed(SendGuardianWhatsappJob::class);
});

it('tells a teacher marked absent, from the account of whoever marked them', function () {
    Queue::fake();
    Setting::setVal('teacher_attendance_whatsapp', 1);

    Livewire::test(Screen::class)->call('mark', $this->teacher->id, 'absent');

    Queue::assertPushed(SendGuardianWhatsappJob::class, fn (SendGuardianWhatsappJob $job) => $job->phone === '0551234567'
        && $job->senderClientId === 'supervisor_'.$this->supervisor->id
        && str_contains($job->message, 'سُجِّل غيابك'));
});

it('sends nothing for a present teacher, or a teacher with no number', function () {
    Queue::fake();
    Setting::setVal('teacher_attendance_whatsapp', 1);

    Livewire::test(Screen::class)
        ->call('mark', $this->teacher->id, 'present')
        ->call('mark', $this->cover->id, 'absent');

    Queue::assertNotPushed(SendGuardianWhatsappJob::class);
});

// ─────────── Settings ───────────

it('lets the manager set the window and the notice', function () {
    $this->actingAs(Manager::factory()->create(), 'manager');

    Livewire::test(Settings::class)
        ->assertSet('teacherLockDays', 7)
        ->assertSet('teacherWhatsapp', false)
        ->set('teacherLockDays', 3)
        ->set('teacherWhatsapp', true)
        ->call('saveTeacherAttendance')
        ->assertHasNoErrors();

    expect(TeacherAttendanceSettings::lockDays())->toBe(3);
    expect(TeacherAttendanceSettings::notifiesTeachers())->toBeTrue();
});
