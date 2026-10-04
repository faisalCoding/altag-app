<?php

use App\Ai\Agents\PersonlanAssistant;
use App\Ai\Tools\getAcademicCalendar;
use App\Ai\Tools\getAttendanceData;
use App\Ai\Tools\getCompetitionGroups;
use App\Ai\Tools\getCompetitions;
use App\Ai\Tools\getMagicLinks;
use App\Ai\Tools\getMutunPlans;
use App\Ai\Tools\getOrganizationStructure;
use App\Ai\Tools\getPeopleDirectory;
use App\Ai\Tools\getQuranPlans;
use App\Ai\Tools\getStudentProfile;
use App\Ai\Tools\getTasks;
use App\Models\AcademicCalendarEvent;
use App\Models\Attendance;
use App\Models\Circle;
use App\Models\GamificationTeam;
use App\Models\GamificationTrack;
use App\Models\Guardian;
use App\Models\Leaderboard;
use App\Models\LeaderboardCriterion;
use App\Models\Ode;
use App\Models\OdePath;
use App\Models\Stage;
use App\Models\Student;
use App\Models\StudentOdePlan;
use App\Models\StudentPlan;
use App\Models\StudentPlanDay;
use App\Models\Supervisor;
use App\Models\Task;
use App\Models\TaskCategory;
use App\Models\Teacher;
use App\Models\TeacherCompetition;
use App\Models\TeacherCompetitionCriterion;
use Laravel\Ai\Tools\Request;

/**
 * Run a tool the way the agent does and decode its JSON payload.
 *
 * @param  array<string, mixed>  $arguments
 * @return array<string, mixed>
 */
function runTool(object $tool, array $arguments = []): array
{
    return json_decode((string) $tool->handle(new Request($arguments)), true);
}

beforeEach(function () {
    $this->stage = Stage::create(['name' => 'المرحلة الأولى']);
    $this->circle = Circle::create(['name' => 'حلقة النور', 'stage_id' => $this->stage->id]);

    $this->supervisor = Supervisor::factory()->create(['name' => 'المشرف سعد']);
    $this->supervisor->stages()->attach($this->stage->id);

    $this->teacher = Teacher::factory()->create(['name' => 'المعلم خالد']);
    $this->teacher->circles()->attach($this->circle->id);

    $this->guardian = Guardian::factory()->create(['name' => 'الوالد عمر', 'phone' => '0500000000']);

    $this->student = Student::factory()->create([
        'name' => 'الطالب أنس',
        'circle_id' => $this->circle->id,
        'guardian_id' => $this->guardian->id,
        'status' => 'active',
    ]);
});

it('describes every stage, circle, teacher and supervisor in the structure tool', function () {
    Student::factory()->create(['circle_id' => null, 'status' => 'active']);

    $result = runTool(new getOrganizationStructure);

    expect($result['stages'])->toHaveCount(1);

    $stage = $result['stages'][0];

    expect($stage['stage'])->toBe('المرحلة الأولى')
        ->and($stage['supervisors'])->toBe(['المشرف سعد'])
        ->and($stage['circles'][0]['circle'])->toBe('حلقة النور')
        ->and($stage['circles'][0]['teachers'])->toBe(['المعلم خالد'])
        ->and($stage['circles'][0]['students']['total'])->toBe(1)
        ->and($stage['circles'][0]['students']['مشارك'])->toBe(1)
        ->and($result['students_without_circle'])->toBe(1);
});

it('lists each role through the people directory tool', function () {
    expect(runTool(new getPeopleDirectory, ['role' => 'student'])['people'][0])
        ->toMatchArray([
            'name' => 'الطالب أنس',
            'circle' => 'حلقة النور',
            'stage' => 'المرحلة الأولى',
            'guardian' => 'الوالد عمر',
            'status' => 'مشارك',
        ]);

    expect(runTool(new getPeopleDirectory, ['role' => 'teacher'])['people'][0])
        ->toMatchArray(['name' => 'المعلم خالد', 'circles' => ['حلقة النور']]);

    expect(runTool(new getPeopleDirectory, ['role' => 'supervisor'])['people'][0])
        ->toMatchArray(['name' => 'المشرف سعد', 'stages' => ['المرحلة الأولى']]);

    $guardian = runTool(new getPeopleDirectory, ['role' => 'guardian'])['people'][0];

    expect($guardian['name'])->toBe('الوالد عمر')
        ->and($guardian['children'][0]['name'])->toBe('الطالب أنس');
});

