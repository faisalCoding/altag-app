<?php

use App\Livewire\Shared\OdePlanCreator;
use App\Models\Ayah;
use App\Models\Circle;
use App\Models\GamificationTransaction;
use App\Models\Leaderboard;
use App\Models\Ode;
use App\Models\OdePath;
use App\Models\OdePathDay;
use App\Models\OdeVerse;
use App\Models\Stage;
use App\Models\Student;
use App\Models\StudentOdeAchievement;
use App\Models\StudentOdePlan;
use App\Models\StudentPlan;
use App\Models\StudentPlanDay;
use App\Models\Supervisor;
use App\Models\Surah;
use App\Models\Teacher;
use App\Services\GamificationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->stage = Stage::create(['name' => 'مرحلة الخطط']);
    $this->circle = Circle::create(['name' => 'حلقة الخطط', 'stage_id' => $this->stage->id]);

    $this->supervisor = Supervisor::factory()->create();
    $this->supervisor->stages()->attach($this->stage->id);

    $this->teacher = Teacher::factory()->create();
    $this->teacher->circles()->attach($this->circle->id);

    $this->student = Student::create([
        'name' => 'طالب الخطة',
        'email' => 'planstudent@example.com',
        'password' => bcrypt('password'),
        'circle_id' => $this->circle->id,
        'is_approved' => true,
        'status' => 'active',
    ]);

    $this->ode = Ode::create(['name' => 'تحفة الأطفال']);
    // Create 15 verses
    for ($i = 1; $i <= 15; $i++) {
        OdeVerse::create([
            'ode_id' => $this->ode->id,
            'verse_number' => $i,
            'sadr' => "الصدر {$i}",
            'ajuz' => "العجز {$i}",
        ]);
    }

    $this->odePath = OdePath::create([
        'ode_id' => $this->ode->id,
        'name' => 'مسار تحفة الأطفال',
        'start_date' => '2026-06-18',
    ]);
});

it('allows supervisor to generate and save a shared ode path schedule', function () {
    $this->actingAs($this->supervisor, 'supervisor');

    Livewire::test(OdePlanCreator::class)
        ->set('odePathId', $this->odePath->id)
        ->set('startDate', '2026-06-18')
        ->set('activeDays', ['Sunday', 'Monday', 'Tuesday', 'Wednesday'])
        ->set('hifzStart', 1)
        ->set('hifzEnd', 10)
        ->set('hifzRate', 2) // 5 days
        ->set('hasReview', true)
        ->set('reviewStart', 11)
        ->set('reviewEnd', 15)
        ->set('reviewRate', 1) // 5 days
        ->call('generatePreview')
        ->assertHasNoErrors()
        ->call('savePlan')
        ->assertHasNoErrors();

    $days = OdePathDay::where('ode_path_id', $this->odePath->id)->orderBy('day_number')->get();
    expect($days)->toHaveCount(5);
    expect($days[0]->from_verse_number)->toBe(1);
    expect($days[0]->to_verse_number)->toBe(2);
    expect($days[0]->review_from_verse_number)->toBe(11);
    expect($days[0]->review_to_verse_number)->toBe(11);
});

it('caps the ode schedule at the end date even when the ode is not fully covered', function () {
    $this->actingAs($this->supervisor, 'supervisor');

    // 15 verses at rate 1 would need 15 days, but the end date only allows 4 active days.
    Livewire::test(OdePlanCreator::class)
        ->set('odePathId', $this->odePath->id)
        ->set('startDate', '2026-06-18')
        ->set('endDate', '2026-06-24')
        ->set('activeDays', ['Sunday', 'Monday', 'Tuesday', 'Wednesday'])
        ->set('hifzStart', 1)
        ->set('hifzEnd', 15)
        ->set('hifzRate', 1)
        ->set('hasReview', false)
        ->call('generatePreview')
        ->assertHasNoErrors()
        ->call('savePlan')
        ->assertHasNoErrors();

    $days = OdePathDay::where('ode_path_id', $this->odePath->id)->orderBy('day_number')->get();
    expect($days)->toHaveCount(4);
    expect($days->last()->date->format('Y-m-d'))->toBeLessThanOrEqual('2026-06-24');
    expect($this->odePath->fresh()->end_date->format('Y-m-d'))->toBe('2026-06-24');
});

