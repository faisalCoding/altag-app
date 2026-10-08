<?php

use App\Models\Challenge;
use App\Models\ChallengeItem;
use App\Models\Circle;
use App\Models\Stage;
use App\Models\Student;
use App\Models\StudentPlan;
use App\Models\StudentPlanDay;
use App\Models\Teacher;
use App\Services\CircleReportService;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * «لم يسمع» is stored as 0: a grade (the teacher handled the day) but not a
 * recitation. Every reader must keep it apart from 1..3 and from null.
 */
beforeEach(function () {
    Carbon\Carbon::setTestNow('2026-07-20 10:00:00');

    $this->stage = Stage::factory()->create();
    $this->circle = Circle::factory()->create(['stage_id' => $this->stage->id]);
    $this->teacher = Teacher::factory()->create();
    $this->teacher->circles()->attach($this->circle->id);
    $this->student = Student::factory()->create(['circle_id' => $this->circle->id, 'is_approved' => true]);

    $this->plan = StudentPlan::create([
        'student_id' => $this->student->id,
        'teacher_id' => $this->teacher->id,
        'plan_type' => 'hifz_review',
        'start_date' => '2026-07-01',
        'days_count' => 4,
        'active_days' => [0, 1, 2, 3, 4, 5, 6],
        'status' => 'active',
        'is_approved' => true,
        'created_by_role' => 'teacher',
    ]);

    // Two pages of five ayahs each.
    $surahId = DB::table('surahs')->insertGetId([
        'number' => 1, 'name_arabic' => 'الاختبار', 'name_simple' => 'test',
        'revelation_place' => 'makkah', 'revelation_order' => 1, 'verses_count' => 10,
        'start_page' => 1, 'end_page' => 2,
    ]);
    foreach (range(1, 10) as $id) {
        DB::table('ayahs')->insert([
            'id' => $id, 'surah_id' => $surahId, 'verse_number' => $id, 'verse_key' => "1:{$id}",
            'juz_number' => 1, 'hizb_number' => 1, 'rub_number' => 1, 'page_number' => (int) ceil($id / 5),
            'ruku_number' => 1, 'manzil_number' => 1, 'text_uthmani' => 'نص',
        ]);
    }
});

/** @param array<string, mixed> $attributes */
function notHeardDay(StudentPlan $plan, string $date, array $attributes = []): StudentPlanDay
{
    return StudentPlanDay::create(array_merge([
        'student_plan_id' => $plan->id,
        'date' => $date,
        'day_name' => 'اختبار',
    ], $attributes));
}

it('leaves a «لم يسمع» day out of the plan completion', function () {
    notHeardDay($this->plan, '2026-07-05', ['hifz_achievement' => 3]);
    notHeardDay($this->plan, '2026-07-06', ['hifz_achievement' => 0]);
    notHeardDay($this->plan, '2026-07-07', ['review_achievement' => 0]);
    notHeardDay($this->plan, '2026-07-08');

    // Only the first day was recited: 1 of 4.
    expect($this->plan->completionPercentage())->toBe(25.0);
});

it('reports «لم يسمع» in a bucket of its own in the achievement distribution', function () {
    notHeardDay($this->plan, '2026-07-05', ['hifz_achievement' => 3, 'review_achievement' => 0]);
    notHeardDay($this->plan, '2026-07-06', ['hifz_achievement' => 0]);
    notHeardDay($this->plan, '2026-07-07', ['hifz_achievement' => 1, 'review_achievement' => 2]);
    notHeardDay($this->plan, '2026-07-08');

    expect($this->plan->achievementDistribution())->toBe([
        'excellent' => 1,
        'good' => 1,
        'weak' => 1,
        'not_heard' => 2,
    ]);
});