it('filters the people directory by circle, status and search', function () {
    $otherCircle = Circle::create(['name' => 'حلقة الفرقان', 'stage_id' => $this->stage->id]);
    Student::factory()->create([
        'name' => 'الطالب زيد',
        'circle_id' => $otherCircle->id,
        'status' => 'suspended',
    ]);

    expect(runTool(new getPeopleDirectory, ['role' => 'student', 'circle' => 'النور'])['people'])
        ->toHaveCount(1);

    expect(runTool(new getPeopleDirectory, ['role' => 'student', 'status' => 'suspended'])['people'][0]['name'])
        ->toBe('الطالب زيد');

    expect(runTool(new getPeopleDirectory, ['role' => 'student', 'search' => 'زيد'])['people'])
        ->toHaveCount(1);
});

it('caps the people directory and says so', function () {
    Student::factory()->count(3)->create(['circle_id' => $this->circle->id]);

    $result = runTool(new getPeopleDirectory, ['role' => 'student', 'limit' => 2]);

    expect($result['people'])->toHaveCount(2)
        ->and($result['note'])->toContain('capped');
});

it('returns a full student profile with plans, attendance and grades', function () {
    Attendance::create([
        'student_id' => $this->student->id,
        'teacher_id' => $this->teacher->id,
        'circle_id' => $this->circle->id,
        'date' => '2026-07-01',
        'status' => 'present',
    ]);
    Attendance::create([
        'student_id' => $this->student->id,
        'teacher_id' => $this->teacher->id,
        'circle_id' => $this->circle->id,
        'date' => '2026-07-02',
        'status' => 'absent',
    ]);

    $plan = StudentPlan::create([
        'student_id' => $this->student->id,
        'teacher_id' => $this->teacher->id,
        'start_date' => '2026-07-01',
        'days_count' => 2,
        'status' => 'active',
        'plan_type' => 'memorization',
        'active_days' => ['sunday'],
    ]);
    StudentPlanDay::create([
        'student_plan_id' => $plan->id,
        'date' => '2026-07-01',
        'day_name' => 'الأحد',
        'hifz_achievement' => 3,
    ]);

    $result = runTool(new getStudentProfile, ['student' => 'أنس']);

    expect($result['identity'])->toMatchArray([
        'name' => 'الطالب أنس',
        'circle' => 'حلقة النور',
        'stage' => 'المرحلة الأولى',
        'status' => 'مشارك',
    ]);
    expect($result['identity']['guardian']['name'])->toBe('الوالد عمر')
        ->and($result['attendance']['حاضر'])->toBe(1)
        ->and($result['attendance']['غائب'])->toBe(1)
        ->and($result['quran_plans'][0]['type'])->toBe('حفظ')
        ->and($result['quran_plans'][0]['completion_percentage'])->toBe(50)
        ->and($result['quran_plans'][0]['grades']['ممتاز'])->toBe(1);
});

it('asks which student is meant when the name is ambiguous', function () {
    Student::factory()->create(['name' => 'الطالب أنس الثاني', 'circle_id' => $this->circle->id]);

    $result = runTool(new getStudentProfile, ['student' => 'أنس']);

    expect($result['ambiguous'])->toBeTrue()
        ->and($result['matches'])->toHaveCount(2);

    expect(runTool(new getStudentProfile, ['student' => 'لا أحد'])['error'])->toContain('No student');
});

