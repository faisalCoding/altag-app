<?php

use App\Livewire\Teacher\Attendance;
use App\Livewire\Teacher\LeaderboardGrade;
use App\Livewire\Teacher\Leaderboards;
use App\Models\AcademicCalendarEvent;
use App\Models\Ayah;
use App\Models\Circle;
use App\Models\Leaderboard;
use App\Models\Student;
use App\Models\StudentPlan;
use App\Models\StudentPlanDay;
use App\Models\Surah;
use App\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Create dummy Surah and Ayah for plans eager loading
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

    // Setup teacher, circle, 20 students, and plans/days
    $this->teacher = Teacher::factory()->create();
    $this->circle = Circle::factory()->create();
    $this->teacher->circles()->attach($this->circle->id);

    $this->students = Student::factory()->count(20)->create([
        'circle_id' => $this->circle->id,
        'status' => 'active',
        'is_approved' => true,
    ]);

    $today = now();
    foreach ($this->students as $student) {
        $plan = StudentPlan::create([
            'student_id' => $student->id,
            'teacher_id' => $this->teacher->id,
            'start_date' => $today->copy()->subDays(5),
            'days_count' => 30,
            'active_days' => [0, 1, 2, 3, 4, 5, 6],
            'description' => 'Test Plan',
            'status' => 'active',
            'plan_type' => 'hifz_review',
            'direction' => 'forward',
            'is_approved' => true,
            'created_by_role' => 'teacher',
        ]);

        for ($i = 0; $i < 30; $i++) {
            StudentPlanDay::create([
                'student_plan_id' => $plan->id,
                'date' => $today->copy()->subDays(5)->addDays($i)->format('Y-m-d'),
                'day_name' => $today->copy()->subDays(5)->addDays($i)->dayName,
                'from_ayah_id' => 1,
                'to_ayah_id' => 1,
                'review_from_ayah_id' => 1,
                'review_to_ayah_id' => 1,
                'hifz_achievement' => $i < 5 ? 100 : null,
                'review_achievement' => $i < 5 ? 100 : null,
            ]);
        }
    }

    $this->actingAs($this->teacher, 'teacher');
});

test('teacher tasmeeh-manager renders efficiently within budgets', function () {
    gc_collect_cycles();
    $memBefore = memory_get_usage();

    DB::enableQueryLog();
    Livewire::test('teacher.⚡tasmeeh-manager');
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    gc_collect_cycles();
    $memAfter = memory_get_usage();

    $queryCount = count($queries);
    $memUsed = $memAfter - $memBefore;

    // Assert optimized queries (budget is < 23 queries, usually 20)
    expect($queryCount)->toBeLessThan(23);
    // Assert memory allocation is low (budget is 15MB)
    expect($memUsed)->toBeLessThan(15 * 2048 * 2048);
});

test('teacher attendance component renders efficiently within budgets', function () {
    gc_collect_cycles();
    $memBefore = memory_get_usage();

    DB::enableQueryLog();
    Livewire::test(Attendance::class);
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    gc_collect_cycles();
    $memAfter = memory_get_usage();

    $queryCount = count($queries);
    $memUsed = $memAfter - $memBefore;

    // Attendance component renders lists of students. It should execute few queries (budget < 15 queries)
    expect($queryCount)->toBeLessThan(15);
    expect($memUsed)->toBeLessThan(15 * 2048 * 2048);
});

test('teacher student-manager component renders efficiently within budgets', function () {
    gc_collect_cycles();
    $memBefore = memory_get_usage();

    DB::enableQueryLog();
    Livewire::test('teacher.⚡student-manager');
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    gc_collect_cycles();
    $memAfter = memory_get_usage();

    $queryCount = count($queries);
    $memUsed = $memAfter - $memBefore;

    // Student manager component lists circle students.
    expect($queryCount)->toBeLessThan(15);
    expect($memUsed)->toBeLessThan(15 * 2048 * 2048);
});

test('shared plan-creator component renders efficiently within budgets', function () {
    gc_collect_cycles();
    $memBefore = memory_get_usage();

    DB::enableQueryLog();
    Livewire::test('shared.⚡plan-creator');
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    gc_collect_cycles();
    $memAfter = memory_get_usage();

    $queryCount = count($queries);
    $memUsed = $memAfter - $memBefore;

    // Plan creator should render with minimal queries.
    expect($queryCount)->toBeLessThan(15);
    expect($memUsed)->toBeLessThan(15 * 2048 * 2048);
});

test('teacher leaderboards component renders efficiently within budgets', function () {
    gc_collect_cycles();
    $memBefore = memory_get_usage();

    DB::enableQueryLog();
    Livewire::test(Leaderboards::class);
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    gc_collect_cycles();
    $memAfter = memory_get_usage();

    $queryCount = count($queries);
    $memUsed = $memAfter - $memBefore;

    // Leaderboards list circle leaderboards.
    expect($queryCount)->toBeLessThan(15);
    expect($memUsed)->toBeLessThan(15 * 2048 * 2048);
});