it('does not duplicate path days when enrolling students', function () {
    $this->actingAs($this->supervisor, 'supervisor');

    // Pre-create a shared schedule on the path
    OdePathDay::create([
        'ode_path_id' => $this->odePath->id,
        'day_number' => 1,
        'date' => '2026-06-18',
        'day_name' => 'الخميس',
        'from_verse_number' => 1,
        'to_verse_number' => 5,
    ]);

    Livewire::test('supervisor.manage-ode-paths')
        ->call('showEnrollModal', $this->odePath->id)
        ->set('selectedStudentIds', [$this->student->id])
        ->call('enrollStudents')
        ->assertHasNoErrors();

    $plan = StudentOdePlan::where('student_id', $this->student->id)
        ->where('ode_path_id', $this->odePath->id)
        ->first();
    expect($plan)->not->toBeNull();
    expect($plan->status)->toBe('active');

    // The old per-student day table must be gone entirely
    expect(Schema::hasTable('student_ode_plan_days'))->toBeFalse();

    // Only the single shared schedule day exists, regardless of enrollment
    expect(OdePathDay::where('ode_path_id', $this->odePath->id)->count())->toBe(1);
});

it('allows teacher to grade student recitation of ode path days', function () {
    $this->actingAs($this->teacher, 'teacher');

    $plan = StudentOdePlan::create([
        'student_id' => $this->student->id,
        'ode_path_id' => $this->odePath->id,
        'start_date' => '2026-06-18',
        'status' => 'active',
        'created_by_role' => 'teacher',
    ]);

    $day = OdePathDay::create([
        'ode_path_id' => $this->odePath->id,
        'day_number' => 1,
        'date' => '2026-06-18',
        'day_name' => 'الخميس',
        'from_verse_number' => 1,
        'to_verse_number' => 5,
        'review_from_verse_number' => 6,
        'review_to_verse_number' => 10,
    ]);

    // Test grading Hifz
    Livewire::test('teacher.student-tasmeeh-card', [
        'student' => $this->student,
        'sPlans' => collect(),
        'activePlanId' => null,
        'gradedAtDate' => '2026-06-18',
    ])
        ->call('saveOdeAchievement', $day->id, 'hifz', 3) // Excellent
        ->assertHasNoErrors();

    $achievement = StudentOdeAchievement::where([
        'student_ode_plan_id' => $plan->id,
        'ode_path_day_id' => $day->id,
    ])->first();

    expect($achievement)->not->toBeNull();
    expect($achievement->hifz_achievement)->toBe(3);
    expect($achievement->hifz_graded_at)->not->toBeNull();

    // Test grading Review
    Livewire::test('teacher.student-tasmeeh-card', [
        'student' => $this->student,
        'sPlans' => collect(),
        'activePlanId' => null,
        'gradedAtDate' => '2026-06-18',
    ])
        ->call('saveOdeAchievement', $day->id, 'review', 2) // Good
        ->assertHasNoErrors();

    $achievement = $achievement->fresh();
    expect($achievement->review_achievement)->toBe(2);
    expect($achievement->review_graded_at)->not->toBeNull();

    // Grading "لم يسمع" must clear the hifz value and its timestamp (null, not 0).
    Livewire::test('teacher.student-tasmeeh-card', [
        'student' => $this->student,
        'sPlans' => collect(),
        'activePlanId' => null,
        'gradedAtDate' => '2026-06-18',
    ])
        ->call('saveOdeAchievement', $day->id, 'hifz', null)
        ->assertHasNoErrors();

    $achievement = $achievement->fresh();
    expect($achievement->hifz_achievement)->toBeNull();
    expect($achievement->hifz_graded_at)->toBeNull();
});

