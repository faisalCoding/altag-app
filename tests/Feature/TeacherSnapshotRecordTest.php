<?php

use App\Models\AcademicCalendarEvent;
use App\Models\Agreement;
use App\Models\Circle;
use App\Models\FreeRecitation;
use App\Models\HadithPath;
use App\Models\HadithPathDay;
use App\Models\HadithText;
use App\Models\PlanDayAttempt;
use App\Models\Stage;
use App\Models\Student;
use App\Models\StudentHadithAchievement;
use App\Models\StudentHadithPlan;
use App\Models\StudentPlan;
use App\Models\StudentPlanDay;
use App\Models\Teacher;
use App\Support\Agreements;
use Carbon\Carbon;

/**
 * What the teacher app's student card reads beside the tasmeeh: every graded
 * recitation over the attendance period the tasmeeh does not hold, the
 * agreements with their links, and what the stage memorises.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-07-08 10:00:00');

    $this->stage = Stage::factory()->create(['require_edit_reason' => false, 'hadith_enabled' => true, 'odes_enabled' => false]);
    $this->circle = Circle::factory()->create(['stage_id' => $this->stage->id]);
    $this->teacher = Teacher::factory()->create();
    $this->teacher->circles()->attach($this->circle->id);
    $this->token = $this->teacher->createToken('phone')->plainTextToken;

    $this->student = Student::factory()->create(['circle_id' => $this->circle->id, 'status' => 'active', 'is_approved' => true]);

    // The attendance period began before the sync window.
    AcademicCalendarEvent::create([
        'event_name' => 'الفصل الأول', 'start_date' => '2026-05-01', 'end_date' => '2026-08-30',
        'is_attendance_period' => true, 'weekdays' => [1, 2, 3, 4, 5], 'is_visible' => true,
    ]);
});

function recordPlanDayFor(Student $student, string $status): StudentPlanDay
{
    $plan = StudentPlan::create([
        'student_id' => $student->id, 'plan_type' => 'hifz', 'direction' => 'forward', 'start_date' => '2026-06-01',
        'is_approved' => true, 'status' => $status, 'days_count' => 1, 'active_days' => ['sunday'],
    ]);

    return StudentPlanDay::create(['student_plan_id' => $plan->id, 'date' => '2026-06-07', 'day_name' => 'الأحد']);
}

it('sends the record of every other plan, earlier free recitations and the mutun, leaving the tasmeeh\'s own to it', function () {
    $finished = recordPlanDayFor($this->student, 'completed');
    PlanDayAttempt::create(['student_plan_day_id' => $finished->id, 'part' => 'hifz', 'recited_on' => '2026-06-07', 'grade' => 3]);
    $running = recordPlanDayFor($this->student, 'active');
    PlanDayAttempt::create(['student_plan_day_id' => $running->id, 'part' => 'hifz', 'recited_on' => '2026-06-08', 'grade' => 2]);
    // Before the window, which starts on the 1st of the previous Hijri month (17 May); the window's own come with the tasmeeh.
    FreeRecitation::create(['student_id' => $this->student->id, 'type' => 'review', 'recited_on' => '2026-05-10', 'achievement' => 1]);
    FreeRecitation::create(['student_id' => $this->student->id, 'type' => 'hifz', 'recited_on' => '2026-06-20', 'achievement' => 2]);

    $text = HadithText::create(['name' => 'الأربعون النووية']);
    $path = HadithPath::create(['hadith_text_id' => $text->id, 'name' => 'مسار', 'memorize_type' => 'lines', 'memorize_amount' => 2, 'start_date' => '2026-05-01']);
    $day = HadithPathDay::create(['hadith_path_id' => $path->id, 'day_number' => 1, 'date' => '2026-06-10', 'day_name' => 'الأربعاء', 'memorize_type' => 'lines', 'memorize_amount' => 2]);
    $hadithPlan = StudentHadithPlan::create(['student_id' => $this->student->id, 'hadith_path_id' => $path->id, 'start_date' => '2026-05-01', 'status' => 'active', 'created_by_role' => 'teacher']);
    StudentHadithAchievement::create(['student_hadith_plan_id' => $hadithPlan->id, 'hadith_path_day_id' => $day->id, 'review_achievement' => 0, 'review_graded_at' => '2026-06-10 08:00:00']);

    $history = collect($this->withToken($this->token)->getJson('/api/v1/teacher/sync')->assertSuccessful()->json('data.recitation_history'));

    expect($history->map(fn (array $row) => [$row['date'], $row['kind'], $row['part'], $row['free'], $row['grade']])->all())->toBe([
        ['2026-05-10', 'quran', 'review', true, 1],
        ['2026-06-07', 'quran', 'hifz', false, 3],
        ['2026-06-10', 'hadith', 'review', false, 0],
    ]);
});

it('sends the agreements each student owes, with the student\'s and the guardian\'s own links', function () {
    $agreement = Agreement::create(['title' => 'تعهد الالتزام', 'type' => 'pledge', 'audience' => 'both', 'topics' => [['title' => 'الحضور', 'clauses' => ['بند']]], 'status' => 'draft']);
    $agreement->stages()->attach($this->stage->id);
    Agreements::publish($agreement, null, true);
    Agreements::sign($agreement->fresh(), $this->student, 'student', $this->student->name, null, 'link', null, null);

    $sent = $this->withToken($this->token)->getJson('/api/v1/teacher/sync')->assertSuccessful()->json('data');

    expect($sent['agreements'])->toHaveCount(1)
        ->and($sent['agreements'][0])->toMatchArray(['student_id' => $this->student->id, 'title' => 'تعهد الالتزام', 'type' => 'pledge'])
        ->and($sent['agreements'][0]['parties']['student']['signed_at'])->not->toBeNull()
        ->and($sent['agreements'][0]['parties']['guardian']['signed_at'])->toBeNull()
        ->and($sent['agreements'][0]['parties']['guardian']['link'])->toContain('/agreements/'.$this->student->id.'/guardian?signature=')
        ->and($sent['circles'][0]['stage'])->toMatchArray(['hadith_enabled' => true, 'odes_enabled' => false]);
});
