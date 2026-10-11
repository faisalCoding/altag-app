<?php

use App\Ai\Agents\PersonlanAssistant;
use App\Ai\Tools\getBusBookings;
use App\Ai\Tools\getDisciplineAndStatus;
use App\Ai\Tools\getExams;
use App\Ai\Tools\getForms;
use App\Ai\Tools\getMemorizationProgress;
use App\Ai\Tools\getPeerPairs;
use App\Ai\Tools\getRecitationActivity;
use App\Ai\Tools\getRulesAcknowledgements;
use App\Ai\Tools\getTeacherAttendance;
use App\Models\Attendance;
use App\Models\Ayah;
use App\Models\Bus;
use App\Models\BusBooking;
use App\Models\Circle;
use App\Models\ExamLevel;
use App\Models\Form;
use App\Models\FormAssignment;
use App\Models\FormResponse;
use App\Models\Manager;
use App\Models\PeerPair;
use App\Models\PlanDayAttempt;
use App\Models\PromotionRun;
use App\Models\PromotionRunItem;
use App\Models\RuleSection;
use App\Models\Setting;
use App\Models\Stage;
use App\Models\Student;
use App\Models\StudentPlan;
use App\Models\StudentPlanDay;
use App\Models\StudentStatusHistory;
use App\Models\Supervisor;
use App\Models\Surah;
use App\Models\Teacher;
use App\Models\TeacherAttendance;
use App\Services\ExamReadiness;
use App\Support\CircleRules;
use Carbon\Carbon;
use Laravel\Ai\Tools\Request;

