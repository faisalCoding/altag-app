<?php

use App\Livewire\Shared\TeacherAttendance as Screen;
use App\Models\AcademicCalendarEvent;
use App\Models\Circle;
use App\Models\Stage;
use App\Models\Supervisor;
use App\Models\Teacher;
use App\Models\TeacherAttendance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon\Carbon::setTestNow('2026-09-14 09:00:00');

    $this->stage = Stage::factory()->create();
    $this->circle = Circle::factory()->create(['stage_id' => $this->stage->id]);

    $this->supervisor = Supervisor::factory()->create();
    $this->supervisor->stages()->attach($this->stage->id);

    $this->teacher = Teacher::factory()->create(['name' => 'أستاذ أحمد']);
    $this->teacher->circles()->attach($this->circle->id);

    $this->actingAs($this->supervisor, 'supervisor');
});

it('lists only the teachers of the supervisor own stages', function () {
    $outsider = Teacher::factory()->create(['name' => 'أستاذ بعيد']);
    $outsider->circles()->attach(Circle::factory()->create(['stage_id' => Stage::factory()->create()->id])->id);

    // By the roll itself: every teacher of the academy is offered as a
    // substitute, so the other stage's name is on the page all the same.
    Livewire::test(Screen::class)
        ->assertSee('أستاذ أحمد')
        ->assertSet('teacherOrder', [$this->teacher->id]);
});

it('records a status for the chosen day', function () {
    Livewire::test(Screen::class)
        ->call('mark', $this->teacher->id, 'late');

    $record = TeacherAttendance::first();

    expect($record->status)->toBe('late');
    expect($record->teacher_id)->toBe($this->teacher->id);
    expect($record->date->format('Y-m-d'))->toBe('2026-09-14');
    expect($record->recorded_by_id)->toBe($this->supervisor->id);
});

it('overwrites rather than stacking a second record for the same day', function () {
    Livewire::test(Screen::class)
        ->call('mark', $this->teacher->id, 'absent')
        ->call('mark', $this->teacher->id, 'present');

    expect(TeacherAttendance::count())->toBe(1);
    expect(TeacherAttendance::first()->status)->toBe('present');
});

it('keeps each day separate', function () {
    Livewire::test(Screen::class)
        ->call('mark', $this->teacher->id, 'absent')
        ->set('date', '2026-09-10')
        ->call('mark', $this->teacher->id, 'present');

    expect(TeacherAttendance::count())->toBe(2);
});

it('reads back what was already marked when the day changes', function () {
    TeacherAttendance::create([
        'teacher_id' => $this->teacher->id,
        'date' => '2026-09-10',
        'status' => 'excused',
    ]);

    $component = Livewire::test(Screen::class)->set('date', '2026-09-10');

    expect($component->get('records')[$this->teacher->id])->toBe('excused');
});

it('refuses a status it does not recognise', function () {
    Livewire::test(Screen::class)
        ->call('mark', $this->teacher->id, 'holiday');

    expect(TeacherAttendance::count())->toBe(0);
});

it('refuses a teacher outside the supervisor stages', function () {
    $outsider = Teacher::factory()->create();
    $outsider->circles()->attach(Circle::factory()->create(['stage_id' => Stage::factory()->create()->id])->id);

    Livewire::test(Screen::class)
        ->call('mark', $outsider->id, 'present');

    expect(TeacherAttendance::count())->toBe(0);
});

it('marks everyone still unmarked as present without touching the rest', function () {
    $second = Teacher::factory()->create();
    $second->circles()->attach($this->circle->id);

    Livewire::test(Screen::class)
        ->call('mark', $this->teacher->id, 'absent')
        ->call('markRemainingPresent');

    expect(TeacherAttendance::where('teacher_id', $this->teacher->id)->value('status'))->toBe('absent');
    expect(TeacherAttendance::where('teacher_id', $second->id)->value('status'))->toBe('present');
});

it('clears only the chosen day', function () {
    Livewire::test(Screen::class)
        ->call('mark', $this->teacher->id, 'present')
        ->set('date', '2026-09-10')
        ->call('mark', $this->teacher->id, 'absent')
        ->call('clearDay');

    expect(TeacherAttendance::count())->toBe(1);
    expect(TeacherAttendance::first()->date->format('Y-m-d'))->toBe('2026-09-14');
});

it('narrows the list by circle', function () {
    $otherCircle = Circle::factory()->create(['stage_id' => $this->stage->id]);
    $other = Teacher::factory()->create(['name' => 'أستاذ خالد']);
    $other->circles()->attach($otherCircle->id);

    // By the roll itself: every teacher of the stages still shows among the
    // substitutes an absent teacher's row offers.
    Livewire::test(Screen::class)
        ->set('circleFilter', $otherCircle->id)
        ->assertSee('أستاذ خالد')
        ->assertSet('teacherOrder', [$other->id]);
});

