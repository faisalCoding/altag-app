<?php

use App\Models\AppNotification;
use App\Models\Ayah;
use App\Models\Circle;
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
    Surah::create([
        'id' => 1, 'number' => 1, 'name_arabic' => 'الفاتحة', 'name_simple' => 'Al-Fatihah',
        'revelation_place' => 'makkah', 'revelation_order' => 1, 'verses_count' => 7,
        'start_page' => 1, 'end_page' => 1,
    ]);
    Ayah::create([
        'id' => 1, 'surah_id' => 1, 'verse_number' => 1, 'page_number' => 1,
        'line_number_start' => 1, 'line_number_end' => 1, 'verse_key' => '1:1',
        'juz_number' => 1, 'hizb_number' => 1, 'rub_number' => 1, 'ruku_number' => 1,
        'manzil_number' => 1, 'text_uthmani' => 'بسم الله',
    ]);

    $this->teacher = Teacher::factory()->create();
    $this->circle = Circle::factory()->create();
    $this->teacher->circles()->attach($this->circle->id);

    $this->student = Student::factory()->create([
        'circle_id' => $this->circle->id,
        'status' => 'active',
        'is_approved' => true,
    ]);

    $this->plan = StudentPlan::create([
        'student_id' => $this->student->id,
        'teacher_id' => $this->teacher->id,
        'start_date' => now()->subDays(2),
        'days_count' => 5,
        'active_days' => [0, 1, 2, 3, 4, 5, 6],
        'description' => 'Test Plan',
        'status' => 'active',
        'plan_type' => 'hifz_review',
        'direction' => 'forward',
        'is_approved' => true,
        'created_by_role' => 'teacher',
    ]);

    foreach (range(0, 4) as $i) {
        StudentPlanDay::create([
            'student_plan_id' => $this->plan->id,
            'date' => now()->subDays(2)->addDays($i)->format('Y-m-d'),
            'day_name' => now()->subDays(2)->addDays($i)->dayName,
            'from_ayah_id' => 1,
            'to_ayah_id' => 1,
            'review_from_ayah_id' => 1,
            'review_to_ayah_id' => 1,
            'hifz_achievement' => null,
            'review_achievement' => null,
        ]);
    }

    $this->actingAs($this->teacher, 'teacher');
});

it('renders the student tasmeeh card standalone without the parent preload caches', function () {
    // Simulates a lazy load: the parent's app()->instance(...) caches are NOT set,
    // so the card must fall back to fetching its own plan days.
    expect(app()->bound('tasmeeh_days_cache'))->toBeFalse();

    $sPlans = StudentPlan::where('student_id', $this->student->id)->latest()->get();

    Livewire::test('teacher.⚡student-tasmeeh-card', [
        'student' => $this->student,
        'sPlans' => $sPlans,
        'activePlanId' => $this->plan->id,
    ])->assertOk();
});

it('renders the tasmeeh-manager with lazy card placeholders instead of full cards', function () {
    $html = Livewire::test('teacher.⚡tasmeeh-manager')->html();

    // The lazy placeholder (from the card's placeholder() method) is shown
    // instead of the full, heavy card markup on the initial render.
    expect($html)->toContain('animate-pulse');
});

it('keeps the tasmeeh-manager initial render cheap (no per-card plan-day queries)', function () {
    DB::enableQueryLog();
    Livewire::test('teacher.⚡tasmeeh-manager');
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    // No eager preload of every plan's days with the 4 ayah relations anymore.
    $loadedAllPlanDaysWithAyahs = collect($queries)->contains(
        fn ($q) => str_contains($q['query'], 'student_plan_days')
            && str_contains($q['query'], 'review_from_ayah_id')
    );

    expect($loadedAllPlanDaysWithAyahs)->toBeFalse();
});

/**
 * The card holds every day of every plan at once, so a re-render ships
 * megabytes. Grading a day changes nothing else on screen — the button
 * highlights itself from Alpine state — so the response must carry no HTML.
 */
