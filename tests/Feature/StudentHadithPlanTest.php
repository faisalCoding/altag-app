<?php

use App\Models\Ayah;
use App\Models\Circle;
use App\Models\GamificationTransaction;
use App\Models\Hadith;
use App\Models\HadithChapter;
use App\Models\HadithLine;
use App\Models\HadithPath;
use App\Models\HadithPathDay;
use App\Models\HadithText;
use App\Models\Leaderboard;
use App\Models\Stage;
use App\Models\Student;
use App\Models\StudentHadithAchievement;
use App\Models\StudentHadithPlan;
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

    $this->hadithText = HadithText::create([
        'name' => 'متن الأحاديث القصار',
        'description' => 'متن الأحاديث القصار',
    ]);

    $this->chapter = HadithChapter::create([
        'hadith_text_id' => $this->hadithText->id,
        'name' => 'كتاب الإيمان',
    ]);

    $this->hadith = Hadith::create([
        'hadith_chapter_id' => $this->chapter->id,
        'name' => 'الأعمال بالنيات',
        'sanad' => 'عمر بن الخطاب',
        'ruling' => 'صحيح',
    ]);

    // Create 15 lines
    for ($i = 1; $i <= 15; $i++) {
        HadithLine::create([
            'hadith_id' => $this->hadith->id,
            'line_number' => $i,
            'text' => "نص السطر {$i}",
        ]);
    }

    $this->hadithPath = HadithPath::create([
        'name' => 'مسار الأحاديث القصار',
        'hadith_text_id' => $this->hadithText->id,
        'memorize_type' => 'hadiths',
        'memorize_amount' => 1,
        'start_date' => '2026-06-18',
    ]);
});

