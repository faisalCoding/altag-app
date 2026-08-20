<?php

use App\Models\Circle;
use App\Models\Stage;
use App\Models\Student;
use App\Models\StudentPlan;
use App\Models\StudentPlanDay;
use App\Models\Teacher;
use App\Services\CircleReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Memorisation and revision are read by different measures.
 *
 * Hifz answers "how much of the mushaf does the student hold", so a page
 * re-recited is not a page gained and the count is distinct. Review answers
 * "how much revision was done", where returning to the same pages is the
 * substance of the work — counting it once discarded most of the effort, which
 * is what made a student's real 264 pages read as 95.
 */
beforeEach(function () {
    Carbon\Carbon::setTestNow('2026-07-20 10:00:00');

    $this->stage = Stage::factory()->create();
    $this->circle = Circle::factory()->create(['stage_id' => $this->stage->id]);
    $this->teacher = Teacher::factory()->create();
    $this->teacher->circles()->attach($this->circle->id);
    $this->student = Student::factory()->create(['circle_id' => $this->circle->id]);

    $this->plan = StudentPlan::create([
        'student_id' => $this->student->id,
        'teacher_id' => $this->teacher->id,
        'start_date' => '2026-07-01',
        'days_count' => 30,
        'active_days' => [0, 1, 2, 3, 4, 5, 6],
        'status' => 'active',
        'is_approved' => true,
        'created_by_role' => 'teacher',
    ]);

    // Ten pages of five ayahs each, so a range's page count is easy to reason about.
    $surahId = DB::table('surahs')->insertGetId([
        'number' => 1,
        'name_arabic' => 'الاختبار',
        'name_simple' => 'test',
        'revelation_place' => 'makkah',
        'revelation_order' => 1,
        'verses_count' => 50,
        'start_page' => 1,
        'end_page' => 10,
    ]);
    $rows = [];
    for ($i = 1; $i <= 50; $i++) {
        $rows[] = [
            'id' => $i,
            'surah_id' => $surahId,
            'verse_number' => $i,
            'verse_key' => "1:{$i}",
            'juz_number' => 1,
            'hizb_number' => 1,
            'rub_number' => 1,
            'page_number' => (int) ceil($i / 5),
            'ruku_number' => 1,
            'manzil_number' => 1,
            'text_uthmani' => 'نص',
        ];
    }
    DB::table('ayahs')->insert($rows);
});

/** @param array{0:int,1:int}|null $hifz @param array{0:int,1:int}|null $review */
function planDay(int $planId, string $date, ?array $hifz = null, ?array $review = null): StudentPlanDay
{
    return StudentPlanDay::create([
        'student_plan_id' => $planId,
        'date' => $date,
        'day_name' => 'اختبار',
        'from_ayah_id' => $hifz[0] ?? null,
        'to_ayah_id' => $hifz[1] ?? null,
        'hifz_achievement' => $hifz ? 3 : null,
        'review_from_ayah_id' => $review[0] ?? null,
        'review_to_ayah_id' => $review[1] ?? null,
        'review_achievement' => $review ? 3 : null,
    ]);
}

function reportFor(Circle $circle): array
{
    return CircleReportService::build(
        CircleReportService::studentsForCircle($circle),
        Carbon\Carbon::parse('2026-07-01'),
        Carbon\Carbon::parse('2026-07-20'),
    );
}

it('counts the same review range again each day it is revised', function () {
    // Pages 1–2 (ayahs 1–10), revised on three separate days.
    planDay($this->plan->id, '2026-07-05', null, [1, 10]);
    planDay($this->plan->id, '2026-07-06', null, [1, 10]);
    planDay($this->plan->id, '2026-07-07', null, [1, 10]);

    $row = reportFor($this->circle)['perStudent'][0];

    expect($row['review_pages'])->toBe(6);           // the work: 2 pages × 3 days
    expect($row['review_pages_distinct'])->toBe(2);  // the extent: 2 pages of mushaf
    expect($row['review_days'])->toBe(3);
});

it('keeps hifz pages distinct, so re-reciting is not re-memorising', function () {
    // The same growing portion recited across three days, as a plan builds up.
    planDay($this->plan->id, '2026-07-05', [1, 5]);
    planDay($this->plan->id, '2026-07-06', [1, 10]);
    planDay($this->plan->id, '2026-07-07', [1, 15]);

    $row = reportFor($this->circle)['perStudent'][0];

    expect($row['hifz_pages'])->toBe(3); // pages 1–3 reached, not 6
});

it('adds up review across different portions without inflating them', function () {
    planDay($this->plan->id, '2026-07-05', null, [1, 10]);   // pages 1–2
    planDay($this->plan->id, '2026-07-06', null, [11, 20]);  // pages 3–4

    $row = reportFor($this->circle)['perStudent'][0];

    expect($row['review_pages'])->toBe(4);
    expect($row['review_pages_distinct'])->toBe(4); // nothing repeated, so they agree
});

it('counts a page once when a single day covers it twice over', function () {
    // One day's range sitting inside one page: it is one page of work, not two.
    planDay($this->plan->id, '2026-07-05', null, [1, 4]);

    $row = reportFor($this->circle)['perStudent'][0];

    expect($row['review_pages'])->toBe(1);
    expect($row['review_pages_distinct'])->toBe(1);
});

it('carries both review figures into the totals', function () {
    planDay($this->plan->id, '2026-07-05', null, [1, 10]);
    planDay($this->plan->id, '2026-07-06', null, [1, 10]);

    $totals = reportFor($this->circle)['totals'];

    expect($totals['review']['pages'])->toBe(4);
    expect($totals['review']['pages_distinct'])->toBe(2);
});

it('sums the running total across students rather than merging their pages', function () {
    $second = Student::factory()->create(['circle_id' => $this->circle->id]);
    $secondPlan = StudentPlan::create([
        'student_id' => $second->id,
        'teacher_id' => $this->teacher->id,
        'start_date' => '2026-07-01',
        'days_count' => 30,
        'active_days' => [0, 1, 2, 3, 4, 5, 6],
        'status' => 'active',
        'is_approved' => true,
        'created_by_role' => 'teacher',
    ]);

    // Both revise the very same two pages: four pages of work between them.
    planDay($this->plan->id, '2026-07-05', null, [1, 10]);
    planDay($secondPlan->id, '2026-07-05', null, [1, 10]);

    $totals = reportFor($this->circle)['totals'];

    expect($totals['review']['pages'])->toBe(4);
    expect($totals['review']['pages_distinct'])->toBe(4); // distinct is per student, then summed
});

it('shows both review figures on the report table', function () {
    planDay($this->plan->id, '2026-07-05', null, [1, 10]);
    planDay($this->plan->id, '2026-07-06', null, [1, 10]);

    $html = Blade::render(
        '<x-reports.circle-summary :report="$report" :show-circle-column="false" />',
        ['report' => reportFor($this->circle)],
    );

    // The running total leads, with the distinct figure kept beside it.
    expect($html)->toContain('صفحات المراجعة');
    expect($html)->toContain('المميزة');
    expect($html)->toContain('>4<');   // the work
    expect($html)->toContain('(2)');   // the extent
});
