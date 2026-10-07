<?php

use App\Models\Circle;
use App\Models\GamificationTrack;
use App\Models\HadithPath;
use App\Models\HadithText;
use App\Models\Leaderboard;
use App\Models\Ode;
use App\Models\OdePath;
use App\Models\Stage;
use App\Models\Student;
use App\Models\StudentHadithPlan;
use App\Models\StudentOdePlan;
use App\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->stage = Stage::create(['name' => 'مرحلة التسكين']);
    $this->circle = Circle::create(['name' => 'حلقة التسكين', 'stage_id' => $this->stage->id]);

    $this->teacher = Teacher::factory()->create();
    $this->teacher->circles()->attach($this->circle->id);

    $this->student = Student::create([
        'name' => 'طالب التسكين',
        'email' => 'enroll-student@example.com',
        'password' => bcrypt('password'),
        'circle_id' => $this->circle->id,
        'is_approved' => true,
        'status' => 'active',
    ]);

    $this->hadithText = HadithText::create(['name' => 'متن التسكين']);
    $this->hadithPathA = HadithPath::create([
        'hadith_text_id' => $this->hadithText->id,
        'name' => 'مسار حديث أ',
        'memorize_type' => 'hadiths',
        'memorize_amount' => 1,
        'start_date' => '2026-07-01',
    ]);
    $this->hadithPathB = HadithPath::create([
        'hadith_text_id' => $this->hadithText->id,
        'name' => 'مسار حديث ب',
        'memorize_type' => 'hadiths',
        'memorize_amount' => 1,
        'start_date' => '2026-07-01',
    ]);

    $this->ode = Ode::create(['name' => 'منظومة التسكين']);
    $this->odePath = OdePath::create([
        'ode_id' => $this->ode->id,
        'name' => 'مسار منظومة أ',
        'start_date' => '2026-07-01',
    ]);
});

function tasmeehCard($student)
{
    return Livewire::test('teacher.student-tasmeeh-card', [
        'student' => $student,
        'sPlans' => collect(),
        'activePlanId' => null,
        'gradedAtDate' => '2026-07-04',
    ]);
}

it('shows the ode and hadith enrollment bars for a permitted teacher even without any plan', function () {
    $this->actingAs($this->teacher, 'teacher');

    tasmeehCard($this->student)
        ->assertSee('مسار المنظومة')
        ->assertSee('مسار الحديث')
        ->assertSee('غير مُسكَّن في مسار منظومة')
        ->assertSee('غير مُسكَّن في مسار حديث');
});

/**
 * The bar showed to every teacher who may place students in tracks, though
 * the competition running for the circle had none to place them in.
 */
it('offers the track bar only when a competition running for the circle has tracks', function () {
    $this->actingAs($this->teacher, 'teacher');

    $leaderboard = Leaderboard::create([
        'title' => 'مسابقة بلا مسارات',
        'circle_id' => $this->circle->id,
        'supervisor_id' => null,
        'competition_type' => 'gamification',
        'is_active' => true,
        'start_date' => now()->subDay()->toDateString(),
        'end_date' => null,
    ]);

    tasmeehCard($this->student)->assertDontSee('غير مُسكَّن في أي مسار تلعيب');

    GamificationTrack::create(['leaderboard_id' => $leaderboard->id, 'name' => 'مسار متقدم', 'sort_order' => 1]);

    tasmeehCard($this->student)->assertSee('غير مُسكَّن في أي مسار تلعيب');
});

it('hides the enrollment bars for a teacher lacking every management permission', function () {
    $this->teacher->update(['permissions' => [
        'can_manage_students' => true,
        'can_change_student_status' => true,
        'can_create_students' => true,
        'can_manage_hadith_paths' => false,
        'can_manage_ode_paths' => false,
        'can_manage_gamification_tracks' => false,
    ]]);
    $this->actingAs($this->teacher, 'teacher');

    tasmeehCard($this->student)
        ->assertDontSee('غير مُسكَّن في مسار منظومة')
        ->assertDontSee('غير مُسكَّن في مسار حديث')
        ->assertDontSee('غير مُسكَّن في أي مسار تلعيب');
});

it('lets a permitted teacher enroll a student into a hadith path', function () {
    $this->actingAs($this->teacher, 'teacher');

    tasmeehCard($this->student)
        ->call('openPathModal', 'hadith')
        ->set('selectedPathId', $this->hadithPathA->id)
        ->call('enrollInPath')
        ->assertHasNoErrors();

    $plan = StudentHadithPlan::where('student_id', $this->student->id)->where('status', 'active')->first();
    expect($plan)->not->toBeNull();
    expect($plan->hadith_path_id)->toBe($this->hadithPathA->id);
    expect($plan->created_by_role)->toBe('teacher');
});