it('renders odes plans list page for supervisor', function () {
    $this->actingAs($this->supervisor, 'supervisor');

    $response = $this->get(route('supervisor.odes.plans'));
    $response->assertSuccessful();
    $response->assertSee('خطط المنظومات المنشأة');
});

it('renders odes plans list page for teacher', function () {
    $this->actingAs($this->teacher, 'teacher');

    $response = $this->get(route('teacher.ode-plans'));
    $response->assertSuccessful();
    $response->assertSee('خطط المنظومات المنشأة');
});

it('renders ode paths management page for supervisor', function () {
    $this->actingAs($this->supervisor, 'supervisor');

    $response = $this->get(route('supervisor.odes.paths'));
    $response->assertSuccessful();
    $response->assertSee('مسارات حفظ المنظومات');
});

it('allows supervisor and teacher to delete ode plans', function () {
    $this->actingAs($this->teacher, 'teacher');

    $plan = StudentOdePlan::create([
        'student_id' => $this->student->id,
        'ode_path_id' => $this->odePath->id,
        'start_date' => '2026-06-18',
        'status' => 'active',
        'created_by_role' => 'teacher',
    ]);

    Livewire::test('shared.ode-plans-list', ['role' => 'teacher'])
        ->call('deletePlan', $plan->id)
        ->assertHasNoErrors();

    expect(StudentOdePlan::where('id', $plan->id)->exists())->toBeFalse();
});

it('keeps achievements for unchanged days and invalidates changed/later achievements when editing path', function () {
    $this->actingAs($this->supervisor, 'supervisor');

    $plan = StudentOdePlan::create([
        'student_id' => $this->student->id,
        'ode_path_id' => $this->odePath->id,
        'start_date' => '2026-06-18',
        'status' => 'active',
        'created_by_role' => 'supervisor',
    ]);

    $day1 = OdePathDay::create([
        'ode_path_id' => $this->odePath->id,
        'day_number' => 1,
        'date' => '2026-06-18',
        'from_verse_number' => 1,
        'to_verse_number' => 2,
    ]);

    $day2 = OdePathDay::create([
        'ode_path_id' => $this->odePath->id,
        'day_number' => 2,
        'date' => '2026-06-19',
        'from_verse_number' => 3,
        'to_verse_number' => 4,
    ]);

    $day3 = OdePathDay::create([
        'ode_path_id' => $this->odePath->id,
        'day_number' => 3,
        'date' => '2026-06-20',
        'from_verse_number' => 5,
        'to_verse_number' => 6,
    ]);

    StudentOdeAchievement::create([
        'student_ode_plan_id' => $plan->id,
        'ode_path_day_id' => $day1->id,
        'hifz_achievement' => 3,
    ]);

    StudentOdeAchievement::create([
        'student_ode_plan_id' => $plan->id,
        'ode_path_day_id' => $day2->id,
        'hifz_achievement' => 2,
    ]);

    // Modify day 2 (and keep day 1 unchanged) so changes start from day 2
    $newDays = [
        [
            'day_name' => 'الخميس',
            'date' => '2026-06-18',
            'from_verse_number' => 1,
            'to_verse_number' => 2,
            'review_from_verse_number' => null,
            'review_to_verse_number' => null,
        ],
        [
            'day_name' => 'الجمعة',
            'date' => '2026-06-19',
            'from_verse_number' => 3,
            'to_verse_number' => 5, // changed
            'review_from_verse_number' => null,
            'review_to_verse_number' => null,
        ],
        [
            'day_name' => 'السبت',
            'date' => '2026-06-20',
            'from_verse_number' => 6,
            'to_verse_number' => 7,
            'review_from_verse_number' => null,
            'review_to_verse_number' => null,
        ],
    ];

    Livewire::test('shared.ode-plan-creator', [
        'odePathId' => $this->odePath->id,
        'userRole' => 'supervisor',
    ])
        ->set('startDate', '2026-06-18')
        ->set('planDays', $newDays)
        ->call('savePlan')
        ->assertSet('confirmingDeletion', true)
        ->assertSet('affectedAchievementsCount', 1)
        ->assertSet('affectedFromDayNumber', 2)
        ->call('confirmSaveWithDeletion')
        ->assertHasNoErrors();

    $newDay1 = OdePathDay::where('ode_path_id', $this->odePath->id)->where('day_number', 1)->first();
    $newDay2 = OdePathDay::where('ode_path_id', $this->odePath->id)->where('day_number', 2)->first();

    // Day 1 achievement remains (unchanged)
    $ach1 = StudentOdeAchievement::where('student_ode_plan_id', $plan->id)
        ->where('ode_path_day_id', $newDay1->id)
        ->first();
    expect($ach1)->not->toBeNull();
    expect($ach1->hifz_achievement)->toBe(3);

    // Day 2 achievement removed (invalidated)
    $ach2 = StudentOdeAchievement::where('student_ode_plan_id', $plan->id)
        ->where('ode_path_day_id', $newDay2->id)
        ->first();
    expect($ach2)->toBeNull();
});