test('teacher student-manager dispatches student-list-updated event on student creation', function () {
    $teacher = $this->teacher;
    $permissions = $teacher->permissions ?? [];
    $permissions['can_create_students'] = true;
    $teacher->permissions = $permissions;
    $teacher->save();

    Livewire::test('teacher.⚡student-manager')
        ->set('names', 'New Student، 0500000000')
        ->call('createStudent')
        ->assertDispatched('student-list-updated');
});

test('teacher attendance component listens to student-list-updated event', function () {
    Livewire::test(Attendance::class)
        ->dispatch('student-list-updated')
        ->assertStatus(200);
});

test('teacher tasmeeh-manager component listens to student-list-updated event', function () {
    Livewire::test('teacher.⚡tasmeeh-manager')
        ->dispatch('student-list-updated')
        ->assertStatus(200);
});

test('teacher leaderboard-grade component listens to student-list-updated event', function () {
    $leaderboard = Leaderboard::create([
        'circle_id' => $this->circle->id,
        'title' => 'Test Leaderboard',
        'is_active' => true,
        'start_date' => now(),
        'end_date' => now()->addDays(7),
    ]);

    Livewire::test(LeaderboardGrade::class, ['leaderboardId' => $leaderboard->id])
        ->dispatch('student-list-updated')
        ->assertStatus(200);
});

test('plan creator verifies academic calendar with multi-period distribution and gaps', function () {
    // 1. Create period A
    AcademicCalendarEvent::create([
        'event_name' => 'الفترة الأولى',
        'start_date' => '2026-06-01',
        'end_date' => '2026-06-15',
        'is_attendance_period' => true,
        'weekdays' => [1, 2, 3, 4], // Sun, Mon, Tue, Wed
        'is_visible' => true,
    ]);

    // 2. Create period B (starts after a gap of 5 days)
    AcademicCalendarEvent::create([
        'event_name' => 'الفترة الثانية',
        'start_date' => '2026-06-21',
        'end_date' => '2026-07-05',
        'is_attendance_period' => true,
        'weekdays' => [1, 2, 3, 4, 5], // Sun, Mon, Tue, Wed, Thu
        'is_visible' => true,
    ]);

    // Test component behavior
    $component = Livewire::test('shared.⚡plan-creator')
        ->set('startDate', '2026-06-01')
        ->set('daysCount', 15)
        ->call('checkAttendancePeriod');

    $component->assertSet('isOutsidePeriod', false)
        ->assertSet('expectedEndDate', '2026-06-24')
        ->assertSet('totalCalendarDays', 24) // June 1 to June 24 is 24 calendar days
        ->assertSet('periodDistribution', [
            'الفترة الأولى' => 9,
            'خارج فترات الدوام' => 2,
            'الفترة الثانية' => 4,
        ]);
});

