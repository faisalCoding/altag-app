<?php

use App\Livewire\Supervisor\TeacherAttendance as Screen;
use App\Models\Circle;
use App\Models\Stage;
use App\Models\Supervisor;
use App\Models\Teacher;
use App\Models\TeacherAttendance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon\Carbon::setTestNow('2026-09-12 09:00:00');

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

    Livewire::test(Screen::class)
        ->assertSee('أستاذ أحمد')
        ->assertDontSee('أستاذ بعيد');
});

it('records a status for the chosen day', function () {
    Livewire::test(Screen::class)
        ->call('mark', $this->teacher->id, 'late');

    $record = TeacherAttendance::first();

    expect($record->status)->toBe('late');
    expect($record->teacher_id)->toBe($this->teacher->id);
    expect($record->date->format('Y-m-d'))->toBe('2026-09-12');
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
        ->set('date', '2026-09-13')
        ->call('mark', $this->teacher->id, 'present');

    expect(TeacherAttendance::count())->toBe(2);
});

it('reads back what was already marked when the day changes', function () {
    TeacherAttendance::create([
        'teacher_id' => $this->teacher->id,
        'date' => '2026-09-13',
        'status' => 'excused',
    ]);

    $component = Livewire::test(Screen::class)->set('date', '2026-09-13');

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
        ->set('date', '2026-09-13')
        ->call('mark', $this->teacher->id, 'absent')
        ->call('clearDay');

    expect(TeacherAttendance::count())->toBe(1);
    expect(TeacherAttendance::first()->date->format('Y-m-d'))->toBe('2026-09-12');
});

it('narrows the list by circle', function () {
    $otherCircle = Circle::factory()->create(['stage_id' => $this->stage->id]);
    $other = Teacher::factory()->create(['name' => 'أستاذ خالد']);
    $other->circles()->attach($otherCircle->id);

    Livewire::test(Screen::class)
        ->set('circleFilter', $otherCircle->id)
        ->assertSee('أستاذ خالد')
        ->assertDontSee('أستاذ أحمد');
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
        ->set('date', '2026-09-13')
        ->assertDispatched('teachersLoaded');
});