it('reports quran plan progress and grade distribution', function () {
    $plan = StudentPlan::create([
        'student_id' => $this->student->id,
        'teacher_id' => $this->teacher->id,
        'start_date' => '2026-07-01',
        'days_count' => 4,
        'status' => 'active',
        'plan_type' => 'review',
        'active_days' => ['sunday'],
    ]);

    foreach ([3, 2, 1] as $index => $grade) {
        StudentPlanDay::create([
            'student_plan_id' => $plan->id,
            'date' => '2026-07-0'.($index + 1),
            'day_name' => 'الأحد',
            'hifz_achievement' => $grade,
        ]);
    }

    $result = runTool(new getQuranPlans, ['student' => 'أنس', 'include_days' => true]);

    expect($result['plans'][0])->toMatchArray([
        'student' => 'الطالب أنس',
        'circle' => 'حلقة النور',
        'teacher' => 'المعلم خالد',
        'type' => 'مراجعة',
        'completion_percentage' => 75.0,
    ]);
    expect($result['plans'][0]['grades'])->toBe(['ممتاز' => 1, 'جيد' => 1, 'ضعيف' => 1, 'لم يسمع' => 0])
        ->and($result['plans'][0]['days'])->toHaveCount(3)
        ->and($result['plans'][0]['days'][0]['hifz_grade'])->toBe('ممتاز');
});

it('excludes plans of other circles when filtered', function () {
    $otherCircle = Circle::create(['name' => 'حلقة الفرقان', 'stage_id' => $this->stage->id]);
    $otherStudent = Student::factory()->create(['name' => 'الطالب زيد', 'circle_id' => $otherCircle->id]);

    StudentPlan::create([
        'student_id' => $otherStudent->id,
        'start_date' => '2026-07-01',
        'days_count' => 1,
        'status' => 'active',
        'plan_type' => 'memorization',
        'active_days' => ['sunday'],
    ]);

    $result = runTool(new getQuranPlans, ['circle' => 'النور']);

    expect($result['plans'])->toBeEmpty();
});

it('returns the ode catalogue and the student plans on it', function () {
    $ode = Ode::create(['name' => 'منظومة البيقونية']);
    $path = OdePath::create([
        'ode_id' => $ode->id,
        'name' => 'مسار البيقونية',
        'start_date' => '2026-07-01',
    ]);
    StudentOdePlan::create([
        'student_id' => $this->student->id,
        'ode_path_id' => $path->id,
        'start_date' => '2026-07-01',
        'status' => 'active',
        'created_by_role' => 'teacher',
    ]);

    $result = runTool(new getMutunPlans, ['kind' => 'ode']);

    expect($result['odes_catalogue'][0]['ode'])->toBe('منظومة البيقونية')
        ->and($result['odes_catalogue'][0]['paths'][0]['path'])->toBe('مسار البيقونية')
        ->and($result['ode_plans'][0]['student'])->toBe('الطالب أنس')
        ->and($result['ode_plans'][0]['circle'])->toBe('حلقة النور')
        ->and($result)->not->toHaveKey('mutun_plans');
});

it('returns student and teacher competitions with their criteria', function () {
    $competition = Leaderboard::create([
        'title' => 'مسابقة الحفظ الكبرى',
        'circle_id' => $this->circle->id,
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
        'is_active' => true,
        'competition_type' => 'normal',
    ]);
    LeaderboardCriterion::create([
        'leaderboard_id' => $competition->id,
        'name' => 'إتقان الحفظ',
        'points' => 10,
    ]);

    $teacherCompetition = TeacherCompetition::create([
        'name' => 'مسابقة المعلمين',
        'supervisor_id' => $this->supervisor->id,
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
        'is_active' => true,
    ]);
    TeacherCompetitionCriterion::create([
        'teacher_competition_id' => $teacherCompetition->id,
        'name' => 'الالتزام بالحضور',
        'max_points' => 20,
    ]);
    $teacherCompetition->participants()->attach($this->teacher->id);

    $result = runTool(new getCompetitions, ['include_standings' => true]);

    expect($result['student_competitions'][0])->toMatchArray([
        'title' => 'مسابقة الحفظ الكبرى',
        'type' => 'نقاط',
        'scope' => 'مسابقة حلقة',
    ]);
    expect($result['student_competitions'][0]['criteria'][0]['name'])->toBe('إتقان الحفظ')
        ->and($result['student_competitions'][0]['standings'][0]['student'])->toBe('الطالب أنس')
        ->and($result['teacher_competitions'][0]['name'])->toBe('مسابقة المعلمين')
        ->and($result['teacher_competitions'][0]['criteria'][0]['max_points'])->toBe(20)
        ->and($result['teacher_competitions'][0]['standings'][0]['teacher'])->toBe('المعلم خالد');
});