it('does not count a «لم يسمع» day in the circle report', function () {
    notHeardDay($this->plan, '2026-07-05', [
        'from_ayah_id' => 1, 'to_ayah_id' => 5, 'hifz_achievement' => 3, 'hifz_graded_at' => '2026-07-05 09:00:00',
    ]);
    notHeardDay($this->plan, '2026-07-06', [
        'from_ayah_id' => 6, 'to_ayah_id' => 10, 'hifz_achievement' => 0, 'hifz_graded_at' => '2026-07-06 09:00:00',
        'review_from_ayah_id' => 1, 'review_to_ayah_id' => 5, 'review_achievement' => 0, 'review_graded_at' => '2026-07-06 09:05:00',
    ]);

    $report = CircleReportService::build(
        CircleReportService::studentsForCircle($this->circle),
        Carbon\Carbon::parse('2026-07-01'),
        Carbon\Carbon::parse('2026-07-20'),
    );
    $row = $report['perStudent'][0];

    expect($row['hifz_days'])->toBe(1)
        ->and($row['hifz_score_sum'])->toBe(3)
        ->and($row['hifz_pages'])->toBe(1)
        ->and($row['review_days'])->toBe(0)
        ->and($row['review_pages'])->toBe(0)
        ->and($report['totals']['hifz']['average'])->toBe(3.0)
        ->and($report['totals']['review']['average'])->toBeNull();
});

it('counts only recited days toward a recitation-days challenge', function () {
    $dayIds = collect([3, 2, 0, null])
        ->map(fn (?int $grade, int $index) => notHeardDay($this->plan, '2026-07-0'.($index + 5), ['hifz_achievement' => $grade])->id)
        ->all();

    $item = fn (array $metadata) => (new ChallengeItem([
        'type' => 'recitation_days',
        'target_value' => 10,
        'metadata' => array_merge(['day_ids' => $dayIds], $metadata),
    ]))->setRelation('challenge', new Challenge(['student_id' => $this->student->id, 'start_date' => '2026-07-01']));

    expect($item([])->calculateProgress())->toBe(2)
        ->and($item(['quality_required' => true, 'quality_req' => 'excellent'])->calculateProgress())->toBe(1)
        ->and($item(['quality_required' => true, 'quality_req' => 'good_or_better'])->calculateProgress())->toBe(2);
});

it('shows the «لم يسمع» bucket on the student plans page', function () {
    notHeardDay($this->plan, '2026-07-05', ['hifz_achievement' => 3]);
    notHeardDay($this->plan, '2026-07-06', ['hifz_achievement' => 0]);

    $this->actingAs($this->student, 'student')
        ->get(route('student.plan'))
        ->assertSuccessful()
        ->assertSee('لم يسمع');
});

it('lists a «لم يسمع» grading in the recitation log with the range actually recited', function () {
    notHeardDay($this->plan, '2026-07-05', [
        'from_ayah_id' => 1, 'to_ayah_id' => 10, 'hifz_achievement' => 0, 'hifz_graded_at' => '2026-07-05 09:00:00',
    ]);
    notHeardDay($this->plan, '2026-07-06', [
        'from_ayah_id' => 1, 'to_ayah_id' => 10, 'hifz_achievement' => 3, 'hifz_graded_at' => '2026-07-06 09:00:00',
        'hifz_recited_from_ayah_id' => 1, 'hifz_recited_to_ayah_id' => 3,
    ]);

    $this->actingAs($this->teacher, 'teacher');

    Livewire::test('teacher.student-recitation-log', ['studentId' => $this->student->id])
        ->assertViewHas('stats', fn ($stats) => $stats['distribution'][0] === 1 && $stats['distribution'][3] === 1)
        ->assertViewHas('days', function ($days) {
            $ranges = $days->flatMap(fn ($day) => $day['entries'])->pluck('range', 'achievement');

            // The scheduled wird when nothing else was recorded; the recited range when it was.
            return $ranges[0] === 'الاختبار' && $ranges[3] === 'الاختبار 1-3';
        })
        ->assertSee('لم يُسمع')
        ->call('toggleQuality', 0)
        ->assertViewHas('days', fn ($days) => $days->flatMap(fn ($day) => $day['entries'])->pluck('achievement')->all() === [0]);
});
