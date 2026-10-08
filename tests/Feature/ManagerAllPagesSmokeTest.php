<?php

use App\Models\Circle;
use App\Models\ExamLevel;
use App\Models\Guardian;
use App\Models\Manager;
use App\Models\Stage;
use App\Models\Student;
use App\Models\StudentExam;
use App\Models\Supervisor;
use App\Models\Task;
use App\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Smoke-tests every manager-facing page: logs in as a manager with a
 * realistic dataset seeded, then hits every named `manager.*` GET route and
 * asserts it renders successfully (or redirects where that is the correct
 * behavior), catching any real breakage across the whole role.
 */
it('loads every manager page without error', function () {
    $manager = Manager::factory()->create();

    $stage = Stage::create(['name' => 'المرحلة الأولى']);
    $circle = Circle::create(['name' => 'حلقة النور', 'stage_id' => $stage->id]);

    $teacher = Teacher::factory()->create(['is_approved' => true]);
    $teacher->circles()->attach($circle->id);

    Supervisor::factory()->create(['is_approved' => true]);
    Guardian::factory()->create(['is_approved' => true]);

    $student = Student::factory()->create(['circle_id' => $circle->id, 'is_approved' => true]);

    $examLevel = ExamLevel::create(['name' => 'مستوى أول']);
    StudentExam::create([
        'student_id' => $student->id,
        'exam_level_id' => $examLevel->id,
        'date_time' => now()->addDay(),
        'status' => 'pending',
    ]);

    Task::create([
        'title' => 'مهمة تجريبية',
        'created_by_type' => Manager::class,
        'created_by_id' => $manager->id,
        'assigned_to_type' => Manager::class,
        'assigned_to_id' => $manager->id,
    ]);

    $this->actingAs($manager, 'manager');

    $today = now()->format('Y-m-d');

    $simpleRoutes = [
        'manager.dashboard',
        'manager.academic-calendar',
        'manager.ai-analysis',
        'manager.api-docs',
        'manager.attendance-reports',
        'manager.circles',
        'manager.exam-levels',
        'manager.exceeded-limits',
        'manager.guardians',
        'manager.messages',
        'manager.pending-approvals',
        'manager.quran-editor',
        'manager.quranic-achievement',
        'manager.role-permissions',
        'manager.settings',
        'manager.stages',
        'manager.student-exams',
        'manager.students',
        'manager.supervisors',
        'manager.tasks',
        'manager.teachers',
        'manager.whatsapp-settings',
        'manager.yearly-attendance',
    ];

    foreach ($simpleRoutes as $routeName) {
        $this->get(route($routeName))->assertSuccessful();
    }

    $this->get(route('manager.attendance-list', ['circleId' => $circle->id, 'date' => $today]))->assertSuccessful();

    // Non-existent backup file must 404 cleanly, not crash with a 500.
    $this->get(route('manager.backup-browser', ['filename' => 'does-not-exist.sqlite']))->assertNotFound();
});
