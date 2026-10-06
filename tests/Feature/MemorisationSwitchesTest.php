<?php

use App\Livewire\Supervisor\Settings;
use App\Models\Circle;
use App\Models\HadithPath;
use App\Models\HadithText;
use App\Models\Ode;
use App\Models\OdePath;
use App\Models\Stage;
use App\Models\Student;
use App\Models\StudentHadithPlan;
use App\Models\StudentOdeAchievement;
use App\Models\StudentOdePlan;
use App\Models\Supervisor;
use App\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->stage = Stage::create(['name' => 'الثانوية']);
    $this->circle = Circle::create(['name' => 'حلقة النور', 'stage_id' => $this->stage->id]);

    $this->supervisor = Supervisor::factory()->create();
    $this->supervisor->stages()->attach($this->stage->id);

    $this->teacher = Teacher::factory()->create();
    $this->teacher->circles()->attach($this->circle->id);

    $this->student = Student::create([
        'name' => 'طالب المتون',
        'email' => 'mutun-switch@example.com',
        'password' => bcrypt('password'),
        'circle_id' => $this->circle->id,
        'is_approved' => true,
        'status' => 'active',
    ]);

    $this->odePath = OdePath::create([
        'ode_id' => Ode::create(['name' => 'تحفة الأطفال'])->id,
        'name' => 'مسار التحفة',
        'start_date' => '2026-07-01',
    ]);

    $this->hadithPath = HadithPath::create([
        'hadith_text_id' => HadithText::create(['name' => 'الأربعون النووية'])->id,
        'name' => 'مسار الأربعين',
        'memorize_type' => 'hadiths',
        'memorize_amount' => 1,
        'start_date' => '2026-07-01',
    ]);
});

function switchCard(Student $student)
{
    return Livewire::test('teacher.student-tasmeeh-card', [
        'student' => $student,
        'sPlans' => collect(),
        'activePlanId' => null,
        'gradedAtDate' => '2026-07-04',
    ]);
}

function enrol(Student $student, string $kind, $path): void
{
    $attributes = [
        'student_id' => $student->id,
        'start_date' => '2026-07-01',
        'status' => 'active',
        'created_by_role' => 'teacher',
    ];

    $kind === 'ode'
        ? StudentOdePlan::create($attributes + ['ode_path_id' => $path->id])
        : StudentHadithPlan::create($attributes + ['hadith_path_id' => $path->id]);
}

// ── المفتاحان ───────────────────────────────────────────────────────────────

it('starts with both on, so nothing changes until a supervisor decides', function () {
    expect($this->stage->fresh()->hadith_enabled)->toBeTrue();
    expect($this->stage->fresh()->odes_enabled)->toBeTrue();
    expect($this->student->memorisesHadith())->toBeTrue();
    expect($this->student->memorisesOdes())->toBeTrue();
});

it('lets a supervisor turn each one off for their stage, independently', function () {
    $this->actingAs($this->supervisor, 'supervisor');

    Livewire::test(Settings::class)->call('toggleOdes', $this->stage->id);

    // The odes went; the mutun did not — two switches, as asked.
    expect($this->stage->fresh()->odes_enabled)->toBeFalse();
    expect($this->stage->fresh()->hadith_enabled)->toBeTrue();

    Livewire::test(Settings::class)->call('toggleHadith', $this->stage->id);

    expect($this->stage->fresh()->hadith_enabled)->toBeFalse();
});

it('will not let a supervisor switch a stage they do not hold', function () {
    $other = Stage::create(['name' => 'مرحلة غيره']);
    $this->actingAs($this->supervisor, 'supervisor');

    Livewire::test(Settings::class)->call('toggleOdes', $other->id);

    // The id arrives from a browser like anything else.
    expect($other->fresh()->odes_enabled)->toBeTrue();
});

// ── من يُسأل ────────────────────────────────────────────────────────────────

it('reads the switch from the circle stage, which always wins', function () {
    $this->stage->update(['odes_enabled' => false]);

    expect($this->student->fresh()->memorisesOdes())->toBeFalse();
});