it('saves a grade without re-rendering the whole card', function () {
    $day = StudentPlanDay::where('student_plan_id', $this->plan->id)->first();

    $component = Livewire::test('teacher.⚡student-tasmeeh-card', [
        'student' => $this->student,
        'sPlans' => StudentPlan::where('student_id', $this->student->id)->latest()->get(),
        'activePlanId' => $this->plan->id,
    ])->call('saveAchievement', $day->id, 'hifz', 3);

    expect($component->effects)->not->toHaveKey('html');

    // The grade still lands, and is credited with a grading time.
    $day->refresh();
    expect($day->hifz_achievement)->toBe(3)
        ->and($day->hifz_graded_at)->not->toBeNull();
});

it('drives the grade highlight from client state so a tap shows at once', function () {
    $html = Livewire::test('teacher.⚡student-tasmeeh-card', [
        'student' => $this->student,
        'sPlans' => StudentPlan::where('student_id', $this->student->id)->latest()->get(),
        'activePlanId' => $this->plan->id,
    ])->html();

    // The selected state is bound to Alpine, not baked in by the server.
    expect($html)->toContain('hifz.achievement === 3 ?')
        ->and($html)->toContain('review.achievement === 0 ?');
});

/**
 * The card used to render all twenty-five days and hide all but one with
 * x-show. The days now travel as data and the browser renders the one on show.
 */
it('sends the quran days as data rather than as twenty-five hidden cards', function () {
    $html = Livewire::test('teacher.⚡student-tasmeeh-card', [
        'student' => $this->student,
        'sPlans' => StudentPlan::where('student_id', $this->student->id)->latest()->get(),
        'activePlanId' => $this->plan->id,
    ])->html();

    expect($html)->toContain('quranDays:')
        ->and($html)->toContain('currentQuranDay()')
        // One card, not one per day.
        ->and(substr_count($html, 'تقييم الإنجاز (التسميع)'))->toBe(2);
});

/**
 * A student may follow more than one plan at a time, and the select at the top
 * of the card switches between them.
 *
 * Alpine evaluates x-data once and hands that scope to the children it
 * initialises. Livewire keys a component's root element by its wire:id, so the
 * root is morphed in place however much its day data changed — day data held
 * there would go on serving the plan the teacher had just switched away from,
 * while the server-rendered headings followed the new one. A key on a child
 * element is honoured, so the section is replaced and its scope rebuilt.
 */
it('hangs the quran days on an element keyed by the plan, not on the component root', function () {
    $html = Livewire::test('teacher.⚡student-tasmeeh-card', [
        'student' => $this->student,
        'sPlans' => StudentPlan::where('student_id', $this->student->id)->latest()->get(),
        'activePlanId' => $this->plan->id,
    ])->html();

    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="UTF-8">'.$html);

    $holders = (new DOMXPath($document))->query('//*[contains(@x-data, "quranDays:")]');

    expect($holders->length)->toBe(1)
        ->and($holders->item(0)->getAttribute('wire:key'))->toBe('quran-plan-'.$this->plan->id);
});

it('shows the days of the plan the teacher switches to', function () {
    $other = StudentPlan::create([
        'student_id' => $this->student->id,
        'teacher_id' => $this->teacher->id,
        'start_date' => now()->addDays(10),
        'days_count' => 3,
        'active_days' => [0, 1, 2, 3, 4, 5, 6],
        'description' => 'Second Plan',
        'status' => 'active',
        'plan_type' => 'hifz',
        'direction' => 'forward',
        'is_approved' => true,
        'created_by_role' => 'teacher',
    ]);

    foreach (range(0, 2) as $i) {
        StudentPlanDay::create([
            'student_plan_id' => $other->id,
            'date' => now()->addDays(10 + $i)->format('Y-m-d'),
            'day_name' => now()->addDays(10 + $i)->dayName,
            'from_ayah_id' => 1,
            'to_ayah_id' => 1,
        ]);
    }

    $html = Livewire::test('teacher.⚡student-tasmeeh-card', [
        'student' => $this->student,
        'sPlans' => StudentPlan::where('student_id', $this->student->id)->latest()->get(),
        'activePlanId' => $this->plan->id,
    ])->call('selectPlan', $other->id)->html();

    $firstPlanDayIds = StudentPlanDay::where('student_plan_id', $this->plan->id)->pluck('id');
    $otherPlanDayIds = StudentPlanDay::where('student_plan_id', $other->id)->pluck('id');

    // The key changes with the plan, which is what makes the browser rebuild
    // the scope rather than keep the days it already had.
    expect($html)->toContain('wire:key="quran-plan-'.$other->id.'"')
        ->and($html)->not->toContain('wire:key="quran-plan-'.$this->plan->id.'"');

    foreach ($otherPlanDayIds as $id) {
        expect($html)->toContain('\u0022id\u0022:'.$id.',');
    }

    foreach ($firstPlanDayIds as $id) {
        expect($html)->not->toContain('\u0022id\u0022:'.$id.',');
    }
});