it('switches the hadith path and suspends the previous active plan', function () {
    $this->actingAs($this->teacher, 'teacher');

    StudentHadithPlan::create([
        'student_id' => $this->student->id,
        'hadith_path_id' => $this->hadithPathA->id,
        'start_date' => '2026-07-01',
        'status' => 'active',
        'created_by_role' => 'supervisor',
    ]);

    tasmeehCard($this->student)
        ->call('openPathModal', 'hadith')
        ->set('selectedPathId', $this->hadithPathB->id)
        ->call('enrollInPath')
        ->assertHasNoErrors();

    expect(StudentHadithPlan::where('student_id', $this->student->id)->where('status', 'active')->count())->toBe(1);
    expect(StudentHadithPlan::where('student_id', $this->student->id)->where('hadith_path_id', $this->hadithPathA->id)->first()->status)->toBe('suspended');
    expect(StudentHadithPlan::where('student_id', $this->student->id)->where('hadith_path_id', $this->hadithPathB->id)->first()->status)->toBe('active');
});

it('lets a permitted teacher enroll a student into an ode path', function () {
    $this->actingAs($this->teacher, 'teacher');

    tasmeehCard($this->student)
        ->call('openPathModal', 'ode')
        ->set('selectedPathId', $this->odePath->id)
        ->call('enrollInPath')
        ->assertHasNoErrors();

    $plan = StudentOdePlan::where('student_id', $this->student->id)->where('status', 'active')->first();
    expect($plan)->not->toBeNull();
    expect($plan->ode_path_id)->toBe($this->odePath->id);
    expect($plan->created_by_role)->toBe('teacher');
});

it('blocks hadith enrollment when the teacher lacks the permission', function () {
    $this->teacher->update(['permissions' => [
        'can_manage_students' => true,
        'can_change_student_status' => true,
        'can_create_students' => true,
        'can_manage_hadith_paths' => false,
        'can_manage_ode_paths' => true,
        'can_manage_gamification_tracks' => true,
    ]]);
    $this->actingAs($this->teacher, 'teacher');

    tasmeehCard($this->student)
        ->set('pathModalType', 'hadith')
        ->set('selectedPathId', $this->hadithPathA->id)
        ->call('enrollInPath');

    expect(StudentHadithPlan::where('student_id', $this->student->id)->count())->toBe(0);
});

it('lets a permitted teacher enroll a student into a gamification track, one per competition', function () {
    $this->actingAs($this->teacher, 'teacher');

    $leaderboard = Leaderboard::create([
        'title' => 'مسابقة التسكين',
        'circle_id' => $this->circle->id,
        'supervisor_id' => null,
        'competition_type' => 'gamification',
        'is_active' => true,
        'start_date' => now()->subDay()->toDateString(),
        'end_date' => null,
    ]);
    $trackA = GamificationTrack::create(['leaderboard_id' => $leaderboard->id, 'name' => 'مسار متقدم', 'sort_order' => 1]);
    $trackB = GamificationTrack::create(['leaderboard_id' => $leaderboard->id, 'name' => 'مسار مبتدئ', 'sort_order' => 2]);

    $component = tasmeehCard($this->student)
        ->call('openTrackModal')
        ->set("trackSelections.{$leaderboard->id}", $trackA->id)
        ->call('saveTrackEnrollment')
        ->assertHasNoErrors();

    expect(DB::table('gamification_track_student')->where('student_id', $this->student->id)->pluck('track_id')->all())
        ->toBe([$trackA->id]);

    // Switching to another track in the same competition replaces the first.
    $component->call('openTrackModal')
        ->set("trackSelections.{$leaderboard->id}", $trackB->id)
        ->call('saveTrackEnrollment');

    expect(DB::table('gamification_track_student')->where('student_id', $this->student->id)->pluck('track_id')->all())
        ->toBe([$trackB->id]);
});

it('blocks track enrollment when the teacher lacks the permission', function () {
    $this->teacher->update(['permissions' => [
        'can_manage_students' => true,
        'can_change_student_status' => true,
        'can_create_students' => true,
        'can_manage_hadith_paths' => true,
        'can_manage_ode_paths' => true,
        'can_manage_gamification_tracks' => false,
    ]]);
    $this->actingAs($this->teacher, 'teacher');

    $leaderboard = Leaderboard::create([
        'title' => 'مسابقة التسكين',
        'circle_id' => $this->circle->id,
        'supervisor_id' => null,
        'competition_type' => 'gamification',
        'is_active' => true,
        'start_date' => now()->subDay()->toDateString(),
        'end_date' => null,
    ]);
    $track = GamificationTrack::create(['leaderboard_id' => $leaderboard->id, 'name' => 'مسار', 'sort_order' => 1]);

    tasmeehCard($this->student)
        ->set('availableCompetitions', [[
            'id' => $leaderboard->id,
            'title' => $leaderboard->title,
            'tracks' => [['id' => $track->id, 'name' => $track->name]],
        ]])
        ->set("trackSelections.{$leaderboard->id}", $track->id)
        ->call('saveTrackEnrollment');

    expect(DB::table('gamification_track_student')->where('student_id', $this->student->id)->count())->toBe(0);
});