it('renders both Quranic plan and Ode plan simultaneously in student-tasmeeh-card', function () {
    $this->actingAs($this->teacher, 'teacher');

    Surah::create([
        'id' => 1,
        'number' => 1,
        'name_arabic' => 'الفاتحة',
        'name_simple' => 'Al-Fatihah',
        'revelation_place' => 'makkah',
        'revelation_order' => 1,
        'verses_count' => 7,
        'start_page' => 1,
        'end_page' => 1,
    ]);

    Ayah::create([
        'id' => 1,
        'surah_id' => 1,
        'verse_number' => 1,
        'page_number' => 1,
        'line_number_start' => 1,
        'line_number_end' => 1,
        'verse_key' => '1:1',
        'juz_number' => 1,
        'hizb_number' => 1,
        'rub_number' => 1,
        'ruku_number' => 1,
        'manzil_number' => 1,
        'text_uthmani' => 'Ayah 1 text',
    ]);

    $quranicPlan = StudentPlan::create([
        'student_id' => $this->student->id,
        'teacher_id' => $this->teacher->id,
        'start_date' => '2026-06-18',
        'days_count' => 5,
        'active_days' => [0, 1, 2, 3, 4, 5, 6],
        'status' => 'active',
        'plan_type' => 'hifz',
        'direction' => 'forward',
        'is_approved' => true,
        'created_by_role' => 'teacher',
    ]);

    for ($i = 0; $i < 5; $i++) {
        StudentPlanDay::create([
            'student_plan_id' => $quranicPlan->id,
            'date' => Carbon::parse('2026-06-18')->addDays($i)->format('Y-m-d'),
            'day_name' => 'الأحد',
            'from_ayah_id' => 1,
            'to_ayah_id' => 1,
        ]);
    }

    $odePlan = StudentOdePlan::create([
        'student_id' => $this->student->id,
        'ode_path_id' => $this->odePath->id,
        'start_date' => '2026-06-18',
        'status' => 'active',
        'created_by_role' => 'teacher',
    ]);

    $odeDay = OdePathDay::create([
        'ode_path_id' => $this->odePath->id,
        'day_number' => 1,
        'date' => '2026-06-18',
        'day_name' => 'الخميس',
        'from_verse_number' => 1,
        'to_verse_number' => 5,
    ]);

    $component = Livewire::test('teacher.student-tasmeeh-card', [
        'student' => $this->student,
        'sPlans' => collect([$quranicPlan]),
        'activePlanId' => $quranicPlan->id,
        'gradedAtDate' => '2026-06-18',
    ]);

    // The Quranic plan shows without being picked: every active plan does.
    expect(collect($component->viewData('quranSections'))->map(fn ($section) => $section['plan']->id)->all())
        ->toBe([$quranicPlan->id]);

    $viewData = $component->instance()->with();
    $expectedQuranMap = [];
    $allQuranDays = StudentPlanDay::where('student_plan_id', $quranicPlan->id)->orderBy('date')->get();
    foreach ($allQuranDays as $day) {
        $expectedQuranMap[$day->date->toDateString()] = $day->id;
    }

    expect($viewData['activePlan']->id)->toBe($quranicPlan->id)
        ->and($viewData['activeOdePlan']->id)->toBe($odePlan->id)
        ->and($viewData['odeDayForSelectedDate']->id)->toBe($odeDay->id)
        ->and($viewData['odeDayIds'])->toBe([$odeDay->id])
        ->and($viewData['defaultOdeDayId'])->toBe($odeDay->id)
        ->and($viewData['odeDateToDayIdMap'])->toBe(['2026-06-18' => $odeDay->id])
        ->and($viewData['quranDateToDayIdMap'])->toBe($expectedQuranMap);

    // Each section's days hang on an element keyed by its own plan. The browser
    // reads the days once, when it builds that element's scope, so a section
    // whose plan changed has to be replaced rather than morphed in place.
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="UTF-8">'.$component->html());
    $xpath = new DOMXPath($document);

    $holderKey = fn (string $property) => $xpath
        ->query('//*[contains(@x-data, "'.$property.':")]')
        ->item(0)?->getAttribute('wire:key');

    expect($holderKey('quranDays'))->toBe('quran-plan-'.$quranicPlan->id)
        ->and($holderKey('odeDays'))->toBe('ode-plan-'.$odePlan->id);
});