it('hands the search to the browser rather than to the server', function () {
    $other = Teacher::factory()->create(['name' => 'أستاذ خالد']);
    $other->circles()->attach($this->circle->id);

    $html = Livewire::test(Screen::class)->html();

    // Both are in the markup and the page filters between them itself; a round
    // trip per keystroke is what this screen was rebuilt to stop doing.
    expect($html)->toContain('أستاذ خالد')->toContain('أستاذ أحمد')
        ->toContain('x-model="search"')
        ->toContain('isVisible(');
});

it('gives the browser the order it needs to walk the list', function () {
    $other = Teacher::factory()->create(['name' => 'أستاذ خالد']);
    $other->circles()->attach($this->circle->id);

    $page = Livewire::test(Screen::class);

    // Marking advances to the next unmarked teacher without asking the server
    // where that is, so the order has to travel with the page.
    expect($page->get('teacherOrder'))->toHaveCount(2)
        ->toContain($this->teacher->id, $other->id);
});

it('starts the walk over when the day or the circle changes', function () {
    Livewire::test(Screen::class)
        ->set('date', '2026-09-10')
        ->assertDispatched('teachersLoaded');
});

it('turns a day that has not come yet back to today', function () {
    Livewire::test(Screen::class)
        ->set('date', '2026-09-15')
        ->assertSet('date', '2026-09-14')
        ->call('mark', $this->teacher->id, 'present');

    expect(TeacherAttendance::first()->date->format('Y-m-d'))->toBe('2026-09-14');
});

it('turns a malformed day back to today', function () {
    Livewire::test(Screen::class)
        ->set('date', 'not-a-day')
        ->assertSet('date', '2026-09-14')
        ->assertOk();
});

it('lists nobody on a weekend and refuses to mark them', function () {
    Livewire::test(Screen::class)
        ->set('date', '2026-09-12') // A Saturday.
        ->assertSet('teacherOrder', [])
        ->assertSee('يوم إجازة')
        ->call('mark', $this->teacher->id, 'present')
        ->call('markRemainingPresent');

    expect(TeacherAttendance::count())->toBe(0);
});

it('lists nobody on a day the calendar closes', function () {
    AcademicCalendarEvent::create([
        'event_name' => 'فترة دوام الحلقات',
        'start_date' => '2026-09-01',
        'end_date' => '2026-09-30',
        'is_attendance_period' => true,
        'weekdays' => [1, 2, 3, 4, 5],
        'excluded_dates' => ['2026-09-10'],
        'is_visible' => true,
    ]);
    AcademicCalendarEvent::forgetPeriodCache();

    Livewire::test(Screen::class)
        ->set('date', '2026-09-10')
        ->assertSet('teacherOrder', [])
        ->call('mark', $this->teacher->id, 'present');

    expect(TeacherAttendance::count())->toBe(0);
});

it('marks only the teachers the search leaves showing', function () {
    $hidden = Teacher::factory()->create(['name' => 'أستاذ خالد']);
    $hidden->circles()->attach($this->circle->id);

    Livewire::test(Screen::class)
        ->call('markRemainingPresent', [$this->teacher->id]);

    expect(TeacherAttendance::where('teacher_id', $this->teacher->id)->value('status'))->toBe('present');
    expect(TeacherAttendance::where('teacher_id', $hidden->id)->exists())->toBeFalse();
});

it('leaves a mark made elsewhere since the page opened', function () {
    $page = Livewire::test(Screen::class);

    TeacherAttendance::create([
        'teacher_id' => $this->teacher->id,
        'date' => '2026-09-14',
        'status' => 'absent',
    ]);

    $page->call('markRemainingPresent');

    expect(TeacherAttendance::count())->toBe(1);
    expect(TeacherAttendance::first()->status)->toBe('absent');
    expect($page->get('records')[$this->teacher->id])->toBe('absent');
});

it('files the day under the stage of the teacher', function () {
    Livewire::test(Screen::class)->call('mark', $this->teacher->id, 'present');

    expect(TeacherAttendance::first()->stage_id)->toBe($this->stage->id);
});

it('keeps a teacher who moved stages on the days taken under this one', function () {
    TeacherAttendance::create([
        'teacher_id' => $this->teacher->id,
        'stage_id' => $this->stage->id,
        'date' => '2026-09-10',
        'status' => 'late',
    ]);

    $this->teacher->circles()->sync([Circle::factory()->create(['stage_id' => Stage::factory()->create()->id])->id]);

    $page = Livewire::test(Screen::class)->assertSet('teacherOrder', []);

    $page->set('date', '2026-09-10')
        ->assertSet('teacherOrder', [$this->teacher->id])
        ->call('mark', $this->teacher->id, 'present');

    $record = TeacherAttendance::first();
    expect($record->status)->toBe('present');
    expect($record->stage_id)->toBe($this->stage->id);
});

it('names the circle when clearing only its teachers', function () {
    $this->circle->update(['name' => 'حلقة الفجر']);

    Livewire::test(Screen::class)
        ->assertSee('حذف تحضير هذا اليوم كاملاً؟')
        ->set('circleFilter', $this->circle->id)
        ->assertSee('حذف تحضير معلمي حلقة حلقة الفجر في هذا اليوم؟');
});

it('will not open as the manager roll call for a supervisor', function () {
    Livewire::test(Screen::class, ['role' => 'manager'])->assertForbidden();
});