test('plan creator applies review ceiling on auto fill', function () {
    // Let's create more surahs and ayahs so we can test boundaries
    // Surah 1 has 7 verses.
    for ($i = 2; $i <= 7; $i++) {
        Ayah::create([
            'id' => $i,
            'surah_id' => 1,
            'verse_number' => $i,
            'page_number' => 1,
            'line_number_start' => $i,
            'line_number_end' => $i,
            'verse_key' => "1:$i",
            'juz_number' => 1,
            'hizb_number' => 1,
            'rub_number' => 1,
            'ruku_number' => 1,
            'manzil_number' => 1,
            'text_uthmani' => "Ayah $i of Surah 1",
        ]);
    }

    // Let's create Surah 2 with 5 verses.
    Surah::create([
        'id' => 2,
        'number' => 2,
        'name_arabic' => 'البقرة',
        'name_simple' => 'Al-Baqarah',
        'revelation_place' => 'madinah',
        'revelation_order' => 2,
        'verses_count' => 5,
        'start_page' => 2,
        'end_page' => 2,
    ]);

    for ($i = 1; $i <= 5; $i++) {
        Ayah::create([
            'id' => 15 + $i,
            'surah_id' => 2,
            'verse_number' => $i,
            'page_number' => 2,
            'line_number_start' => $i,
            'line_number_end' => $i,
            'verse_key' => "2:$i",
            'juz_number' => 1,
            'hizb_number' => 1,
            'rub_number' => 1,
            'ruku_number' => 1,
            'manzil_number' => 1,
            'text_uthmani' => "Ayah $i of Surah 2",
        ]);
    }

    // Now test the component
    $component = Livewire::test('shared.⚡plan-creator')
        ->set('studentId', $this->students->first()->id)
        ->set('planType', 'review')
        ->set('fillDirection', 'forward')
        ->set('startDate', '2026-06-01')
        ->set('daysCount', 5)
        ->set('activeDays', ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'])
        ->call('generateDays');

    // First: set ceiling to Surah 1, Verse 5, and fill days 0, 1, 2
    $component->set('memorizedUpToSurah', 1)
        ->set('memorizedUpToVerse', 5)
        ->call('fillSelected', 'surah', 'review', [0, 1, 2]);

    // Second: change ceiling to Surah 2, Verse 3, and fill days 3, 4
    $component->set('memorizedUpToSurah', 2)
        ->set('memorizedUpToVerse', 3)
        ->call('fillSelected', 'surah', 'review', [3, 4]);

    // Verify first 3 days respect the first ceiling (Surah 1, Verse 5)
    $planDays = $component->get('planDays');
    for ($i = 0; $i <= 2; $i++) {
        expect($planDays[$i]['review_to_surah_id'])->toBeLessThanOrEqual(1);
        if ($planDays[$i]['review_to_surah_id'] === 1) {
            expect($planDays[$i]['review_to_verse'])->toBeLessThanOrEqual(5);
        }
    }

    // Verify day 3 & 4 respect the second ceiling (Surah 2, Verse 3)
    for ($i = 3; $i <= 4; $i++) {
        expect($planDays[$i]['review_to_surah_id'])->toBeLessThanOrEqual(2);
        if ($planDays[$i]['review_to_surah_id'] === 2) {
            expect($planDays[$i]['review_to_verse'])->toBeLessThanOrEqual(3);
        }
    }
});

test('plan creator supports dynamic custom pages auto fill', function () {
    // We want to test filling 2 pages.
    // Let's create Surah 3 with 40 verses (so we have enough verses to fill pages)
    Surah::create([
        'id' => 3,
        'number' => 3,
        'name_arabic' => 'آل عمران',
        'name_simple' => 'Al-Imran',
        'revelation_place' => 'madinah',
        'revelation_order' => 3,
        'verses_count' => 40,
        'start_page' => 3,
        'end_page' => 5,
    ]);

    for ($i = 1; $i <= 40; $i++) {
        // Let's spread verses across 3 pages: 15 on page 3, 15 on page 4, 10 on page 5
        $page = 3;
        if ($i > 30) {
            $page = 5;
        } elseif ($i > 15) {
            $page = 4;
        }

        $line = $i % 15 ?: 15;

        Ayah::create([
            'id' => 30 + $i,
            'surah_id' => 3,
            'verse_number' => $i,
            'page_number' => $page,
            'line_number_start' => $line,
            'line_number_end' => $line,
            'verse_key' => "3:$i",
            'juz_number' => 1,
            'hizb_number' => 1,
            'rub_number' => 1,
            'ruku_number' => 1,
            'manzil_number' => 1,
            'text_uthmani' => "Ayah $i of Surah 3",
        ]);
    }

    $component = Livewire::test('shared.⚡plan-creator')
        ->set('studentId', $this->students->first()->id)
        ->set('planType', 'hifz')
        ->set('fillDirection', 'forward')
        ->set('startDate', '2026-06-01')
        ->set('daysCount', 3)
        ->set('activeDays', ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'])
        ->call('generateDays');

    // Set first day's start to Surah 3, Verse 1
    $component->set('planDays.0.from_surah_id', 3)
        ->set('planDays.0.from_verse', 1);

    // Call custom_pages_2 (2 pages = 30 lines) on the first day
    $component->call('fillSelected', 'custom_pages_2', 'hifz', [0]);

    $planDays = $component->get('planDays');
    // First day should end at Surah 3, Verse 30 (since verse 1 to 15 is 15 lines/page 1, and 16 to 30 is 15 lines/page 2)
    expect($planDays[0]['to_surah_id'])->toBe(3);
    expect($planDays[0]['to_verse'])->toBe(30);
});

test('plan creator applies page alignment rules on review but not on hifz', function () {
    // We want to test page-fill optimization rules for 'review' plan type.
    // Let's create:
    // Surah 4: 30 verses (lines). Ends at page 7, line 10.
    // Surah 5: 10 verses (lines). Ends at page 8, line 5.
    // Surah 6: 30 verses (lines). Ends at page 10, line 5.
    // Let's make them contiguous starting from page 5, line 11 (after Surah 3's end at page 5, line 10).

    $currentPage = 5;
    $currentLine = 11;

    $createContiguousSurah = function (int $surahId, int $versesCount) use (&$currentPage, &$currentLine) {
        Surah::create([
            'id' => $surahId,
            'number' => $surahId,
            'name_arabic' => 'سورة '.$surahId,
            'name_simple' => 'Surah '.$surahId,
            'revelation_place' => 'makkah',
            'revelation_order' => $surahId,
            'verses_count' => $versesCount,
            'start_page' => $currentPage,
            'end_page' => $currentPage + ceil(($currentLine - 1 + $versesCount) / 15) - 1,
        ]);

        for ($v = 1; $v <= $versesCount; $v++) {
            Ayah::create([
                'id' => $surahId * 1000 + $v,
                'surah_id' => $surahId,
                'verse_number' => $v,
                'page_number' => $currentPage,
                'line_number_start' => $currentLine,
                'line_number_end' => $currentLine,
                'verse_key' => "$surahId:$v",
                'juz_number' => 1,
                'hizb_number' => 1,
                'rub_number' => 1,
                'ruku_number' => 1,
                'manzil_number' => 1,
                'text_uthmani' => "Ayah $v of Surah $surahId",
            ]);
            $currentLine++;
            if ($currentLine > 15) {
                $currentLine = 1;
                $currentPage++;
            }
        }
    };

    $createContiguousSurah(4, 30); // Surah 4: 30 verses.
    $createContiguousSurah(5, 10); // Surah 5: 10 verses.
    $createContiguousSurah(6, 30); // Surah 6: 30 verses.

    // Scenario 1: Rule 1 (Surah Completion)
    // Starting at Surah 4, Verse 1. Volume = 1 page (15 lines).
    // Remaining in Surah 4 is 15 lines. Since 15 <= 15, it should complete Surah 4 (ending at Surah 4, Verse 30).

    // First, test with 'review' plan type (should apply optimization)
    $component = Livewire::test('shared.⚡plan-creator')
        ->set('studentId', $this->students->first()->id)
        ->set('planType', 'review')
        ->set('fillDirection', 'forward')
        ->set('startDate', '2026-06-01')
        ->set('daysCount', 3)
        ->set('activeDays', ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'])
        ->set('memorizedUpToSurah', 6)
        ->set('memorizedUpToVerse', 30)
        ->call('generateDays');

    $component->set('planDays.0.review_from_surah_id', 4)
        ->set('planDays.0.review_from_verse', 1)
        ->call('fillSelected', 'page', 'review', [0]);

    $planDays = $component->get('planDays');
    expect($planDays[0]['review_to_surah_id'])->toBe(4);
    expect($planDays[0]['review_to_verse'])->toBe(30); // Completed!

    // Second, test with 'hifz' plan type (should NOT apply optimization)
    $componentHifz = Livewire::test('shared.⚡plan-creator')
        ->set('studentId', $this->students->first()->id)
        ->set('planType', 'hifz')
        ->set('fillDirection', 'forward')
        ->set('startDate', '2026-06-01')
        ->set('daysCount', 3)
        ->set('activeDays', ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'])
        ->call('generateDays');

    $componentHifz->set('planDays.0.from_surah_id', 4)
        ->set('planDays.0.from_verse', 1)
        ->call('fillSelected', 'page', 'hifz', [0]);

    $planDaysHifz = $componentHifz->get('planDays');
    expect($planDaysHifz[0]['to_surah_id'])->toBe(4);
    expect($planDaysHifz[0]['to_verse'])->toBe(15); // Not completed (exactly 1 page / 15 lines)

    // Scenario 2: Rule 2 Scenario A (New surah <= 15 lines total, complete it)
    // Starting at Surah 4, Verse 20. Volume = 1 page (15 lines).
    // Remaining in Surah 4 is 11 lines. New surah is Surah 5 (10 lines total).
    // Normal traverse ends at Surah 5, Verse 4 (11 + 4 = 15 lines).
    // Since lines in Surah 5 is 4 (< 15) and total size is 10 (<= 15), it should complete Surah 5 (ends at Surah 5, Verse 10).

    // Test with 'review' plan type (should apply optimization)
    $component->set('planDays.0.review_from_surah_id', 4)
        ->set('planDays.0.review_from_verse', 20)
        ->call('fillSelected', 'page', 'review', [0]);

    $planDays = $component->get('planDays');
    expect($planDays[0]['review_to_surah_id'])->toBe(5);
    expect($planDays[0]['review_to_verse'])->toBe(10); // Completed!

    // Test with 'hifz' plan type (should NOT apply optimization)
    $componentHifz->set('planDays.0.from_surah_id', 4)
        ->set('planDays.0.from_verse', 20)
        ->call('fillSelected', 'page', 'hifz', [0]);

    $planDaysHifz = $componentHifz->get('planDays');
    expect($planDaysHifz[0]['to_surah_id'])->toBe(5);
    expect($planDaysHifz[0]['to_verse'])->toBe(4); // Not completed (exactly 15 lines total)

    // Scenario 3: Rule 2 Scenario B (New surah > 15 lines total, read exactly 15 lines of it)
    // Starting at Surah 5, Verse 5. Volume = 1 page (15 lines).
    // Remaining in Surah 5 is 6 lines. New surah is Surah 6 (30 lines total).
    // Normal traverse ends at Surah 6, Verse 9 (6 + 9 = 15 lines).
    // Since lines in Surah 6 is 9 (< 15) and total size is 30 (> 15), it should extend to 15 lines of Surah 6 (ends at Surah 6, Verse 15).

    // Test with 'review' plan type (should apply optimization)
    $component->set('planDays.0.review_from_surah_id', 5)
        ->set('planDays.0.review_from_verse', 5)
        ->call('fillSelected', 'page', 'review', [0]);

    $planDays = $component->get('planDays');
    expect($planDays[0]['review_to_surah_id'])->toBe(6);
    expect($planDays[0]['review_to_verse'])->toBe(15); // Extended to 15 lines of the new surah!

    // Test with 'hifz' plan type (should NOT apply optimization)
    $componentHifz->set('planDays.0.from_surah_id', 5)
        ->set('planDays.0.from_verse', 5)
        ->call('fillSelected', 'page', 'hifz', [0]);

    $planDaysHifz = $componentHifz->get('planDays');
    expect($planDaysHifz[0]['to_surah_id'])->toBe(6);
    expect($planDaysHifz[0]['to_verse'])->toBe(9); // Not completed/extended (exactly 15 lines total)
});

test('plan creator supports reverse review auto fill and progresses towards Fatihah', function () {
    // Create verses 2-7 of Surah 1
    for ($i = 2; $i <= 7; $i++) {
        Ayah::create([
            'id' => $i,
            'surah_id' => 1,
            'verse_number' => $i,
            'page_number' => 1,
            'line_number_start' => $i,
            'line_number_end' => $i,
            'verse_key' => "1:$i",
            'juz_number' => 1,
            'hizb_number' => 1,
            'rub_number' => 1,
            'ruku_number' => 1,
            'manzil_number' => 1,
            'text_uthmani' => "Ayah $i of Surah 1",
        ]);
    }

    // Create Surah 2 with 5 verses
    Surah::create([
        'id' => 2,
        'number' => 2,
        'name_arabic' => 'البقرة',
        'name_simple' => 'Al-Baqarah',
        'revelation_place' => 'madinah',
        'revelation_order' => 2,
        'verses_count' => 5,
        'start_page' => 2,
        'end_page' => 2,
    ]);

    for ($i = 1; $i <= 5; $i++) {
        Ayah::create([
            'id' => 15 + $i,
            'surah_id' => 2,
            'verse_number' => $i,
            'page_number' => 2,
            'line_number_start' => $i,
            'line_number_end' => $i,
            'verse_key' => "2:$i",
            'juz_number' => 1,
            'hizb_number' => 1,
            'rub_number' => 1,
            'ruku_number' => 1,
            'manzil_number' => 1,
            'text_uthmani' => "Ayah $i of Surah 2",
        ]);
    }

    $component = Livewire::test('shared.⚡plan-creator')
        ->set('studentId', $this->students->first()->id)
        ->set('planType', 'review')
        ->set('fillDirection', 'reverse')
        ->set('startDate', '2026-06-01')
        ->set('daysCount', 3)
        ->set('activeDays', ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'])
        ->call('generateDays');

    $component->set('planDays.0.review_from_surah_id', 2)
        ->set('planDays.0.review_from_verse', 1)
        ->set('memorizedUpToSurah', 1)
        ->set('memorizedUpToVerse', 7)
        ->call('fillSelected', 'third', 'review', [0, 1, 2]);

    $planDays = $component->get('planDays');

    // Day 1: Surah 2, Verse 1 to Surah 2, Verse 5 (5 lines total)
    expect($planDays[0]['review_from_surah_id'])->toBe(2);
    expect($planDays[0]['review_from_verse'])->toBe(1);
    expect($planDays[0]['review_to_surah_id'])->toBe(2);
    expect($planDays[0]['review_to_verse'])->toBe(5);

    // Day 2: should progress to Surah 1, Verse 1 and read 5 lines (auto-completes to Verse 7 due to page optimization)
    expect($planDays[1]['review_from_surah_id'])->toBe(1);
    expect($planDays[1]['review_from_verse'])->toBe(1);
    expect($planDays[1]['review_to_surah_id'])->toBe(1);
    expect($planDays[1]['review_to_verse'])->toBe(7);

    // Day 3: should loop back to Surah 2, Verse 1
    expect($planDays[2]['review_from_surah_id'])->toBe(2);
    expect($planDays[2]['review_from_verse'])->toBe(1);
    expect($planDays[2]['review_to_surah_id'])->toBe(2);
    expect($planDays[2]['review_to_verse'])->toBe(5);
});

test('plan creator supports hifz ceiling and wrap around', function () {
    // Create Surah 30 (Ar-Rum) with 3 verses
    Surah::create([
        'id' => 30,
        'number' => 30,
        'name_arabic' => 'الروم',
        'name_simple' => 'Ar-Rum',
        'revelation_place' => 'makkah',
        'revelation_order' => 84,
        'verses_count' => 3,
        'start_page' => 400,
        'end_page' => 400,
    ]);

    for ($i = 1; $i <= 3; $i++) {
        Ayah::create([
            'id' => 300 + $i,
            'surah_id' => 30,
            'verse_number' => $i,
            'page_number' => 400,
            'line_number_start' => $i,
            'line_number_end' => $i,
            'verse_key' => "30:$i",
            'juz_number' => 21,
            'hizb_number' => 41,
            'rub_number' => 1,
            'ruku_number' => 1,
            'manzil_number' => 1,
            'text_uthmani' => "Ar-Rum Ayah $i",
        ]);
    }

    // Create Surah 31 (Luqman) with 3 verses
    Surah::create([
        'id' => 31,
        'number' => 31,
        'name_arabic' => 'لقمان',
        'name_simple' => 'Luqman',
        'revelation_place' => 'makkah',
        'revelation_order' => 85,
        'verses_count' => 3,
        'start_page' => 401,
        'end_page' => 401,
    ]);

    for ($i = 1; $i <= 3; $i++) {
        Ayah::create([
            'id' => 310 + $i,
            'surah_id' => 31,
            'verse_number' => $i,
            'page_number' => 401,
            'line_number_start' => $i,
            'line_number_end' => $i,
            'verse_key' => "31:$i",
            'juz_number' => 21,
            'hizb_number' => 41,
            'rub_number' => 1,
            'ruku_number' => 1,
            'manzil_number' => 1,
            'text_uthmani' => "Luqman Ayah $i",
        ]);
    }

    // Create Al-Fatihah (Surah 1) Ayah 2 to 5 if not exists (Surah 1 Ayah 1 is already in beforeEach)
    for ($i = 2; $i <= 5; $i++) {
        Ayah::create([
            'id' => 10 + $i,
            'surah_id' => 1,
            'verse_number' => $i,
            'page_number' => 1,
            'line_number_start' => $i,
            'line_number_end' => $i,
            'verse_key' => "1:$i",
            'juz_number' => 1,
            'hizb_number' => 1,
            'rub_number' => 1,
            'ruku_number' => 1,
            'manzil_number' => 1,
            'text_uthmani' => "Al-Fatihah Ayah $i",
        ]);
    }

    $component = Livewire::test('shared.⚡plan-creator')
        ->set('studentId', $this->students->first()->id)
        ->set('planType', 'hifz')
        ->set('fillDirection', 'forward')
        ->set('startDate', '2026-06-01')
        ->set('daysCount', 3)
        ->set('activeDays', ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'])
        ->call('generateDays');

    // Day 1: start at Surah 30, Verse 1.
    // Set Hifz ceiling (memorizedUpToSurah) to Surah 30, Verse 3 (Ar-Rum last Ayah)
    $component->set('planDays.0.from_surah_id', 30)
        ->set('planDays.0.from_verse', 1)
        ->set('memorizedUpToSurah', 30)
        ->set('memorizedUpToVerse', 3)
        ->call('fillSelected', 'page', 'hifz', [0, 1, 2]);

    $planDays = $component->get('planDays');

    // Day 1: Ar-Rum 1 to Ar-Rum 3 (since volume 'page' is 15 lines, and Ar-Rum 1-3 is 3 lines, but capped at Ar-Rum 3)
    expect($planDays[0]['from_surah_id'])->toBe(30);
    expect($planDays[0]['from_verse'])->toBe(1);
    expect($planDays[0]['to_surah_id'])->toBe(30);
    expect($planDays[0]['to_verse'])->toBe(3);

    // Day 2: should wrap around to Al-Fatihah (Surah 1), Verse 1 and read 15 lines (capped at 5 since we created only 5 ayahs)
    expect($planDays[1]['from_surah_id'])->toBe(1);
    expect($planDays[1]['from_verse'])->toBe(1);
    expect($planDays[1]['to_surah_id'])->toBe(1);
    expect($planDays[1]['to_verse'])->toBe(5);
});

test('plan creator handles reverse hifz and forward review with static review ceiling correctly', function () {
    // Create Surah 58 (Al-Mujadilah) with 22 verses
    Surah::create([
        'id' => 58,
        'number' => 58,
        'name_arabic' => 'المجادلة',
        'name_simple' => 'Al-Mujadilah',
        'revelation_place' => 'madinah',
        'revelation_order' => 105,
        'verses_count' => 22,
        'start_page' => 542,
        'end_page' => 545,
    ]);

    for ($i = 1; $i <= 22; $i++) {
        $page = 542;
        $line = $i;
        if ($i > 15) {
            $page = 543;
            $line = $i - 15;
        }

        Ayah::create([
            'id' => 58000 + $i,
            'surah_id' => 58,
            'verse_number' => $i,
            'page_number' => $page,
            'line_number_start' => $line,
            'line_number_end' => $line,
            'verse_key' => "58:$i",
            'juz_number' => 28,
            'hizb_number' => 55,
            'rub_number' => 1,
            'ruku_number' => 1,
            'manzil_number' => 1,
            'text_uthmani' => "Al-Mujadilah Ayah $i",
        ]);
    }

    // Create Surah 114 (An-Nas) with 6 verses
    Surah::create([
        'id' => 114,
        'number' => 114,
        'name_arabic' => 'الناس',
        'name_simple' => 'Al-Nas',
        'revelation_place' => 'makkah',
        'revelation_order' => 21,
        'verses_count' => 6,
        'start_page' => 604,
        'end_page' => 604,
    ]);

    for ($i = 1; $i <= 6; $i++) {
        Ayah::create([
            'id' => 114000 + $i,
            'surah_id' => 114,
            'verse_number' => $i,
            'page_number' => 604,
            'line_number_start' => $i,
            'line_number_end' => $i,
            'verse_key' => "114:$i",
            'juz_number' => 30,
            'hizb_number' => 60,
            'rub_number' => 1,
            'ruku_number' => 1,
            'manzil_number' => 1,
            'text_uthmani' => "Al-Nas Ayah $i",
        ]);
    }

    $component = Livewire::test('shared.⚡plan-creator')
        ->set('studentId', $this->students->first()->id)
        ->set('planType', 'review')
        ->set('fillDirection', 'reverse')
        ->set('reviewDirection', 'forward')
        ->set('startDate', '2026-06-01')
        ->set('daysCount', 1)
        ->set('activeDays', ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'])
        ->call('generateDays');

    // First day review start is set to Surah 58 Verse 1
    $component->set('planDays.0.review_from_surah_id', 58)
        ->set('planDays.0.review_from_verse', 1);

    // Set the static review ceiling to Surah 58, Verse 22
    $component->set('memorizedUpToSurah', 58)
        ->set('memorizedUpToVerse', 22);

    // Fill selected days with page volume
    $component->call('fillSelected', 'page', 'review', [0]);

    $planDays = $component->get('planDays');

    // Ensure it started at Surah 58 Verse 1, and went forward to Surah 58 Verse 22 (due to page optimization surah completion)
    expect($planDays[0]['review_from_surah_id'])->toBe(58);
    expect($planDays[0]['review_from_verse'])->toBe(1);
    expect($planDays[0]['review_to_surah_id'])->toBe(58);
    expect($planDays[0]['review_to_verse'])->toBe(22);
});

test('plan creator resolves duplicate review days by calculating backwards from ceiling when hit', function () {
    // Create Surahs and Ayahs for testing.
    // Surahs: Nas (114, 6 verses), Falaq (113, 5 verses), Ikhlas (112, 4 verses), Masad (111, 5 verses), Kafirun (109, 6 verses), Quraysh (106, 4 verses)
    $surahDetails = [
        114 => ['name' => 'الناس', 'verses' => 6],
        113 => ['name' => 'الفلق', 'verses' => 5],
        112 => ['name' => 'الإخلاص', 'verses' => 4],
        111 => ['name' => 'المسد', 'verses' => 5],
        110 => ['name' => 'النصر', 'verses' => 3],
        109 => ['name' => 'الكافرون', 'verses' => 6],
        108 => ['name' => 'الكوثر', 'verses' => 3],
        107 => ['name' => 'الماعون', 'verses' => 7],
        106 => ['name' => 'قريش', 'verses' => 6],
        105 => ['name' => 'الفيل', 'verses' => 5],
        104 => ['name' => 'الهمزة', 'verses' => 9],
        103 => ['name' => 'العصر', 'verses' => 3],
        102 => ['name' => 'التكاثر', 'verses' => 8],
    ];

    foreach ($surahDetails as $id => $data) {
        Surah::create([
            'id' => $id,
            'number' => $id,
            'name_arabic' => $data['name'],
            'name_simple' => 'Surah '.$id,
            'revelation_place' => 'makkah',
            'revelation_order' => $id,
            'verses_count' => $data['verses'],
            'start_page' => 600 - $id,
            'end_page' => 600 - $id,
        ]);

        for ($i = 1; $i <= $data['verses']; $i++) {
            Ayah::create([
                'id' => $id * 1000 + $i,
                'surah_id' => $id,
                'verse_number' => $i,
                'page_number' => 600 - $id,
                'line_number_start' => $i,
                'line_number_end' => $i,
                'verse_key' => "$id:$i",
                'juz_number' => 30,
                'hizb_number' => 60,
                'rub_number' => 1,
                'ruku_number' => 1,
                'manzil_number' => 7,
                'text_uthmani' => "Surah $id Ayah $i",
            ]);
        }
    }

    $component = Livewire::test('shared.⚡plan-creator')
        ->set('studentId', $this->students->first()->id)
        ->set('planType', 'hifz_review')
        ->set('fillDirection', 'reverse')
        ->set('reviewDirection', 'reverse')
        ->set('startDate', '2026-06-03') // Wednesday
        ->set('daysCount', 8)
        ->set('activeDays', ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'])
        ->call('generateDays');

    // Index 0: Wednesday (selected)
    // Index 1-3: Thursday, Friday, Saturday (unselected/empty)
    // Index 4: Sunday (selected)
    // Index 5-6: Monday, Tuesday (unselected/empty)
    // Index 7: Wednesday (selected)

    // Wednesday (Index 0): Hifz starts at Al-Fil 1 (Surah 105), ends at Al-Asr 3
    $component->set('planDays.0.from_surah_id', 105)
        ->set('planDays.0.from_verse', 1)
        ->set('planDays.0.to_surah_id', 103)
        ->set('planDays.0.to_verse', 3);

    // Sunday (Index 4): Hifz starts at Al-Takathur 1 (Surah 102), ends at Al-Takathur 8
    $component->set('planDays.4.from_surah_id', 102)
        ->set('planDays.4.from_verse', 1)
        ->set('planDays.4.to_surah_id', 102)
        ->set('planDays.4.to_verse', 8);

    // Wednesday (Index 7): Hifz starts at Al-Humazah 9 (Surah 104), ends at Al-Humazah 1
    $component->set('planDays.7.from_surah_id', 104)
        ->set('planDays.7.from_verse', 9)
        ->set('planDays.7.to_surah_id', 104)
        ->set('planDays.7.to_verse', 1);

    // First day review start is set to Nas 1 (Surah 114)
    $component->set('planDays.0.review_from_surah_id', 114)
        ->set('planDays.0.review_from_verse', 1);

    // Call fillSelected with custom pages (say, 3 pages = 45 lines) for review
    // Selected indices: Wednesday (0), Sunday (4), Wednesday (7)
    $component->call('fillSelected', 'custom_pages_3', 'review', [0, 4, 7]);

    $planDays = $component->get('planDays');

    // Index 0 (Wednesday) review:
    // Hifz start is Al-Fil 1. So ceiling is Quraysh 6.
    // Starting from Nas 1, we try to read 45 lines in reverse.
    // Total lines from Nas 1 to Quraysh 6 is 45 lines exactly.
    // We hit the ceiling. Capped to Quraysh 6.
    // Backwards calculation from Quraysh 6 starts at Nas 1.
    // So Index 0 review should be Nas 1 to Quraysh 6.
    expect($planDays[0]['review_from_surah_id'])->toBe(114);
    expect($planDays[0]['review_from_verse'])->toBe(1);
    expect($planDays[0]['review_to_surah_id'])->toBe(106);
    expect($planDays[0]['review_to_verse'])->toBe(6);

    // Index 4 (Sunday) review:
    // Thursday, Friday, Saturday (unselected/empty) did not reset progression.
    // Sunday's normal range would be Nas 1 to Quraysh 6 (duplicate of Wednesday).
    // Duplicate range check triggers, calculating backwards from Al-Asr 3.
    // Backwards calculation from Al-Asr 3 starts at Al-Masad 1.
    // So Index 4 review should be Al-Masad 1 to Al-Asr 3.
    expect($planDays[4]['review_from_surah_id'])->toBe(111);
    expect($planDays[4]['review_from_verse'])->toBe(1);
    expect($planDays[4]['review_to_surah_id'])->toBe(103);
    expect($planDays[4]['review_to_verse'])->toBe(3);

    // Index 7 (Wednesday) review:
    // Monday, Tuesday (unselected/empty) did not reset progression.
    // Sunday review hit the ceiling and set resetNextReview = true.
    // So Wednesday review resets to the beginning of the review cycle: Nas 1.
    // Wednesday's ceiling is Al-Humazah 8.
    // Nas 1 to Al-Humazah 8 is 59 lines. We want 45 lines.
    // Nas 1 + 45 lines = Quraysh 6.
    // So Index 7 review should be Nas 1 to Quraysh 6.
    expect($planDays[7]['review_from_surah_id'])->toBe(114);
    expect($planDays[7]['review_from_verse'])->toBe(1);
    expect($planDays[7]['review_to_surah_id'])->toBe(106);
    expect($planDays[7]['review_to_verse'])->toBe(6);
});