it('returns academic calendar events within a range', function () {
    AcademicCalendarEvent::create([
        'event_name' => 'الفصل الأول',
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
        'is_attendance_period' => true,
        'weekdays' => ['sunday', 'monday'],
    ]);
    AcademicCalendarEvent::create([
        'event_name' => 'إجازة منتصف العام',
        'start_date' => '2026-12-01',
        'end_date' => '2026-12-15',
    ]);

    $all = runTool(new getAcademicCalendar);
    $ranged = runTool(new getAcademicCalendar, ['from' => '2026-07-01', 'to' => '2026-07-31']);

    expect($all['events'])->toHaveCount(2)
        ->and($ranged['events'])->toHaveCount(1)
        ->and($ranged['events'][0]['name'])->toBe('الفصل الأول')
        ->and($ranged['events'][0]['is_attendance_period'])->toBeTrue();
});

it('returns tasks with their category and assignee', function () {
    $category = TaskCategory::create(['name' => 'متابعة إدارية']);

    Task::create([
        'title' => 'مراجعة تقارير الحلقات',
        'task_category_id' => $category->id,
        'due_date' => '2026-07-20',
        'status' => 'pending',
        'created_by_id' => $this->supervisor->id,
        'created_by_type' => Supervisor::class,
        'assigned_to_id' => $this->teacher->id,
        'assigned_to_type' => Teacher::class,
    ]);
    Task::create([
        'title' => 'مهمة أخرى',
        'due_date' => '2026-08-01',
        'status' => 'completed',
        'created_by_id' => $this->supervisor->id,
        'created_by_type' => Supervisor::class,
    ]);

    $result = runTool(new getTasks);

    expect($result['tasks'])->toHaveCount(2)
        ->and($result['categories'])->toContain('متابعة إدارية');

    $filtered = runTool(new getTasks, ['assignee' => 'خالد']);

    expect($filtered['tasks'])->toHaveCount(1)
        ->and($filtered['tasks'][0])->toMatchArray([
            'title' => 'مراجعة تقارير الحلقات',
            'category' => 'متابعة إدارية',
            'assigned_to' => 'المعلم خالد',
            'assigned_to_role' => 'معلم',
        ]);

    expect(runTool(new getTasks, ['status' => 'completed'])['tasks'])->toHaveCount(1);
});

/**
 * Competition groups cut across circles, so this is the only route from a
 * student to the group they compete in. Without it the assistant used to walk
 * students one at a time and run out of steps mid-answer.
 */