it('allows teacher to grade student recitation of hadith path days', function () {
    $this->actingAs($this->teacher, 'teacher');

    $plan = StudentHadithPlan::create([
        'student_id' => $this->student->id,
        'hadith_path_id' => $this->hadithPath->id,
        'start_date' => '2026-06-18',
        'status' => 'active',
        'created_by_role' => 'teacher',
    ]);

    $day = HadithPathDay::create([
        'hadith_path_id' => $this->hadithPath->id,
        'day_number' => 1,
        'date' => '2026-06-18',
        'day_name' => 'الخميس',
        'memorize_type' => 'lines',
        'memorize_amount' => 5,
        'from_hadith_id' => $this->hadith->id,
        'to_hadith_id' => $this->hadith->id,
        'from_line_number' => 1,
        'to_line_number' => 5,
        'review_from_hadith_id' => $this->hadith->id,
        'review_to_hadith_id' => $this->hadith->id,
        'review_from_line_number' => 6,
        'review_to_line_number' => 10,
    ]);

    // Test grading Hifz
    Livewire::test('teacher.student-tasmeeh-card', [
        'student' => $this->student,
        'sPlans' => collect(),
        'activePlanId' => null,
        'gradedAtDate' => '2026-06-18',
    ])
        ->call('saveHadithAchievement', $day->id, 'hifz', 3) // Excellent
        ->assertHasNoErrors();

    $achievement = StudentHadithAchievement::where([
        'student_hadith_plan_id' => $plan->id,
        'hadith_path_day_id' => $day->id,
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
        ->call('saveHadithAchievement', $day->id, 'review', 2) // Good
        ->assertHasNoErrors();

    $achievement = $achievement->fresh();
    expect($achievement->review_achievement)->toBe(2);
    expect($achievement->review_graded_at)->not->toBeNull();
});

it('renders both Quranic plan and Hadith plan simultaneously in student-tasmeeh-card', function () {
    $this->actingAs($this->teacher, 'teacher');

    // Create dummy Surah and Ayah for database constraints
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

    // Create a Quranic plan
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

    // Create Quranic days
    for ($i = 0; $i < 5; $i++) {
        StudentPlanDay::create([
            'student_plan_id' => $quranicPlan->id,
            'date' => Carbon::parse('2026-06-18')->addDays($i)->format('Y-m-d'),
            'day_name' => 'الأحد',
            'from_ayah_id' => 1,
            'to_ayah_id' => 1,
        ]);
    }

    // Create a Hadith plan
    $hadithPlan = StudentHadithPlan::create([
        'student_id' => $this->student->id,
        'hadith_path_id' => $this->hadithPath->id,
        'start_date' => '2026-06-18',
        'status' => 'active',
        'created_by_role' => 'teacher',
    ]);

    // Create a Hadith path day
    $hadithDay = HadithPathDay::create([
        'hadith_path_id' => $this->hadithPath->id,
        'day_number' => 1,
        'date' => '2026-06-18',
        'day_name' => 'الخميس',
        'memorize_type' => 'lines',
        'memorize_amount' => 5,
        'from_hadith_id' => $this->hadith->id,
        'to_hadith_id' => $this->hadith->id,
        'from_line_number' => 1,
        'to_line_number' => 5,
    ]);

    // Instantiate card component and check it loads both
    $component = Livewire::test('teacher.student-tasmeeh-card', [
        'student' => $this->student,
        'sPlans' => collect([$quranicPlan]),
        'activePlanId' => $quranicPlan->id,
        'gradedAtDate' => '2026-06-18',
    ]);

    // Quranic plan should be selected in the selector
    expect($component->get('selectedPlanId'))->toBe($quranicPlan->id);

    // Both plans should be returned to the view with Alpine mapping variables
    $viewData = $component->instance()->with();
    $expectedQuranMap = [];
    $allQuranDays = StudentPlanDay::where('student_plan_id', $quranicPlan->id)->orderBy('date')->get();
    foreach ($allQuranDays as $day) {
        $expectedQuranMap[$day->date->toDateString()] = $day->id;
    }

    expect($viewData['activePlan']->id)->toBe($quranicPlan->id)
        ->and($viewData['activeHadithPlan']->id)->toBe($hadithPlan->id)
        ->and($viewData['hadithDayForSelectedDate']->id)->toBe($hadithDay->id)
        ->and($viewData['hadithDayIds'])->toBe([$hadithDay->id])
        ->and($viewData['defaultHadithDayId'])->toBe($hadithDay->id)
        ->and($viewData['hadithDateToDayIdMap'])->toBe(['2026-06-18' => $hadithDay->id])
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
        ->and($holderKey('hadithDays'))->toBe('hadith-plan-'.$hadithPlan->id);
});

it('does not duplicate path days when enrolling student', function () {
    $this->actingAs($this->supervisor, 'supervisor');

    // Run the enrollment component test
    Livewire::test('supervisor.manage-hadith-paths')
        ->call('showEnrollModal', $this->hadithPath->id)
        ->set('selectedStudentIds', [$this->student->id])
        ->call('enrollStudents')
        ->assertHasNoErrors();

    // Verify a plan was created
    $plan = StudentHadithPlan::where('student_id', $this->student->id)->where('hadith_path_id', $this->hadithPath->id)->first();
    expect($plan)->not->toBeNull();

    // Confirm that no days table or plan days exist
    expect(Schema::hasTable('student_hadith_plan_days'))->toBeFalse();
});

it('keeps achievements for unchanged days and invalidates changed/later achievements when editing path', function () {
    $this->actingAs($this->supervisor, 'supervisor');

    // Create a student plan
    $plan = StudentHadithPlan::create([
        'student_id' => $this->student->id,
        'hadith_path_id' => $this->hadithPath->id,
        'start_date' => '2026-06-18',
        'status' => 'active',
        'created_by_role' => 'supervisor',
    ]);

    // Create 3 path days
    $day1 = HadithPathDay::create([
        'hadith_path_id' => $this->hadithPath->id,
        'day_number' => 1,
        'date' => '2026-06-18',
        'memorize_type' => 'hadiths',
        'memorize_amount' => 1,
        'from_hadith_id' => $this->hadith->id,
        'to_hadith_id' => $this->hadith->id,
    ]);

    $day2 = HadithPathDay::create([
        'hadith_path_id' => $this->hadithPath->id,
        'day_number' => 2,
        'date' => '2026-06-19',
        'memorize_type' => 'hadiths',
        'memorize_amount' => 1,
        'from_hadith_id' => $this->hadith->id,
        'to_hadith_id' => $this->hadith->id,
    ]);

    $day3 = HadithPathDay::create([
        'hadith_path_id' => $this->hadithPath->id,
        'day_number' => 3,
        'date' => '2026-06-20',
        'memorize_type' => 'hadiths',
        'memorize_amount' => 1,
        'from_hadith_id' => $this->hadith->id,
        'to_hadith_id' => $this->hadith->id,
    ]);

    // Add achievements for day 1 and day 2
    StudentHadithAchievement::create([
        'student_hadith_plan_id' => $plan->id,
        'hadith_path_day_id' => $day1->id,
        'hifz_achievement' => 3,
    ]);

    StudentHadithAchievement::create([
        'student_hadith_plan_id' => $plan->id,
        'hadith_path_day_id' => $day2->id,
        'hifz_achievement' => 2,
    ]);

    // Launch plan creator and update days
    // We simulate modifying day 2 and day 3 (so change starts from day 2)
    $newDays = [
        [
            'day_name' => 'الخميس',
            'memorize_type' => 'hadiths',
            'memorize_amount' => 1,
            'from_hadith_id' => $this->hadith->id,
            'to_hadith_id' => $this->hadith->id,
            'from_line_number' => null,
            'to_line_number' => null,
            'date' => '2026-06-18',
        ],
        [
            // Modifying day 2 (change from hadiths to lines)
            'day_name' => 'الجمعة',
            'memorize_type' => 'lines',
            'memorize_amount' => 5,
            'from_hadith_id' => $this->hadith->id,
            'to_hadith_id' => $this->hadith->id,
            'from_line_number' => 1,
            'to_line_number' => 5,
            'date' => '2026-06-19',
        ],
        [
            'day_name' => 'السبت',
            'memorize_type' => 'hadiths',
            'memorize_amount' => 1,
            'from_hadith_id' => $this->hadith->id,
            'to_hadith_id' => $this->hadith->id,
            'from_line_number' => null,
            'to_line_number' => null,
            'date' => '2026-06-20',
        ],
    ];

    Livewire::test('shared.hadith-plan-creator', [
        'hadithPathId' => $this->hadithPath->id,
        'userRole' => 'supervisor',
    ])
        ->set('startDate', '2026-06-18')
        ->set('planDays', $newDays)
        ->call('savePlan')
        // Should require confirmation because achievements are affected on Day 2
        ->assertSet('confirmingDeletion', true)
        ->assertSet('affectedAchievementsCount', 1) // Day 2 achievement is affected
        ->assertSet('affectedFromDayNumber', 2)
        ->call('confirmSaveWithDeletion')
        ->assertHasNoErrors();

    // Verify achievements:
    // Day 1 achievement should remain (the new day 1 has same path day id or is unchanged index 1)
    // Wait, the path days were recreated. Let's see: new day 1 is created.
    $newDay1 = HadithPathDay::where('hadith_path_id', $this->hadithPath->id)->where('day_number', 1)->first();
    $newDay2 = HadithPathDay::where('hadith_path_id', $this->hadithPath->id)->where('day_number', 2)->first();

    // Check achievement for Day 1 is migrated/re-linked to the new Day 1
    $ach1 = StudentHadithAchievement::where('student_hadith_plan_id', $plan->id)
        ->where('hadith_path_day_id', $newDay1->id)
        ->first();
    expect($ach1)->not->toBeNull();
    expect($ach1->hifz_achievement)->toBe(3);

    // Day 2 achievement should be deleted/cleared since it was modified/invalidated
    $ach2 = StudentHadithAchievement::where('student_hadith_plan_id', $plan->id)
        ->where('hadith_path_day_id', $newDay2->id)
        ->first();
    expect($ach2)->toBeNull();
});

it('creates gamification transaction when teacher grades hadith achievement on active leaderboard', function () {
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
            'hadith_hifz_enabled' => true,
            'hadith_hifz_excellent_xp' => 10,
            'hadith_hifz_excellent_coins' => 10,
            'manual_claim_enabled' => true,
        ],
    ]);
    $leaderboard->circles()->attach($this->circle->id);

    $plan = StudentHadithPlan::create([
        'student_id' => $this->student->id,
        'hadith_path_id' => $this->hadithPath->id,
        'start_date' => now()->subDays(5)->format('Y-m-d'),
        'status' => 'active',
        'created_by_role' => 'teacher',
    ]);

    $day = HadithPathDay::create([
        'hadith_path_id' => $this->hadithPath->id,
        'day_number' => 1,
        'date' => now()->format('Y-m-d'),
        'day_name' => 'الأحد',
        'memorize_type' => 'lines',
        'memorize_amount' => 5,
        'from_hadith_id' => $this->hadith->id,
        'to_hadith_id' => $this->hadith->id,
        'from_line_number' => 1,
        'to_line_number' => 5,
    ]);

    // Test grading Hifz on the same day
    Livewire::test('teacher.student-tasmeeh-card', [
        'student' => $this->student,
        'sPlans' => collect(),
        'activePlanId' => null,
        'gradedAtDate' => now()->format('Y-m-d'),
    ])
        ->call('saveHadithAchievement', $day->id, 'hifz', 3) // Excellent
        ->assertHasNoErrors();

    // Verify transaction was created in DB
    $transaction = GamificationTransaction::where('student_id', $this->student->id)
        ->where('leaderboard_id', $leaderboard->id)
        ->where('reference_type', StudentHadithAchievement::class)
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