it('leaves no grade button waiting on a server round trip to highlight', function () {
    $html = Livewire::test('teacher.⚡student-tasmeeh-card', [
        'student' => $this->student,
        'sPlans' => StudentPlan::where('student_id', $this->student->id)->latest()->get(),
        'activePlanId' => $this->plan->id,
    ])->html();

    // The old markup disabled every grade button until the server replied.
    expect($html)->not->toContain('syncingTask')
        ->and($html)->not->toContain('disabled:cursor-wait');
});

/**
 * «لم يسمع» is now a grade of its own (0), kept apart from a day not graded
 * yet (null): it is stamped like any grade, earns nothing and notifies no one.
 */
it('stores «لم يسمع» as 0, apart from not graded', function () {
    $day = StudentPlanDay::where('student_plan_id', $this->plan->id)->first();
    $card = fn () => Livewire::test('teacher.⚡student-tasmeeh-card', [
        'student' => $this->student,
        'sPlans' => StudentPlan::where('student_id', $this->student->id)->latest()->get(),
        'activePlanId' => $this->plan->id,
    ]);

    $card()->call('saveAchievement', $day->id, 'hifz', 0)->assertSuccessful();

    $day->refresh();
    expect($day->hifz_achievement)->toBe(0)
        ->and($day->hifz_graded_at)->not->toBeNull()
        ->and($day->hifz_recorded_by)->toBe($this->teacher->id)
        ->and(AppNotification::count())->toBe(0);

    $card()->call('saveAchievement', $day->id, 'hifz', null)->assertSuccessful();

    $day->refresh();
    expect($day->hifz_achievement)->toBeNull()
        ->and($day->hifz_graded_at)->toBeNull();
});

it('refuses anything that is not a grade', function (mixed $value) {
    $day = StudentPlanDay::where('student_plan_id', $this->plan->id)->first();

    Livewire::test('teacher.⚡student-tasmeeh-card', [
        'student' => $this->student,
        'sPlans' => StudentPlan::where('student_id', $this->student->id)->latest()->get(),
        'activePlanId' => $this->plan->id,
    ])->call('saveAchievement', $day->id, 'hifz', $value)->assertStatus(422);

    expect($day->fresh()->hifz_achievement)->toBeNull();
})->with(['four' => 4, 'text' => 'abc', 'empty' => '', 'minus' => -1]);

it('shows what was actually recited when it differs from the wird', function () {
    $day = StudentPlanDay::where('student_plan_id', $this->plan->id)->first();
    $day->update(['hifz_recited_from_ayah_id' => 1, 'hifz_recited_to_ayah_id' => 1]);

    $html = Livewire::test('teacher.⚡student-tasmeeh-card', [
        'student' => $this->student,
        'sPlans' => StudentPlan::where('student_id', $this->student->id)->latest()->get(),
        'activePlanId' => $this->plan->id,
    ])->html();

    expect($html)->toContain('المُسمَّع فعلياً:')
        ->and($html)->toContain('recited_range');
});

it('opens on a day marked «لم يسمع», which the student still owes', function () {
    $days = StudentPlanDay::where('student_plan_id', $this->plan->id)->orderBy('date')->get();
    $days[0]->update(['hifz_achievement' => 3, 'review_achievement' => 3, 'hifz_graded_at' => now(), 'review_graded_at' => now()]);
    $days[1]->update(['hifz_achievement' => 0, 'review_achievement' => 0, 'hifz_graded_at' => now(), 'review_graded_at' => now()]);

    $html = Livewire::test('teacher.⚡student-tasmeeh-card', [
        'student' => $this->student,
        'sPlans' => StudentPlan::where('student_id', $this->student->id)->latest()->get(),
        'activePlanId' => $this->plan->id,
    ])->html();

    expect($html)->toContain('activeDayId: '.$days[1]->id);
});
