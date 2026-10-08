<?php

use App\Models\Ayah;
use App\Models\Circle;
use App\Models\Student;
use App\Models\StudentPlan;
use App\Models\StudentPlanDay;
use App\Models\Surah;
use Livewire\Livewire;

beforeEach(function () {
    Surah::create([
        'id' => 1, 'number' => 1, 'name_arabic' => 'الفاتحة', 'name_simple' => 'Al-Fatihah',
        'revelation_place' => 'makkah', 'revelation_order' => 1, 'verses_count' => 7,
        'start_page' => 1, 'end_page' => 1,
    ]);

    foreach (range(1, 6) as $n) {
        Ayah::create([
            'id' => $n, 'surah_id' => 1, 'verse_number' => $n, 'page_number' => 1,
            'line_number_start' => 1, 'line_number_end' => 1, 'verse_key' => '1:'.$n,
            'juz_number' => 1, 'hizb_number' => 1, 'rub_number' => 1, 'ruku_number' => 1,
            'manzil_number' => 1, 'text_uthmani' => 'آية '.$n,
        ]);
    }

    $this->student = Student::factory()->create(['circle_id' => Circle::factory()->create()->id]);

    $this->plan = StudentPlan::create([
        'student_id' => $this->student->id,
        'start_date' => '2026-07-01',
        'days_count' => 3,
        'active_days' => [0, 1, 2, 3, 4, 5, 6],
        'status' => 'active',
        'plan_type' => 'hifz_review',
        'is_approved' => 1,
        'created_by_role' => 'teacher',
    ]);

    // Hifz is graded through day 2, review only through day 1 — the everyday
    // case where a teacher is further along on one than on the other.
    collect([
        ['date' => '2026-07-01', 'hifz' => 3, 'review' => 3, 'from' => 1, 'to' => 2],
        ['date' => '2026-07-02', 'hifz' => 3, 'review' => null, 'from' => 3, 'to' => 4],
        ['date' => '2026-07-03', 'hifz' => null, 'review' => null, 'from' => 5, 'to' => 6],
    ])->each(fn ($row) => StudentPlanDay::create([
        'student_plan_id' => $this->plan->id,
        'date' => $row['date'],
        'day_name' => 'الأحد',
        'from_ayah_id' => $row['from'],
        'to_ayah_id' => $row['to'],
        'review_from_ayah_id' => $row['from'],
        'review_to_ayah_id' => $row['to'],
        'hifz_achievement' => $row['hifz'],
        'review_achievement' => $row['review'],
    ]));

    $this->actingAs($this->student, 'student');
});

/**
 * The dashboard used to take the single earliest day with either part
 * ungraded, so a student whose hifz ran ahead of their review saw only the
 * review and was never shown the hifz they owed — or the reverse.
 */
it('shows the next hifz and the next review independently', function () {
    $missions = Livewire::test('student.⚡dashboard')->viewData('pendingMissions');

    expect($missions)->toHaveCount(2);

    $byPart = collect($missions)->keyBy('pendingPart');

    // Review is still behind on day 2 while hifz has moved on to day 3.
    expect($byPart['review']->date->format('Y-m-d'))->toBe('2026-07-02')
        ->and($byPart['hifz']->date->format('Y-m-d'))->toBe('2026-07-03');
});

it('drops a part once every one of its days is graded', function () {
    StudentPlanDay::where('student_plan_id', $this->plan->id)->update(['review_achievement' => 3]);

    $missions = Livewire::test('student.⚡dashboard')->viewData('pendingMissions');

    expect($missions)->toHaveCount(1)
        ->and($missions[0]->pendingPart)->toBe('hifz');
});

it('follows the last portion recited, as the teacher app does, past a day left ungraded', function () {
    // The teacher graded day 3's review, the last, and left day 2: the plan
    // follows what was recited last, so its review is done — as the app shows
    // the teacher, the student is not set a portion the teacher has moved past.
    StudentPlanDay::where('student_plan_id', $this->plan->id)
        ->whereDate('date', '2026-07-03')
        ->update(['review_achievement' => 3]);

    $missions = Livewire::test('student.⚡dashboard')->viewData('pendingMissions');

    expect(collect($missions)->firstWhere('pendingPart', 'review'))->toBeNull();
});

it('falls back to the review for the hero card once hifz is finished', function () {
    StudentPlanDay::where('student_plan_id', $this->plan->id)->update(['hifz_achievement' => 3]);

    $component = Livewire::test('student.⚡dashboard');

    expect($component->viewData('pendingHifzMission'))->toBeNull()
        ->and($component->viewData('pendingReviewMission')->pendingPart)->toBe('review');
});

/**
 * A review plan has no hifz at all, and a plan's review may run on after its
 * hifz ran out: such a day owes no hifz. It was showing as an overdue task
 * with «لا يوجد نص محدد».
 */
it('owes no part on a day with no portion for it', function () {
    StudentPlanDay::where('student_plan_id', $this->plan->id)->update(['review_achievement' => 3]);
    StudentPlanDay::where('student_plan_id', $this->plan->id)->whereDate('date', '2026-07-03')
        ->update(['from_ayah_id' => null, 'to_ayah_id' => null]);

    $missions = Livewire::test('student.⚡dashboard')->viewData('pendingMissions');

    expect($missions)->toBeEmpty();
});