it('reads it from the student own stage when there is no circle', function () {
    $own = Stage::create(['name' => 'مرحلة بلا حلقة', 'hadith_enabled' => false]);
    $loose = Student::create([
        'name' => 'طالب بلا حلقة', 'email' => 'loose@example.com', 'password' => bcrypt('x'),
        'stage_id' => $own->id, 'is_approved' => true, 'status' => 'registering',
    ]);

    expect($loose->memorisesHadith())->toBeFalse();
});

it('keeps them for a student with no stage at all', function () {
    $nowhere = Student::create([
        'name' => 'طالب بلا مرحلة', 'email' => 'nowhere@example.com', 'password' => bcrypt('x'),
        'is_approved' => true, 'status' => 'registering',
    ]);

    // There is no one to have turned them off.
    expect($nowhere->memorisesHadith())->toBeTrue();
    expect($nowhere->memorisesOdes())->toBeTrue();
});

// ── عند المعلم ──────────────────────────────────────────────────────────────

it('takes the odes out of the teacher tasmeeh card when the stage hides them', function () {
    $this->actingAs($this->teacher, 'teacher');
    enrol($this->student, 'ode', $this->odePath);

    switchCard($this->student)->assertSee('مسار المنظومة');

    $this->stage->update(['odes_enabled' => false]);

    switchCard($this->student->fresh())
        ->assertDontSee('مسار المنظومة')
        ->assertDontSee('مسار التحفة')
        // The mutun stayed on, so they are still there.
        ->assertSee('مسار الحديث');
});

it('takes the mutun out of the teacher tasmeeh card when the stage hides them', function () {
    $this->actingAs($this->teacher, 'teacher');
    enrol($this->student, 'hadith', $this->hadithPath);

    $this->stage->update(['hadith_enabled' => false]);

    switchCard($this->student->fresh())
        ->assertDontSee('مسار الحديث')
        ->assertDontSee('مسار الأربعين')
        ->assertSee('مسار المنظومة');
});

it('refuses an ode grade sent to a stage that hides the odes', function () {
    $this->actingAs($this->teacher, 'teacher');
    enrol($this->student, 'ode', $this->odePath);
    $this->stage->update(['odes_enabled' => false]);

    // A hidden form is not a closed one: the write is refused on the server.
    switchCard($this->student->fresh())->call('saveOdeAchievement', 1, 'hifz', 3);

    expect(StudentOdeAchievement::count())->toBe(0);
});

it('refuses to enrol a student in a path the stage has hidden', function () {
    $this->actingAs($this->teacher, 'teacher');
    $this->stage->update(['odes_enabled' => false]);

    switchCard($this->student->fresh())
        ->call('openPathModal', 'ode')
        ->set('selectedPathId', $this->odePath->id)
        ->call('enrollInPath');

    expect(StudentOdePlan::count())->toBe(0);
});

// ── عند الطالب ووليّ الأمر ──────────────────────────────────────────────────

it('hides them from the student own dashboard', function () {
    enrol($this->student, 'ode', $this->odePath);
    $this->actingAs($this->student, 'student');

    Livewire::test('student.dashboard')->assertSee('خطط المنظومات');

    $this->stage->update(['odes_enabled' => false]);

    // A real request loads the student afresh; actingAs keeps the one instance,
    // whose circle and stage were already loaded by the render above.
    $this->actingAs($this->student->fresh(), 'student');

    Livewire::test('student.dashboard')->assertDontSee('خطط المنظومات');
});

it('hides them from the guardian, each on its own', function () {
    enrol($this->student, 'ode', $this->odePath);
    $render = fn () => Blade::render('<x-guardian.mutun-odes :student="$s" />', ['s' => $this->student->fresh()]);

    expect($render())->toContain('مسار التحفة');

    $this->stage->update(['odes_enabled' => false]);

    expect($render())->not->toContain('مسار التحفة');
});

it('keeps the plans, and shows them again when switched back on', function () {
    $this->actingAs($this->teacher, 'teacher');
    enrol($this->student, 'ode', $this->odePath);

    $this->stage->update(['odes_enabled' => false]);
    switchCard($this->student->fresh())->assertDontSee('مسار التحفة');

    // Nothing was deleted along the way.
    expect(StudentOdePlan::count())->toBe(1);

    $this->stage->update(['odes_enabled' => true]);
    switchCard($this->student->fresh())->assertSee('مسار التحفة');
});
