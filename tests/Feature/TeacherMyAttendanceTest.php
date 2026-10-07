<?php

use App\Livewire\Teacher\MyAttendance;
use App\Models\AcademicCalendarEvent;
use App\Models\Circle;
use App\Models\RoleScreenPermission;
use App\Models\Screen;
use App\Models\Stage;
use App\Models\Teacher;
use App\Models\TeacherAttendance;
use App\Support\HijriDate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon\Carbon::setTestNow('2026-09-17 09:00:00'); // A Thursday.

    $stage = Stage::factory()->create();
    $this->teacher = Teacher::factory()->create();
    $this->teacher->circles()->attach(Circle::factory()->create(['stage_id' => $stage->id])->id);

    TeacherAttendance::create(['teacher_id' => $this->teacher->id, 'stage_id' => $stage->id, 'date' => '2026-09-16', 'status' => 'absent', 'notes' => 'مراجعة المستشفى']);
    TeacherAttendance::create(['teacher_id' => $this->teacher->id, 'stage_id' => $stage->id, 'date' => '2026-09-17', 'status' => 'late', 'arrived_at' => '16:10:00']);

    $other = Teacher::factory()->create();
    TeacherAttendance::create(['teacher_id' => $other->id, 'stage_id' => $stage->id, 'date' => '2026-09-15', 'status' => 'excused', 'notes' => 'سبب معلم آخر']);

    $this->actingAs($this->teacher, 'teacher');
});

it('shows the teacher their own days, with the reason and the arrival time', function () {
    $page = Livewire::test(MyAttendance::class)
        ->assertSee('مراجعة المستشفى')
        ->assertSee('16:10')
        ->assertDontSee('سبب معلم آخر');

    expect($page->viewData('counts'))->toBe(['present' => 0, 'absent' => 1, 'late' => 1, 'excused' => 0]);
    expect($page->viewData('rate'))->toBe(50);
});

it('counts the working days nobody marked', function () {
    $page = Livewire::test(MyAttendance::class);

    $month = HijriDate::months('2026-09-17', 1)[0];
    $working = AcademicCalendarEvent::workingDaysBetween($month['first_day'], '2026-09-17', Stage::first()->id);

    expect($page->viewData('unrecorded'))->toBe(count($working) - 2);
});

it('walks back a month but never past the current one', function () {
    $current = HijriDate::months('2026-09-17', 1)[0]['first_day'];

    Livewire::test(MyAttendance::class)
        ->assertSet('month', $current)
        ->call('nextMonth')
        ->assertSet('month', $current)
        ->call('previousMonth')
        ->assertNotSet('month', $current)
        ->assertDontSee('مراجعة المستشفى')
        ->call('nextMonth')
        ->assertSet('month', $current);
});

it('opens from the teacher sidebar', function () {
    $this->get(route('teacher.my-attendance'))
        ->assertSuccessful()
        ->assertSee('سجل حضوري');
});

it('gives the app the same month', function () {
    Sanctum::actingAs($this->teacher);

    $response = $this->getJson('/api/v1/teacher/my-attendance')->assertSuccessful();

    expect($response->json('data.counts'))->toBe(['present' => 0, 'absent' => 1, 'late' => 1, 'excused' => 0]);
    expect($response->json('data.rate'))->toBe(50);
    expect($response->json('data.next'))->toBeNull();
    expect($response->json('data.days.0'))->toMatchArray([
        'date' => '2026-09-17',
        'status' => 'late',
        'arrived_at' => '16:10',
    ]);
    expect(collect($response->json('data.days'))->firstWhere('date', '2026-09-16'))->toMatchArray([
        'status' => 'absent',
        'notes' => 'مراجعة المستشفى',
    ]);

    $this->getJson('/api/v1/teacher/my-attendance?month='.$response->json('data.previous'))
        ->assertSuccessful()
        ->assertJsonPath('data.next', $response->json('data.month'));
});

it('refuses the app the record when the page is switched off for teachers', function () {
    $screen = Screen::where('route_name', 'teacher.my-attendance')->first();
    RoleScreenPermission::where('screen_id', $screen?->id)->delete();

    Sanctum::actingAs($this->teacher);

    $this->getJson('/api/v1/teacher/my-attendance')
        ->assertForbidden()
        ->assertJsonPath('code', 'page_disabled');
});
