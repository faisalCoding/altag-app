<?php

use App\Models\Attendance;
use App\Models\Circle;
use App\Models\Guardian;
use App\Models\Stage;
use App\Models\Student;
use App\Models\StudentPlan;
use App\Models\StudentPlanDay;
use App\Models\Surah;
use App\Models\Teacher;
use App\Services\MemorizationJourneyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon\Carbon::setTestNow('2026-06-10 10:00:00');

    $this->stage = Stage::factory()->create();
    $this->circle = Circle::factory()->create(['stage_id' => $this->stage->id]);
    $this->teacher = Teacher::factory()->create();
    $this->guardian = Guardian::factory()->create(['is_approved' => true]);
    $this->child = Student::factory()->create([
        'name' => 'الابن الأول',
        'guardian_id' => $this->guardian->id,
        'circle_id' => $this->circle->id,
    ]);
});

function seedJuzAyahs(): void
{
    $surah = Surah::create([
        'number' => 1,
        'name_arabic' => 'سورة',
        'name_simple' => 'Surah',
        'revelation_place' => 'makkah',
        'revelation_order' => 1,
        'verses_count' => 6,
        'start_page' => 1,
        'end_page' => 1,
    ]);

    // Ayah ids 1-3 in juz 1, ids 4-6 in juz 2.
    foreach (range(1, 6) as $id) {
        DB::table('ayahs')->insert([
            'id' => $id,
            'surah_id' => $surah->id,
            'verse_number' => $id,
            'verse_key' => "1:{$id}",
            'juz_number' => $id <= 3 ? 1 : 2,
            'hizb_number' => 1,
            'rub_number' => 1,
            'page_number' => 1,
            'ruku_number' => 1,
            'manzil_number' => 1,
            'text_uthmani' => 'نص',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

it('returns 30 juz all marked none when the student has no memorization', function () {
    $map = MemorizationJourneyService::juzMap($this->child);

    expect($map)->toHaveCount(30);
    expect(collect($map)->pluck('status')->unique()->all())->toBe(['none']);
});

it('marks a juz full or partial based on the memorized range', function () {
    seedJuzAyahs();

    $plan = StudentPlan::create([
        'student_id' => $this->child->id,
        'plan_type' => 'hifz',
        'direction' => 'forward',
        'start_date' => now()->subDays(5),
        'is_approved' => true,
        'days_count' => 30,
        'active_days' => [0, 1, 2, 3, 4, 5, 6],
    ]);
    StudentPlanDay::create([
        'student_plan_id' => $plan->id,
        'date' => today(),
        'day_name' => 'الأربعاء',
        'hifz_achievement' => 3,
        'from_ayah_id' => 1,
        'to_ayah_id' => 3,
    ]);

    $map = collect(MemorizationJourneyService::juzMap($this->child->fresh()))->keyBy('juz');

    expect($map[1]['status'])->toBe('full');   // juz 1 (ayahs 1-3) fully within [1,3]
    expect($map[2]['status'])->toBe('none');   // juz 2 (ayahs 4-6) untouched
});

it('marks a surah full or partial based on the memorized range', function () {
    // Surah 1 covers ayahs 1-3, surah 2 covers ayahs 4-6.
    foreach ([1, 2] as $number) {
        Surah::create([
            'number' => $number,
            'name_arabic' => "سورة {$number}",
            'name_simple' => "Surah {$number}",
            'revelation_place' => 'makkah',
            'revelation_order' => $number,
            'verses_count' => 3,
            'start_page' => 1,
            'end_page' => 1,
        ]);
    }
    foreach (range(1, 6) as $id) {
        DB::table('ayahs')->insert([
            'id' => $id,
            'surah_id' => $id <= 3 ? 1 : 2,
            'verse_number' => $id <= 3 ? $id : $id - 3,
            'verse_key' => ($id <= 3 ? '1:'.$id : '2:'.($id - 3)),
            'juz_number' => 1,
            'hizb_number' => 1,
            'rub_number' => 1,
            'page_number' => 1,
            'ruku_number' => 1,
            'manzil_number' => 1,
            'text_uthmani' => 'نص',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    $plan = StudentPlan::create([
        'student_id' => $this->child->id,
        'plan_type' => 'hifz',
        'direction' => 'forward',
        'start_date' => now()->subDays(5),
        'is_approved' => true,
        'days_count' => 30,
        'active_days' => [0, 1, 2, 3, 4, 5, 6],
    ]);
    StudentPlanDay::create([
        'student_plan_id' => $plan->id,
        'date' => today(),
        'day_name' => 'الأربعاء',
        'hifz_achievement' => 3,
        'from_ayah_id' => 1,
        'to_ayah_id' => 4,
    ]);

    $map = collect(MemorizationJourneyService::surahMap($this->child->fresh()))->keyBy('number');

    expect($map[1]['status'])->toBe('full');    // ayahs 1-3 fully within [1,4]
    expect($map[2]['status'])->toBe('partial'); // only ayah 4 of 4-6 memorized
});

it('returns the score trend oldest-first', function () {
    $plan = StudentPlan::create([
        'student_id' => $this->child->id,
        'plan_type' => 'hifz',
        'start_date' => now()->subDays(5),
        'is_approved' => true,
        'days_count' => 30,
        'active_days' => [0, 1, 2, 3, 4, 5, 6],
    ]);
    foreach ([['2026-06-08', 2], ['2026-06-09', 3], ['2026-06-10', 1]] as [$date, $score]) {
        StudentPlanDay::create([
            'student_plan_id' => $plan->id,
            'date' => $date,
            'day_name' => 'يوم',
            'hifz_achievement' => $score,
        ]);
    }

    $trend = MemorizationJourneyService::scoreTrend($this->child);

    expect($trend)->toHaveCount(3);
    expect(array_column($trend, 'achievement'))->toBe([2, 3, 1]);
});

it('buckets attendance into the last 8 weeks', function () {
    foreach ([['2026-06-10', 'present'], ['2026-06-09', 'absent'], ['2026-06-02', 'present']] as [$date, $status]) {
        Attendance::create([
            'student_id' => $this->child->id,
            'circle_id' => $this->circle->id,
            'teacher_id' => $this->teacher->id,
            'date' => $date,
            'status' => $status,
        ]);
    }

    $trend = MemorizationJourneyService::attendanceTrend($this->child);

    expect($trend)->toHaveCount(8);
    expect($trend[7])->toMatchArray(['present' => 1, 'total' => 2]); // current week
    expect($trend[6])->toMatchArray(['present' => 1, 'total' => 1]); // previous week
});

it('returns all surahs as none with 0 percentage when the student has no memorization', function () {
    seedJuzAyahs();

    $map = MemorizationJourneyService::surahMap($this->child);

    expect($map)->toHaveCount(1);
    expect($map[0]['status'])->toBe('none');
    expect($map[0]['percentage'])->toBe(0.0);
});

it('computes surah percentage and full status from the memorized range', function () {
    seedJuzAyahs();
    $surah = Surah::where('number', 1)->first();

    $plan = StudentPlan::create([
        'student_id' => $this->child->id,
        'plan_type' => 'hifz',
        'direction' => 'forward',
        'start_date' => now()->subDays(5),
        'is_approved' => true,
        'days_count' => 30,
        'active_days' => [0, 1, 2, 3, 4, 5, 6],
    ]);
    StudentPlanDay::create([
        'student_plan_id' => $plan->id,
        'date' => today(),
        'day_name' => 'الأربعاء',
        'hifz_achievement' => 3,
        'from_ayah_id' => 1,
        'to_ayah_id' => 3,
    ]);

    $map = collect(MemorizationJourneyService::surahMap($this->child->fresh()))->keyBy('surah_id');

    expect($map[$surah->id]['status'])->toBe('partial');
    expect($map[$surah->id]['percentage'])->toBe(50.0); // 3 of 6 verses
});

it('sums ayahs memorized per day within the current month', function () {
    seedJuzAyahs();

    $plan = StudentPlan::create([
        'student_id' => $this->child->id,
        'plan_type' => 'hifz',
        'start_date' => now()->subDays(5),
        'is_approved' => true,
        'days_count' => 30,
        'active_days' => [0, 1, 2, 3, 4, 5, 6],
    ]);

    StudentPlanDay::create([
        'student_plan_id' => $plan->id,
        'date' => '2026-06-08',
        'day_name' => 'يوم',
        'hifz_achievement' => 3,
        'from_ayah_id' => 1,
        'to_ayah_id' => 3,
    ]);
    // Outside the current month — must be excluded.
    StudentPlanDay::create([
        'student_plan_id' => $plan->id,
        'date' => '2026-05-01',
        'day_name' => 'يوم',
        'hifz_achievement' => 3,
        'from_ayah_id' => 4,
        'to_ayah_id' => 6,
    ]);

    $stats = MemorizationJourneyService::monthlyAyahsMemorized($this->child);

    expect($stats)->toBe([['date' => '2026-06-08', 'count' => 3]]);
});

it('counts each ayah on the first day it was memorized, a surah recited from its start each day included', function () {
    seedJuzAyahs();

    $plan = StudentPlan::create([
        'student_id' => $this->child->id, 'plan_type' => 'hifz', 'start_date' => now()->subDays(5),
        'is_approved' => true, 'days_count' => 30, 'active_days' => [0, 1, 2, 3, 4, 5, 6],
    ]);

    // Before the month: 1-2. Then the surah from its start each day: 1-4, 1-6.
    foreach ([['2026-05-31', 1, 2], ['2026-06-07', 1, 4], ['2026-06-08', 1, 6]] as [$date, $from, $to]) {
        StudentPlanDay::create([
            'student_plan_id' => $plan->id, 'date' => $date, 'day_name' => 'يوم',
            'hifz_achievement' => 3, 'from_ayah_id' => $from, 'to_ayah_id' => $to,
        ]);
    }

    expect(MemorizationJourneyService::monthlyAyahsMemorized($this->child))->toBe([
        ['date' => '2026-06-07', 'count' => 2],
        ['date' => '2026-06-08', 'count' => 2],
    ]);
});

it('computes a fractional memorized juz count from the memorized page count', function () {
    expect(MemorizationJourneyService::memorizedJuzCount($this->child))->toBe(0.0);

    $surah = Surah::create([
        'number' => 1, 'name_arabic' => 'سورة', 'name_simple' => 'Surah', 'revelation_place' => 'makkah',
        'revelation_order' => 1, 'verses_count' => 1, 'start_page' => 1, 'end_page' => 302,
    ]);
    DB::table('ayahs')->insert([
        'id' => 1, 'surah_id' => $surah->id, 'verse_number' => 1, 'verse_key' => '1:1',
        'juz_number' => 15, 'hizb_number' => 1, 'rub_number' => 1, 'page_number' => 302,
        'ruku_number' => 1, 'manzil_number' => 1, 'text_uthmani' => 'نص',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $plan = StudentPlan::create([
        'student_id' => $this->child->id,
        'plan_type' => 'hifz',
        'direction' => 'forward',
        'start_date' => now()->subDays(5),
        'is_approved' => true,
        'days_count' => 30,
        'active_days' => [0, 1, 2, 3, 4, 5, 6],
    ]);
    StudentPlanDay::create([
        'student_plan_id' => $plan->id,
        'date' => today(),
        'day_name' => 'الأربعاء',
        'hifz_achievement' => 3,
        'from_ayah_id' => 1,
        'to_ayah_id' => 1,
    ]);

    // memorizedPagesCount() resolves to page 302 (half of 604), so ~15 juz.
    expect(MemorizationJourneyService::memorizedJuzCount($this->child->fresh()))->toBe(15.0);
});

it('returns null current-surah progress for a student with no memorization', function () {
    expect(MemorizationJourneyService::currentSurahProgress($this->child))->toBeNull();
});

it('computes the current in-progress surah and its within-surah percentage', function () {
    seedJuzAyahs();
    $surah = Surah::where('number', 1)->first();

    $plan = StudentPlan::create([
        'student_id' => $this->child->id,
        'plan_type' => 'hifz',
        'direction' => 'forward',
        'start_date' => now()->subDays(5),
        'is_approved' => true,
        'days_count' => 30,
        'active_days' => [0, 1, 2, 3, 4, 5, 6],
    ]);
    StudentPlanDay::create([
        'student_plan_id' => $plan->id,
        'date' => today(),
        'day_name' => 'الأربعاء',
        'hifz_achievement' => 3,
        'from_ayah_id' => 1,
        'to_ayah_id' => 3,
    ]);

    $progress = MemorizationJourneyService::currentSurahProgress($this->child->fresh());

    expect($progress['surah_name'])->toBe($surah->name_arabic);
    expect($progress['memorized_in_surah'])->toBe(3);
    expect($progress['total_in_surah'])->toBe(6);
    expect($progress['percentage'])->toBe(50.0);
});

it('returns zero today mission progress when nothing is scheduled today', function () {
    $progress = MemorizationJourneyService::todayMissionProgress($this->child);

    expect($progress)->toBe(['completed' => 0, 'total' => 0, 'percentage' => 0]);
});

it('computes today mission progress from graded and ungraded plan day components', function () {
    seedJuzAyahs();

    $plan = StudentPlan::create([
        'student_id' => $this->child->id,
        'plan_type' => 'hifz',
        'start_date' => now()->subDays(5),
        'is_approved' => true,
        'days_count' => 30,
        'active_days' => [0, 1, 2, 3, 4, 5, 6],
    ]);
    StudentPlanDay::create([
        'student_plan_id' => $plan->id,
        'date' => today(),
        'day_name' => 'الأربعاء',
        'hifz_achievement' => 3, // graded
        'from_ayah_id' => 1,
        'to_ayah_id' => 3,
        'review_achievement' => null, // not yet graded
        'review_from_ayah_id' => 4,
        'review_to_ayah_id' => 6,
    ]);

    $progress = MemorizationJourneyService::todayMissionProgress($this->child);

    expect($progress)->toBe(['completed' => 1, 'total' => 2, 'percentage' => 50]);
});

it('lists distinct activity dates for a month from attendance and graded plan days', function () {
    Attendance::create([
        'student_id' => $this->child->id,
        'circle_id' => $this->circle->id,
        'teacher_id' => $this->teacher->id,
        'date' => '2026-06-05',
        'status' => 'present',
    ]);

    $plan = StudentPlan::create([
        'student_id' => $this->child->id,
        'plan_type' => 'hifz',
        'start_date' => now()->subDays(5),
        'is_approved' => true,
        'days_count' => 30,
        'active_days' => [0, 1, 2, 3, 4, 5, 6],
    ]);
    StudentPlanDay::create([
        'student_plan_id' => $plan->id,
        'date' => '2026-06-08',
        'day_name' => 'يوم',
        'hifz_achievement' => 3,
    ]);

    $dates = MemorizationJourneyService::activityDatesForMonth($this->child, 2026, 6);

    expect($dates)->toContain('2026-06-05', '2026-06-08');
    expect($dates)->toHaveCount(2);
});

it('renders the memorization journey on the guardian student detail page', function () {
    $this->actingAs($this->guardian, 'guardian');

    $this->get(route('guardian.student', $this->child->id))
        ->assertSuccessful()
        ->assertSee('رحلة الحفظ')
        ->assertSee('خريطة السور')
        ->assertSee('تطوّر التقييم')
        ->assertSee('حضور آخر 8 أسابيع');
});