it('maps competition teams to their members and their attendance', function () {
    $competition = Leaderboard::create([
        'title' => 'مسابقة التاج',
        'circle_id' => $this->circle->id,
        'start_date' => '2026-06-30',
        'end_date' => '2026-08-13',
        'is_active' => true,
        'competition_type' => 'gamification',
    ]);

    $otherCircle = Circle::create(['name' => 'حلقة الفرقان', 'stage_id' => $this->stage->id]);
    $teammate = Student::factory()->create(['name' => 'الطالب زيد', 'circle_id' => $otherCircle->id]);

    $team = GamificationTeam::create(['leaderboard_id' => $competition->id, 'name' => 'فريق تركي']);
    $team->students()->attach([$this->student->id, $teammate->id]);

    GamificationTeam::create(['leaderboard_id' => $competition->id, 'name' => 'فريق صالح']);

    // Two recorded days: both attend the first, one is absent on the second.
    foreach ([
        [$this->student->id, '2026-07-01', 'present'],
        [$teammate->id, '2026-07-01', 'late'],
        [$this->student->id, '2026-07-02', 'present'],
        [$teammate->id, '2026-07-02', 'absent'],
        [$this->student->id, '2026-06-01', 'present'],
    ] as [$studentId, $date, $status]) {
        Attendance::create([
            'student_id' => $studentId,
            'teacher_id' => $this->teacher->id,
            'circle_id' => $this->circle->id,
            'date' => $date,
            'status' => $status,
        ]);
    }

    $result = runTool(new getCompetitionGroups, [
        'competition' => 'التاج',
        'kind' => 'team',
        'from' => '2026-06-30',
        'include_members' => true,
    ]);

    expect($result['competition'])->toBe('مسابقة التاج')
        ->and($result['teams'])->toHaveCount(2);

    $turki = collect($result['teams'])->firstWhere('team', 'فريق تركي');

    // The teammate sits in another circle, so a circle-based answer would miss them.
    expect($turki['members_count'])->toBe(2)
        ->and($turki['members'])->toContain('الطالب أنس', 'الطالب زيد')
        ->and($turki['attendance']['حاضر'])->toBe(2)
        ->and($turki['attendance']['متأخر'])->toBe(1)
        ->and($turki['attendance']['غائب'])->toBe(1)
        ->and($turki['attendance']['days_with_records'])->toBe(2)
        // Three attended out of two recorded days, and the June record is outside the period.
        ->and($turki['attendance']['average_attending_per_day'])->toBe(1.5);

    $saleh = collect($result['teams'])->firstWhere('team', 'فريق صالح');

    expect($saleh['members_count'])->toBe(0)
        ->and($saleh['attendance']['note'])->toContain('no members');
});

it('returns competition tracks and skips attendance without a period', function () {
    $competition = Leaderboard::create([
        'title' => 'مسابقة التاج',
        'circle_id' => $this->circle->id,
        'start_date' => '2026-06-30',
        'is_active' => true,
        'competition_type' => 'gamification',
    ]);

    $track = GamificationTrack::create([
        'leaderboard_id' => $competition->id,
        'name' => 'مسار المتقدمين',
        'sort_order' => 1,
    ]);
    $track->students()->attach($this->student->id);

    $result = runTool(new getCompetitionGroups, ['competition' => 'التاج', 'kind' => 'track']);

    expect($result['tracks'][0]['track'])->toBe('مسار المتقدمين')
        ->and($result['tracks'][0]['members_count'])->toBe(1)
        ->and($result['tracks'][0])->not->toHaveKey('attendance')
        ->and($result)->not->toHaveKey('teams')
        ->and($result['attendance_period'])->toContain('no attendance was counted');
});

it('lists the competitions when the name does not match', function () {
    Leaderboard::create([
        'title' => 'مسابقة التاج',
        'circle_id' => $this->circle->id,
        'start_date' => '2026-06-30',
        'is_active' => true,
        'competition_type' => 'gamification',
    ]);

    $result = runTool(new getCompetitionGroups, ['competition' => 'لا شيء']);

    expect($result['error'])->toContain('No competition')
        ->and($result['available_competitions'])->toContain('مسابقة التاج');
});