it('creates gamification transaction when teacher grades ode achievement on active leaderboard', function () {
    $this->actingAs($this->teacher, 'teacher');

    // Create active leaderboard
    $leaderboard = Leaderboard::create([
        'circle_id' => $this->circle->id,
        'title' => 'مسابقة التاج 13',
        'competition_type' => 'gamification',
        'start_date' => now()->subDays(5)->format('Y-m-d'),
        'end_date' => now()->addDays(5)->format('Y-m-d'),
        'is_active' => true,
        'settings' => [
            'ode_hifz_enabled' => true,
            'ode_hifz_excellent_xp' => 10,
            'ode_hifz_excellent_coins' => 10,
            'manual_claim_enabled' => true,
        ],
    ]);
    $leaderboard->circles()->attach($this->circle->id);

    $plan = StudentOdePlan::create([
        'student_id' => $this->student->id,
        'ode_path_id' => $this->odePath->id,
        'start_date' => now()->subDays(5)->format('Y-m-d'),
        'status' => 'active',
        'created_by_role' => 'teacher',
    ]);

    $day = OdePathDay::create([
        'ode_path_id' => $this->odePath->id,
        'day_number' => 1,
        'date' => now()->format('Y-m-d'),
        'day_name' => 'الأحد',
        'from_verse_number' => 1,
        'to_verse_number' => 5,
    ]);

    // Test grading Hifz on the same day
    Livewire::test('teacher.student-tasmeeh-card', [
        'student' => $this->student,
        'sPlans' => collect(),
        'activePlanId' => null,
        'gradedAtDate' => now()->format('Y-m-d'),
    ])
        ->call('saveOdeAchievement', $day->id, 'hifz', 3) // Excellent
        ->assertHasNoErrors();

    // Verify transaction was created in DB
    $transaction = GamificationTransaction::where('student_id', $this->student->id)
        ->where('leaderboard_id', $leaderboard->id)
        ->where('reference_type', StudentOdeAchievement::class)
        ->first();

    expect($transaction)->not->toBeNull();
    expect($transaction->xp_amount)->toBe(10);
    expect($transaction->amount)->toBe(10);
    expect($transaction->claimed_at)->toBeNull();

    // Verify getPendingRewards retrieves it
    $pending = GamificationService::getPendingRewards($this->student->id, $leaderboard->id);
    expect($pending)->toHaveCount(1);
    expect($pending->first()->id)->toBe($transaction->id);
});
