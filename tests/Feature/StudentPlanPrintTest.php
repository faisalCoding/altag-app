<?php

use App\Models\Ayah;
use App\Models\Circle;
use App\Models\Ode;
use App\Models\OdePath;
use App\Models\OdePathDay;
use App\Models\Student;
use App\Models\StudentOdeAchievement;
use App\Models\StudentOdePlan;
use App\Models\StudentPlan;
use App\Models\StudentPlanDay;
use App\Models\Surah;

beforeEach(function () {
    Surah::create([
        'id' => 1, 'number' => 1, 'name_arabic' => 'الفاتحة', 'name_simple' => 'Al-Fatihah',
        'revelation_place' => 'makkah', 'revelation_order' => 1, 'verses_count' => 7,
        'start_page' => 1, 'end_page' => 1,
    ]);

    foreach (range(1, 4) as $n) {
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

    // One day per grade, plus one still ungraded.
    collect([
        ['date' => '2026-07-01', 'hifz' => 3],
        ['date' => '2026-07-02', 'hifz' => 2],
        ['date' => '2026-07-03', 'hifz' => 1],
        ['date' => '2026-07-04', 'hifz' => null],
    ])->each(fn ($row) => StudentPlanDay::create([
        'student_plan_id' => $this->plan->id,
        'date' => $row['date'],
        'day_name' => 'الأحد',
        'from_ayah_id' => 1,
        'to_ayah_id' => 2,
        'review_from_ayah_id' => 1,
        'review_to_ayah_id' => 2,
        'hifz_achievement' => $row['hifz'],
    ]));

    $this->actingAs($this->student, 'student');
});

it('shows the quran plan with a colour for every graded day', function () {
    $response = $this->get(route('student.plan.print', ['kind' => 'quran', 'id' => $this->plan->id]))
        ->assertOk()
        ->assertSee('خطة الحفظ والمراجعة')
        ->assertSee($this->student->name);

    // Green for ممتاز, blue for جيد, amber for مقبول.
    $response->assertSee('grade-excellent')
        ->assertSee('grade-good')
        ->assertSee('grade-acceptable')
        ->assertSee('ممتاز')
        ->assertSee('جيد')
        ->assertSee('مقبول');
});

it('offers a print action and a colour key', function () {
    $this->get(route('student.plan.print', ['kind' => 'quran', 'id' => $this->plan->id]))
        ->assertSee('طباعة الخطة')
        ->assertSee('دلالة الألوان')
        ->assertSee('لم يُقيَّم');
});

it('renders an ode plan with its grades', function () {
    $ode = Ode::create(['name' => 'منظومة الطباعة']);
    $path = OdePath::create(['ode_id' => $ode->id, 'name' => 'مسار الطباعة', 'start_date' => '2026-07-01']);

    $plan = StudentOdePlan::create([
        'student_id' => $this->student->id,
        'ode_path_id' => $path->id,
        'start_date' => '2026-07-01',
        'status' => 'active',
        'created_by_role' => 'supervisor',
    ]);

    $day = OdePathDay::create([
        'ode_path_id' => $path->id,
        'day_number' => 1,
        'date' => '2026-07-05',
        'from_verse_number' => 1,
        'to_verse_number' => 4,
    ]);

    StudentOdeAchievement::create([
        'student_ode_plan_id' => $plan->id,
        'ode_path_day_id' => $day->id,
        'hifz_achievement' => 2,
    ]);

    $this->get(route('student.plan.print', ['kind' => 'ode', 'id' => $plan->id]))
        ->assertOk()
        ->assertSee('خطة المنظومة')
        ->assertSee('منظومة الطباعة')
        ->assertSee('grade-good');
});

it('refuses another student their plan', function () {
    $intruder = Student::factory()->create(['circle_id' => $this->student->circle_id]);
    $this->actingAs($intruder, 'student');

    $this->get(route('student.plan.print', ['kind' => 'quran', 'id' => $this->plan->id]))
        ->assertNotFound();
});

it('refuses a guest', function () {
    auth('student')->logout();

    $this->get(route('student.plan.print', ['kind' => 'quran', 'id' => $this->plan->id]))
        ->assertRedirect();
});

it('rejects a plan kind it does not serve', function () {
    $this->get('/student/plan/exams/'.$this->plan->id.'/print')->assertNotFound();
});

it('opens the plan with its grades from the plans page', function () {
    $this->get(route('student.plan'))
        ->assertSuccessful()
        ->assertSee('عرض الجدول اليومي')
        ->assertSee(route('student.plan.print', ['kind' => 'quran', 'id' => $this->plan->id]), false)
        ->assertDontSee('/student/plan/show/', false);
});

it('goes back to the page the plan was opened from', function () {
    $print = route('student.plan.print', ['kind' => 'quran', 'id' => $this->plan->id]);

    $this->from(route('student.plan'))->get($print)
        ->assertSee('href="'.route('student.plan').'"', false);

    $this->from('https://example.com/elsewhere')->get($print)
        ->assertSee('href="'.route('student.dashboard').'"', false)
        ->assertDontSee('example.com', false);
});

it('links to each plan kind from the dashboard', function () {
    $ode = Ode::create(['name' => 'منظومة الرابط']);
    $path = OdePath::create(['ode_id' => $ode->id, 'name' => 'مسار', 'start_date' => '2026-07-01']);
    StudentOdePlan::create([
        'student_id' => $this->student->id,
        'ode_path_id' => $path->id,
        'start_date' => '2026-07-01',
        'status' => 'active',
        'created_by_role' => 'supervisor',
    ]);

    $response = $this->get(route('student.dashboard'))->assertOk();

    $response->assertSee('عرض وطباعة الخطة')
        // The ode section did not exist on this dashboard before.
        ->assertSee('خطط المنظومات')
        ->assertSee('منظومة الرابط');
});