it('bounds attendance data by date and can summarize it', function () {
    foreach (['2026-07-01' => 'present', '2026-07-02' => 'absent', '2026-08-01' => 'late'] as $date => $status) {
        Attendance::create([
            'student_id' => $this->student->id,
            'teacher_id' => $this->teacher->id,
            'circle_id' => $this->circle->id,
            'date' => $date,
            'status' => $status,
        ]);
    }

    $july = runTool(new getAttendanceData, ['from' => '2026-07-01', 'to' => '2026-07-31']);

    expect($july['matching_records'])->toBe(2)
        ->and($july['attendance'])->toHaveKeys(['2026-07-01', '2026-07-02'])
        ->and($july['attendance']['2026-07-01']['المرحلة الأولى']['حلقة النور']['المعلم خالد']['الطالب أنس'])
        ->toBe('حاضر');

    $summary = runTool(new getAttendanceData, ['summary' => true]);

    expect($summary['matching_records'])->toBe(3)
        ->and($summary['summary']['المرحلة الأولى']['حلقة النور'])
        ->toBe(['حاضر' => 1, 'غائب' => 1, 'متأخر' => 1, 'مستأذن' => 0]);
});

it('exposes every data tool to the assistant', function () {
    $tools = collect((new PersonlanAssistant)->tools())
        ->map(fn ($tool) => class_basename($tool))
        ->all();

    expect($tools)->toContain(
        'getDateAndTime',
        'getOrganizationStructure',
        'getPeopleDirectory',
        'getMagicLinks',
        'getStudentProfile',
        'getAttendanceData',
        'getQuranPlans',
        'getMutunPlans',
        'getCompetitions',
        'getCompetitionGroups',
        'getAcademicCalendar',
        'getTasks',
    );
});

/**
 * A magic link signs its holder into that account with no password, so these
 * rows are credentials. The manager can already copy them from the students
 * page; this only saves the walk.
 */
it('returns magic login links for the manager to hand out', function () {
    $this->student->update(['access_token' => 'known-token']);

    $result = runTool(new getMagicLinks, ['role' => 'student', 'search' => 'أنس']);

    expect($result['people'])->toHaveCount(1)
        ->and($result['people'][0]['name'])->toBe('الطالب أنس')
        ->and($result['people'][0]['magic_link'])->toBe(route('magic-link', ['token' => 'known-token']))
        ->and($result['people'][0]['circle'])->toBe('حلقة النور')
        ->and($result['warning'])->toContain('without a password');
});

it('issues a link to an account that never had one', function () {
    $this->student->update(['access_token' => null]);

    $result = runTool(new getMagicLinks, ['role' => 'student', 'search' => 'أنس']);

    $token = $this->student->fresh()->access_token;

    expect($token)->not->toBeEmpty()
        ->and($result['people'][0]['had_no_link_before'])->toBeTrue()
        ->and($result['people'][0]['magic_link'])->toBe(route('magic-link', ['token' => $token]));
});

it('finds who has never been issued a link', function () {
    $this->student->update(['access_token' => 'has-one']);
    Student::factory()->create(['name' => 'الطالب بلا رابط', 'circle_id' => $this->circle->id, 'access_token' => null]);

    $result = runTool(new getMagicLinks, ['role' => 'student', 'only_without_link' => true]);

    expect($result['people'])->toHaveCount(1)
        ->and($result['people'][0]['name'])->toBe('الطالب بلا رابط');
});

it('uses the right sign-in route for each role', function () {
    $this->teacher->update(['access_token' => 'teacher-token']);
    $this->supervisor->update(['access_token' => 'supervisor-token']);
    $this->guardian->update(['access_token' => 'guardian-token']);

    expect(runTool(new getMagicLinks, ['role' => 'teacher'])['people'][0]['magic_link'])
        ->toBe(route('teacher.magic-link', ['token' => 'teacher-token']));

    expect(runTool(new getMagicLinks, ['role' => 'supervisor'])['people'][0]['magic_link'])
        ->toBe(route('supervisor.magic-link', ['token' => 'supervisor-token']));

    expect(runTool(new getMagicLinks, ['role' => 'guardian'])['people'][0]['magic_link'])
        ->toBe(route('guardian.magic-link', ['token' => 'guardian-token']));
});

it('refuses a role it has no sign-in route for', function () {
    $result = runTool(new getMagicLinks, ['role' => 'manager']);

    expect($result['error'])->toContain('Unsupported')
        ->and($result)->not->toHaveKey('people');
});