/*
 * Wednesday 14 October 2026. One stage, «حلقة النور» in it, its teacher and
 * supervisor, and two students: أنس and زيد.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-14 09:00:00');

    $this->stage = Stage::create(['name' => 'المرحلة الأولى']);
    $this->circle = Circle::create(['name' => 'حلقة النور', 'stage_id' => $this->stage->id]);
    $this->teacher = Teacher::factory()->create(['name' => 'المعلم خالد']);
    $this->teacher->circles()->attach($this->circle->id);
    $this->supervisor = Supervisor::factory()->create(['name' => 'المشرف سعد']);
    $this->supervisor->stages()->attach($this->stage->id);
    $this->anas = Student::factory()->create(['name' => 'أنس', 'circle_id' => $this->circle->id, 'status' => 'active']);
    $this->zaid = Student::factory()->create(['name' => 'زيد', 'circle_id' => $this->circle->id, 'status' => 'active']);
});

function runAiTool(object $tool, array $arguments = []): array
{
    return json_decode((string) $tool->handle(new Request($arguments)), true);
}

function aiToolPlanDay(Student $student, string $date, array $attributes = []): StudentPlanDay
{
    $plan = StudentPlan::firstOrCreate(['student_id' => $student->id], [
        'start_date' => '2026-10-01', 'days_count' => 10, 'status' => 'active',
        'plan_type' => 'hifz_review', 'active_days' => ['sunday'], 'is_approved' => true, 'direction' => 'forward',
    ]);

    return StudentPlanDay::create(['student_plan_id' => $plan->id, 'date' => $date, 'day_name' => 'الأحد'] + $attributes);
}

it('gives the assistant every new tool', function () {
    $tools = collect((new PersonlanAssistant)->tools())->map(fn ($tool) => class_basename($tool))->all();

    expect($tools)->toContain('getTeacherAttendance', 'getExams', 'getRecitationActivity', 'getDisciplineAndStatus',
        'getRulesAcknowledgements', 'getPeerPairs', 'getForms', 'getBusBookings', 'getMemorizationProgress');
});

it('counts the teachers\' register the way the site\'s report does, the most absent first', function () {
    $other = Teacher::factory()->create(['name' => 'المعلم ماجد']);

    foreach ([
        [$this->teacher, '2026-10-11', 'present'], [$this->teacher, '2026-10-12', 'late'], [$this->teacher, '2026-10-13', 'excused'],
        [$other, '2026-10-11', 'absent'], [$other, '2026-10-12', 'absent'], [$other, '2026-10-13', 'present'],
    ] as [$teacher, $date, $status]) {
        TeacherAttendance::create(['teacher_id' => $teacher->id, 'stage_id' => $this->stage->id, 'date' => $date, 'status' => $status]);
    }
    TeacherAttendance::where('teacher_id', $other->id)->where('date', 'like', '2026-10-11%')->update(['substitute_teacher_id' => $this->teacher->id]);

    $result = runAiTool(new getTeacherAttendance, ['from' => '2026-10-11', 'to' => '2026-10-13', 'details' => true]);

    expect(array_column($result['teachers'], 'teacher'))->toBe(['المعلم ماجد', 'المعلم خالد'])
        // Excused is not an absence: (1 present + 1 late) ÷ 2.
        ->and($result['teachers'][1]['نسبة الحضور'])->toBe(100)
        ->and($result['teachers'][0]['نسبة الحضور'])->toBe(33)
        ->and($result['stages']['المرحلة الأولى']['غائب'])->toBe(2)
        ->and(collect($result['days'])->firstWhere('substitute', 'المعلم خالد')['teacher'])->toBe('المعلم ماجد');
});

it('shows who is ready, expected, seated, and how the finished exams went', function () {
    $level = ExamLevel::create(['name' => 'جزء عم']);
    $ready = ExamReadiness::nominate($this->anas, $level->id, null, $this->teacher);
    ExamReadiness::nominate($this->zaid, $level->id, '2026-10-20', $this->teacher);
    $third = Student::factory()->create(['name' => 'سالم', 'circle_id' => $this->circle->id]);
    $fourth = Student::factory()->create(['name' => 'ماجد', 'circle_id' => $this->circle->id]);
    $batch = ExamReadiness::createBatch('2026-10-10 16:00', 'القاعة', null, [
        ExamReadiness::nominate($third, $level->id, null, $this->teacher)->id,
        ExamReadiness::nominate($fourth, $level->id, null, $this->teacher)->id,
    ], null);
    ExamReadiness::recordResult($batch->exams()->where('student_id', $third->id)->first(), 'passed', '90');
    ExamReadiness::recordResult($batch->exams()->where('student_id', $fourth->id)->first(), 'failed', '50');
    ExamReadiness::createBatch('2026-10-25 16:00', null, null, [$ready->id], null);

    $result = runAiTool(new getExams);

    expect($result['ready']['count'])->toBe(0)
        ->and($result['expected']['students'][0])->toMatchArray(['student' => 'زيد', 'expected_on' => '2026-10-20', 'due' => false])
        ->and($result['seated']['students'][0])->toMatchArray(['student' => 'أنس', 'exam_at' => '2026-10-25 16:00'])
        ->and(array_column($result['batches'], 'results_recorded'))->toBe([2, 0])
        ->and($result['results']['by_level']['جزء عم'])->toMatchArray(['تم التجاوز' => 1, 'لم يتجاوز' => 1, 'نسبة النجاح' => 50, 'متوسط الدرجة' => 70.0]);
});

it('tells who recited and who did not, a «لم يسمع» not counting as recited', function () {
    $day = aiToolPlanDay($this->anas, '2026-10-13');
    PlanDayAttempt::create(['student_plan_day_id' => $day->id, 'part' => 'hifz', 'recited_on' => '2026-10-13', 'grade' => 3, 'recorded_by' => $this->teacher->id]);
    $zaidDay = aiToolPlanDay($this->zaid, '2026-10-13');
    PlanDayAttempt::create(['student_plan_day_id' => $zaidDay->id, 'part' => 'hifz', 'recited_on' => '2026-10-13', 'grade' => 0, 'recorded_by' => $this->teacher->id]);
    Attendance::create(['student_id' => $this->zaid->id, 'circle_id' => $this->circle->id, 'teacher_id' => $this->teacher->id, 'date' => '2026-10-12', 'status' => 'absent']);

    $result = runAiTool(new getRecitationActivity, ['from' => '2026-10-11', 'to' => '2026-10-14']);

    expect($result['totals'])->toMatchArray(['students' => 2, 'recited' => 1, 'did_not_recite' => 1, 'sessions' => 2, 'ممتاز' => 1, 'لم يسمع' => 1])
        ->and($result['did_not_recite'])->toBe([['student' => 'زيد', 'circle' => 'حلقة النور', 'stage' => 'المرحلة الأولى', 'absent_days' => 1, 'graded_not_heard' => 1]])
        ->and($result['circles']['حلقة النور']['recited'])->toBe(1)
        ->and($result['teachers'][0])->toMatchArray(['teacher' => 'المعلم خالد', 'sessions' => 2]);

    // Today alone: nothing recited yet.
    expect(runAiTool(new getRecitationActivity)['totals']['did_not_recite'])->toBe(2);
});

it('lists who is over the limits in the counting window, who is suspended, and the status changes', function () {
    Setting::setVal('absence_limit', 2);
    Setting::setVal('calculation_period_days', 30);

    foreach (['2026-10-05', '2026-10-12'] as $date) {
        Attendance::create(['student_id' => $this->anas->id, 'circle_id' => $this->circle->id, 'teacher_id' => $this->teacher->id, 'date' => $date, 'status' => 'absent']);
    }
    // Outside the window: not counted.
    Attendance::create(['student_id' => $this->zaid->id, 'circle_id' => $this->circle->id, 'teacher_id' => $this->teacher->id, 'date' => '2026-08-01', 'status' => 'absent']);
    Attendance::create(['student_id' => $this->zaid->id, 'circle_id' => $this->circle->id, 'teacher_id' => $this->teacher->id, 'date' => '2026-10-13', 'status' => 'absent']);

    $this->zaid->update(['status' => 'suspended']);
    StudentStatusHistory::create(['student_id' => $this->zaid->id, 'status' => 'suspended', 'start_date' => '2026-10-10', 'end_date' => '2026-10-20', 'notes' => 'تكرار الغياب', 'changed_by_name' => 'المشرف سعد']);

    $result = runAiTool(new getDisciplineAndStatus);

    expect(array_column($result['over_limit']['students'], 'student'))->toBe(['أنس'])
        ->and($result['over_limit']['students'][0]['over'])->toBe(['الغياب'])
        ->and($result['suspended_now'][0])->toMatchArray(['student' => 'زيد', 'since' => '2026-10-10', 'until' => '2026-10-20'])
        ->and($result['status_changes']['changes'][0])->toMatchArray(['student' => 'زيد', 'status' => 'موقوف', 'changed_by' => 'المشرف سعد']);
});

it('tells who acknowledged the rules and who still owes them, by stage', function () {
    $section = RuleSection::create(['title' => 'الانضباط', 'position' => 1]);
    $section->clauses()->create(['audience' => 'shared', 'body' => 'الحضور قبل بدء الحلقة.', 'position' => 1]);
    $version = CircleRules::publish(Manager::factory()->create(), true, null);
    CircleRules::acknowledge($this->teacher, $version, '127.0.0.1', 'test');

    $result = runAiTool(new getRulesAcknowledgements);

    expect($result['binding_version']['number'])->toBe($version->number)
        ->and(array_column($result['owing'], 'name'))->toBe(['المشرف سعد'])
        ->and($result['owing'][0]['stages'])->toBe(['المرحلة الأولى'])
        ->and($result['acknowledged_people'][0])->toMatchArray(['name' => 'المعلم خالد', 'channel' => 'من حسابه'])
        ->and(runAiTool(new getRulesAcknowledgements, ['role' => 'teacher'])['owing'])->toBe([])
        ->and(runAiTool(new getRulesAcknowledgements, ['stage' => 'مرحله اخرى'])['people'])->toBe(0);
});

it('lists the mutual recitation pairs with the grade the teacher gave', function () {
    $anasDay = aiToolPlanDay($this->anas, '2026-10-14');
    PlanDayAttempt::create(['student_plan_day_id' => $anasDay->id, 'part' => 'review', 'recited_on' => '2026-10-14', 'grade' => 2]);
    PeerPair::create([
        'circle_id' => $this->circle->id, 'date' => '2026-10-14', 'position' => 1, 'mutual' => false,
        'first_id' => $this->anas->id, 'first_day_id' => $anasDay->id, 'first_mistakes' => 3, 'second_id' => $this->zaid->id,
    ]);

    $result = runAiTool(new getPeerPairs);

    expect($result['circles']['حلقة النور'])->toMatchArray(['days' => 1, 'pairs' => 1, 'mutual' => 0, 'average_mistakes' => 3.0])
        ->and($result['pairs'][0]['first'])->toMatchArray(['student' => 'أنس', 'recites' => true, 'mistakes' => 3, 'grade' => 'جيد'])
        ->and($result['pairs'][0]['second'])->toMatchArray(['student' => 'زيد', 'recites' => false]);
});

it('counts a form\'s answers and choices, never handing over a free-text answer', function () {
    $form = Form::create([
        'title' => 'طلب طقم الطالب', 'slug' => 'uniform', 'status' => 'published',
        'fields' => [
            ['id' => 'name', 'type' => 'text', 'label' => 'اسم الطالب', 'options' => []],
            ['id' => 'national', 'type' => 'text', 'label' => 'رقم الهوية', 'options' => []],
            ['id' => 'size', 'type' => 'select', 'label' => 'المقاس', 'options' => ['S', 'M', 'L']],
        ],
    ]);
    foreach ([['أنس', '1111111111', 'M'], ['زيد', '2222222222', 'M'], ['سالم', '3333333333', 'L']] as [$name, $id, $size]) {
        FormResponse::create(['form_id' => $form->id, 'answers' => ['name' => $name, 'national' => $id, 'size' => $size]]);
    }
    FormAssignment::create(['form_id' => $form->id, 'user_id' => $this->teacher->id, 'role' => 'teacher', 'status' => 'pending']);

    $output = (string) (new getForms)->handle(new Request(['form' => 'طقم']));
    $result = json_decode($output, true);

    expect($output)->not->toContain('1111111111')->not->toContain('سالم')
        ->and($result['forms'][0]['responses'])->toBe(3)
        ->and($result['forms'][0]['not_answered'])->toBe(['المعلم خالد'])
        ->and($result['forms'][0]['questions'][2])->toBe(['question' => 'المقاس', 'answered' => 3, 'choices' => ['M' => 2, 'L' => 1]])
        ->and($result['forms'][0]['questions'][1])->toBe(['question' => 'رقم الهوية', 'answered' => 3]);
});

it('lists the buses, the stages\' bookings and their standing', function () {
    $bus = Bus::create(['name' => 'الحافلة الكبيرة', 'type' => 'bus', 'fee_amount' => 300, 'is_active' => true]);
    $booking = BusBooking::create(['stage_id' => $this->stage->id, 'date' => '2026-10-20', 'status' => BusBooking::PENDING, 'fee_total' => 300, 'fee_due_on' => '2026-10-17']);
    $booking->buses()->attach($bus->id, ['fee_amount' => 300]);

    $result = runAiTool(new getBusBookings, ['from' => '2026-10-01']);

    expect($result['buses'][0])->toMatchArray(['name' => 'الحافلة الكبيرة', 'fee' => 300])
        ->and($result['bookings'][0])->toMatchArray(['date' => '2026-10-20', 'stage' => 'المرحلة الأولى', 'buses' => ['الحافلة الكبيرة'], 'status' => 'بانتظار الرسوم', 'fee_paid' => false])
        ->and($result['stage_standings']['المرحلة الأولى'])->toBe('مسموح');
});

it('ranks students by what they memorized and counts the verses of a span, with the promotions', function () {
    Surah::create(['id' => 1, 'number' => 1, 'name_arabic' => 'الفاتحة', 'name_simple' => 'Al-Fatihah', 'revelation_place' => 'makkah', 'revelation_order' => 5, 'verses_count' => 7, 'start_page' => 1, 'end_page' => 1]);
    foreach (range(1, 7) as $verse) {
        Ayah::create([
            'id' => $verse, 'surah_id' => 1, 'verse_number' => $verse, 'verse_key' => "1:{$verse}", 'juz_number' => 1, 'page_number' => 1,
            'line_number_start' => $verse, 'line_number_end' => $verse, 'hizb_number' => 1, 'rub_number' => 1, 'ruku_number' => 1,
            'manzil_number' => 1, 'text_uthmani' => "آية {$verse}",
        ]);
    }
    aiToolPlanDay($this->anas, '2026-10-12', ['from_ayah_id' => 1, 'to_ayah_id' => 7, 'hifz_achievement' => 3]);

    $run = PromotionRun::create(['name' => 'ترقيات ١٤٤٨', 'type' => PromotionRun::PROMOTION, 'status' => PromotionRun::APPLIED, 'applied_at' => '2026-09-01']);
    PromotionRunItem::create(['promotion_run_id' => $run->id, 'student_id' => $this->zaid->id, 'from_circle_id' => $this->circle->id, 'from_status' => 'active', 'action' => PromotionRunItem::GRADUATE]);

    $result = runAiTool(new getMemorizationProgress, ['from' => '2026-10-01', 'to' => '2026-10-31']);

    expect($result['students'][0])->toMatchArray(['student' => 'أنس', 'pages' => 1, 'verses_in_period' => 7])
        ->and($result['students'][1])->toMatchArray(['student' => 'زيد', 'pages' => 0, 'verses_in_period' => 0])
        ->and($result['promotions'][0])->toMatchArray(['name' => 'ترقيات ١٤٤٨', 'status' => 'مطبّقة', 'graduated' => 1]);

    expect(runAiTool(new getMemorizationProgress, ['promotion' => 'ترقيات'])['promotions'][0]['students'][0])
        ->toBe(['student' => 'زيد', 'action' => 'تخرّج', 'from' => 'حلقة النور', 'to' => null]);
});
