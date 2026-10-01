<?php

use App\Livewire\Supervisor\ManageHadithPaths;
use App\Models\Circle;
use App\Models\HadithPath;
use App\Models\HadithPathDay;
use App\Models\HadithText;
use App\Models\Stage;
use App\Models\Student;
use App\Models\StudentHadithPlan;
use App\Models\Supervisor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->stage = Stage::create(['name' => 'مرحلة المتون']);
    $this->circle1 = Circle::create(['name' => 'حلقة أ', 'stage_id' => $this->stage->id]);
    $this->circle2 = Circle::create(['name' => 'حلقة ب', 'stage_id' => $this->stage->id]);

    $this->supervisor = Supervisor::factory()->create();
    $this->supervisor->stages()->attach($this->stage->id);

    $this->student1 = Student::factory()->create(['circle_id' => $this->circle1->id, 'name' => 'أحمد']);
    $this->student2 = Student::factory()->create(['circle_id' => $this->circle1->id, 'name' => 'محمد']);
    $this->student3 = Student::factory()->create(['circle_id' => $this->circle2->id, 'name' => 'خالد']);

    $this->hadithText = HadithText::create(['name' => 'متن الجزرية']);
    $this->hadithPath = HadithPath::create([
        'hadith_text_id' => $this->hadithText->id,
        'name' => 'مسار الجزرية',
        'memorize_type' => 'lines',
        'memorize_amount' => 2,
        'start_date' => '2026-06-18',
    ]);

    // Create a path day template
    HadithPathDay::create([
        'hadith_path_id' => $this->hadithPath->id,
        'day_number' => 1,
        'date' => '2026-06-18',
        'day_name' => 'Sunday',
        'memorize_type' => 'lines',
        'memorize_amount' => 2,
        'from_line_number' => 1,
        'to_line_number' => 2,
    ]);
});

it('can toggle selection of all students', function () {
    $this->actingAs($this->supervisor, 'supervisor');

    $allIds = [$this->student1->id, $this->student2->id, $this->student3->id];

    Livewire::test(ManageHadithPaths::class)
        ->assertSet('selectedStudentIds', [])
        ->call('toggleSelectAll', $allIds)
        ->assertSet('selectedStudentIds', array_map('strval', $allIds))
        ->call('toggleSelectAll', $allIds)
        ->assertSet('selectedStudentIds', []);
});

it('can toggle selection of students in a single circle', function () {
    $this->actingAs($this->supervisor, 'supervisor');

    $circle1Ids = [$this->student1->id, $this->student2->id];

    Livewire::test(ManageHadithPaths::class)
        ->assertSet('selectedStudentIds', [])
        ->call('toggleSelectCircle', $circle1Ids)
        ->assertSet('selectedStudentIds', array_map('strval', $circle1Ids))
        ->call('toggleSelectCircle', $circle1Ids)
        ->assertSet('selectedStudentIds', []);
});

it('can enroll selected students in the path', function () {
    $this->actingAs($this->supervisor, 'supervisor');

    $selected = [$this->student1->id, $this->student3->id];

    Livewire::test(ManageHadithPaths::class)
        ->call('showEnrollModal', $this->hadithPath->id)
        ->set('selectedStudentIds', array_map('strval', $selected))
        ->call('enrollStudents')
        ->assertHasNoErrors();

    // Verify students are enrolled
    expect(StudentHadithPlan::where('hadith_path_id', $this->hadithPath->id)->count())->toBe(2);
    expect(StudentHadithPlan::where('student_id', $this->student1->id)->exists())->toBeTrue();
    expect(StudentHadithPlan::where('student_id', $this->student3->id)->exists())->toBeTrue();
    expect(StudentHadithPlan::where('student_id', $this->student2->id)->exists())->toBeFalse();
});

it('preloads enrolled students and preserves plan or suspends them accordingly', function () {
    $this->actingAs($this->supervisor, 'supervisor');

    // Create an active plan for student1
    $student1Plan = StudentHadithPlan::create([
        'student_id' => $this->student1->id,
        'hadith_path_id' => $this->hadithPath->id,
        'start_date' => '2026-06-18',
        'status' => 'active',
        'created_by_role' => 'supervisor',
    ]);

    // Test component preloads student1
    Livewire::test(ManageHadithPaths::class)
        ->call('showEnrollModal', $this->hadithPath->id)
        ->assertSet('selectedStudentIds', [(string) $this->student1->id])
        // Change selection to student2 only (unselecting student1, selecting student2)
        ->set('selectedStudentIds', [(string) $this->student2->id])
        ->call('enrollStudents')
        ->assertHasNoErrors();

    // Verify student1 plan is suspended (not deleted)
    expect($student1Plan->fresh()->status)->toBe('suspended');

    // Verify student2 has a new active plan
    expect(StudentHadithPlan::where('student_id', $this->student2->id)
        ->where('hadith_path_id', $this->hadithPath->id)
        ->where('status', 'active')
        ->exists())->toBeTrue();
});
