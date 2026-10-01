<?php

use App\Models\AppNotification;
use App\Models\Ayah;
use App\Models\Circle;
use App\Models\Conversation;
use App\Models\ExamLevel;
use App\Models\Manager;
use App\Models\Stage;
use App\Models\Student;
use App\Models\StudentExam;
use App\Models\StudentPlan;
use App\Models\StudentPlanDay;
use App\Models\Surah;
use App\Models\Teacher;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Surah::create([
        'id' => 1, 'number' => 1, 'name_arabic' => 'الفاتحة', 'name_simple' => 'Al-Fatihah',
        'revelation_place' => 'makkah', 'revelation_order' => 1, 'verses_count' => 7,
        'start_page' => 1, 'end_page' => 1,
    ]);
    Ayah::create([
        'id' => 1, 'surah_id' => 1, 'verse_number' => 1, 'page_number' => 1,
        'line_number_start' => 1, 'line_number_end' => 1, 'verse_key' => '1:1',
        'juz_number' => 1, 'hizb_number' => 1, 'rub_number' => 1, 'ruku_number' => 1,
        'manzil_number' => 1, 'text_uthmani' => 'بسم الله',
    ]);

    $this->stage = Stage::create(['name' => 'المرحلة الأولى']);
    $this->circle = Circle::create(['name' => 'حلقة النور', 'stage_id' => $this->stage->id]);

    $this->teacher = Teacher::factory()->create();
    $this->teacher->circles()->attach($this->circle->id);

    $this->student = Student::factory()->create([
        'circle_id' => $this->circle->id,
        'status' => 'active',
        'is_approved' => true,
    ]);
});

it('records and reads back a notification', function () {
    NotificationService::notify('student', $this->student->id, 'test', 'عنوان', 'نص الإشعار');

    expect(NotificationService::unreadCountFor('student', $this->student->id))->toBe(1);

    NotificationService::markAllRead('student', $this->student->id);

    expect(NotificationService::unreadCountFor('student', $this->student->id))->toBe(0);
});

it('notifies the student when their teacher grades a plan day', function () {
    $plan = StudentPlan::create([
        'student_id' => $this->student->id,
        'teacher_id' => $this->teacher->id,
        'start_date' => now(),
        'days_count' => 1,
        'active_days' => [0, 1, 2, 3, 4, 5, 6],
        'description' => 'خطة',
        'status' => 'active',
        'plan_type' => 'hifz_review',
        'direction' => 'forward',
        'is_approved' => true,
        'created_by_role' => 'teacher',
    ]);

    $day = StudentPlanDay::create([
        'student_plan_id' => $plan->id,
        'date' => now()->format('Y-m-d'),
        'day_name' => now()->dayName,
        'from_ayah_id' => 1,
        'to_ayah_id' => 1,
    ]);

    Livewire::test('teacher.student-tasmeeh-card', [
        'student' => $this->student,
        'sPlans' => collect(),
        'activePlanId' => $plan->id,
        'gradedAtDate' => now()->format('Y-m-d'),
    ])->call('saveAchievement', $day->id, 'hifz', 3);

    expect(NotificationService::unreadCountFor('student', $this->student->id))->toBe(1);
    expect(AppNotification::first()->type)->toBe('grading');
});

it('notifies the student when their plan is approved', function () {
    $plan = StudentPlan::create([
        'student_id' => $this->student->id,
        'teacher_id' => $this->teacher->id,
        'start_date' => now(),
        'days_count' => 1,
        'active_days' => [0, 1, 2, 3, 4, 5, 6],
        'description' => 'خطة',
        'status' => 'active',
        'plan_type' => 'hifz_review',
        'direction' => 'forward',
        'is_approved' => false,
        'created_by_role' => 'teacher',
    ]);

    $this->actingAs($this->teacher, 'teacher');

    Livewire::test('teacher.student-plans-list')->call('approvePlan', $plan->id);

    expect(NotificationService::unreadCountFor('student', $this->student->id))->toBe(1);
    expect(AppNotification::first()->type)->toBe('plan_approved');
});

it('notifies the student when a new exam is scheduled and when its result is recorded', function () {
    $examLevel = ExamLevel::create(['name' => 'مستوى أول']);

    $this->actingAs($this->teacher, 'teacher');

    $component = Livewire::test('teacher.student-exams')
        ->set('studentId', $this->student->id)
        ->set('examLevelId', $examLevel->id)
        ->set('dateTime', now()->addDay()->format('Y-m-d\TH:i'))
        ->set('status', 'pending')
        ->call('save');

    expect(NotificationService::unreadCountFor('student', $this->student->id))->toBe(1);
    expect(AppNotification::orderByDesc('id')->first()->type)->toBe('exam_scheduled');

    $exam = StudentExam::first();

    Livewire::test('teacher.student-exams')
        ->call('edit', $exam->id)
        ->set('status', 'passed')
        ->set('scorePercentage', 90)
        ->call('save');

    expect(NotificationService::unreadCountFor('student', $this->student->id))->toBe(2);
    expect(AppNotification::orderByDesc('id')->first()->type)->toBe('exam_result');
});

it('notifies the circle teachers when a self-registered student is approved', function () {
    $manager = Manager::factory()->create();
    $this->actingAs($manager, 'manager');

    $pendingStudent = Student::factory()->create([
        'circle_id' => $this->circle->id,
        'is_approved' => false,
    ]);

    Livewire::test('manager.pending-approvals')->call('approve', $pendingStudent->id, 'student');

    expect(NotificationService::unreadCountFor('teacher', $this->teacher->id))->toBe(1);
    expect(AppNotification::first()->type)->toBe('new_student');
});

it('notifies the recipient when a new message is sent', function () {
    $conversation = Conversation::findOrCreateBetween('student', $this->student->id, 'teacher', $this->teacher->id);

    $this->actingAs($this->student, 'student');

    Livewire::test('messaging.inbox')
        ->call('selectConversation', $conversation->id)
        ->set('newMessageBody', 'السلام عليكم')
        ->call('sendMessage');

    expect(NotificationService::unreadCountFor('teacher', $this->teacher->id))->toBe(1);
    expect(AppNotification::first()->type)->toBe('new_message');
});
