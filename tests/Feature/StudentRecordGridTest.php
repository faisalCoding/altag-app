<?php

use App\Livewire\Supervisor\StudentRecord;
use App\Models\Attendance;
use App\Models\Circle;
use App\Models\FreeRecitation;
use App\Models\Ode;
use App\Models\OdePath;
use App\Models\OdePathDay;
use App\Models\PlanDayAttempt;
use App\Models\Stage;
use App\Models\Student;
use App\Models\StudentOdeAchievement;
use App\Models\StudentOdePlan;
use App\Models\StudentPlan;
use App\Models\StudentPlanDay;
use App\Models\Supervisor;
use App\Models\Surah;
use App\Services\StudentRecordGrid;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Thursday; the week began on Sunday the 11th.
    Carbon::setTestNow('2026-10-15 10:00:00');

    $this->supervisor = Supervisor::factory()->create();
    $this->stage = Stage::factory()->create(['name' => 'السنابل']);
    $this->supervisor->stages()->attach($this->stage->id);
    $this->circle = Circle::factory()->create(['stage_id' => $this->stage->id, 'name' => 'حلقة النور']);

    $this->ahmad = Student::factory()->create(['circle_id' => $this->circle->id, 'status' => 'active', 'name' => 'أحمد']);
    $this->badr = Student::factory()->create(['circle_id' => $this->circle->id, 'status' => 'active', 'name' => 'بدر']);

    $this->foreignCircle = Circle::factory()->create(['stage_id' => Stage::factory()->create()->id, 'name' => 'حلقة بعيدة']);
    $this->stranger = Student::factory()->create(['circle_id' => $this->foreignCircle->id, 'status' => 'active', 'name' => 'غريب']);

    $surah = Surah::create([
        'number' => 1, 'name_arabic' => 'الفاتحة', 'name_simple' => 'Al-Fatihah', 'revelation_place' => 'makkah',
        'revelation_order' => 1, 'verses_count' => 12, 'start_page' => 1, 'end_page' => 1,
    ]);
    foreach (range(1, 12) as $id) {
        DB::table('ayahs')->insert([
            'id' => $id, 'surah_id' => $surah->id, 'verse_number' => $id, 'verse_key' => "1:{$id}",
            'juz_number' => 1, 'hizb_number' => 1, 'rub_number' => 1, 'page_number' => 1,
            'line_number_start' => $id, 'line_number_end' => $id,
            'ruku_number' => 1, 'manzil_number' => 1, 'text_uthmani' => 'نص',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    foreach ([
        [$this->ahmad, '2026-10-12', 'present'], [$this->ahmad, '2026-10-13', 'absent'], [$this->ahmad, '2026-10-14', 'late'],
        [$this->badr, '2026-10-12', 'excused'],
    ] as [$student, $date, $status]) {
        Attendance::create(['student_id' => $student->id, 'circle_id' => $this->circle->id, 'date' => $date, 'status' => $status]);
    }

    // Ahmad on the 12th: a hifz, a «لم يسمع» review and a free recitation — two recited.
    $day = recordPlanDay($this->ahmad, '2026-10-12');
    PlanDayAttempt::create(['student_plan_day_id' => $day->id, 'part' => 'hifz', 'recited_on' => '2026-10-12', 'grade' => 3]);
    PlanDayAttempt::create(['student_plan_day_id' => $day->id, 'part' => 'review', 'recited_on' => '2026-10-12', 'grade' => 0]);
    FreeRecitation::create([
        'student_id' => $this->ahmad->id, 'type' => 'review', 'recited_on' => '2026-10-12',
        'from_ayah_id' => 1, 'to_ayah_id' => 7, 'achievement' => 2, 'graded_at' => '2026-10-12 09:00:00',
    ]);

    // Badr on the 14th: only a «لم يسمع».
    $badrDay = recordPlanDay($this->badr, '2026-10-14');
    PlanDayAttempt::create(['student_plan_day_id' => $badrDay->id, 'part' => 'hifz', 'recited_on' => '2026-10-14', 'grade' => 0]);
});

function recordPlanDay(Student $student, string $date): StudentPlanDay
{
    $plan = StudentPlan::firstOrCreate(['student_id' => $student->id], [
        'start_date' => '2026-10-01', 'days_count' => 10, 'status' => 'active',
        'plan_type' => 'hifz_review', 'active_days' => ['sunday'], 'is_approved' => true, 'direction' => 'forward',
    ]);

    return StudentPlanDay::create(['student_plan_id' => $plan->id, 'date' => $date, 'day_name' => 'الأحد', 'from_ayah_id' => 8, 'to_ayah_id' => 12]);
}

/**
 * @return array<string, array<string, mixed>>
 */
function recordRows(array $grid): array
{
    return collect($grid['circles'])->flatMap(fn (array $circle) => $circle['students'])->keyBy('name')->all();
}

it('draws each day in its attendance colour with the portions recited on it, «لم يسمع» showing as 0', function () {
    $rows = recordRows(StudentRecordGrid::build([$this->circle->id], '2026-10-11', '2026-10-15'));

    expect($rows['أحمد']['cells']['2026-10-12'])->toBe(['status' => 'present', 'recited' => 2, 'graded' => true, 'open' => true])
        ->and($rows['أحمد']['cells']['2026-10-13'])->toBe(['status' => 'absent', 'recited' => 0, 'graded' => false, 'open' => true])
        ->and($rows['بدر']['cells']['2026-10-14'])->toBe(['status' => null, 'recited' => 0, 'graded' => true, 'open' => true]);
});

it('reports under every day and beside every student', function () {
    $grid = StudentRecordGrid::build([$this->circle->id], '2026-10-11', '2026-10-15');
    $rows = recordRows($grid);

    // Present and late over present, late and absent; the excused day is no absence.
    expect($rows['أحمد']['totals'])->toMatchArray(['present' => 1, 'late' => 1, 'absent' => 1, 'excused' => 0, 'rate' => 67, 'recited' => 2])
        ->and($rows['بدر']['totals'])->toMatchArray(['excused' => 1, 'rate' => null, 'recited' => 0])
        ->and($grid['days']['2026-10-12'])->toMatchArray(['present' => 1, 'excused' => 1, 'marked' => 2, 'rate' => 100, 'recited' => 2])
        ->and($grid['totals'])->toMatchArray(['students' => 2, 'present' => 1, 'late' => 1, 'absent' => 1, 'excused' => 1, 'rate' => 67, 'recited' => 2]);
});

it('lays out the days the circles meet, up to today', function () {
    $dates = collect(StudentRecordGrid::build([$this->circle->id], '2026-10-08', '2026-10-20')['dates']);

    // Friday the 9th and Saturday the 10th are off; nothing after today.
    expect($dates->pluck('date')->all())->toBe(['2026-10-08', '2026-10-11', '2026-10-12', '2026-10-13', '2026-10-14', '2026-10-15'])
        ->and($dates->firstWhere('date', '2026-10-11')['week_start'])->toBeTrue()
        ->and($dates->firstWhere('date', '2026-10-15')['today'])->toBeTrue();
});

it('leaves a day before the student joined out of their record', function () {
    $newcomer = Student::factory()->create(['circle_id' => $this->circle->id, 'status' => 'active', 'name' => 'جديد', 'joined_at' => '2026-10-14']);

    $cells = recordRows(StudentRecordGrid::build([$this->circle->id], '2026-10-11', '2026-10-15'))['جديد']['cells'];

    expect($cells['2026-10-13']['open'])->toBeFalse()
        ->and($cells['2026-10-14']['open'])->toBeTrue();
});

it('shows the supervisor their own circles alone, and opens a day without drawing the table again', function () {
    $this->actingAs($this->supervisor, 'supervisor');

    $page = Livewire::test(StudentRecord::class)
        ->set('from', '2026-10-11')
        ->assertSee('أحمد')
        ->assertSee('حلقة النور')
        ->assertDontSee('غريب')
        // A square carries no ids: its row names the student, its column the day.
        ->assertSeeHtml('data-s="'.$this->ahmad->id.'"')
        ->assertSeeHtml('data-date="2026-10-12"')
        ->assertSeeHtml('<button type="button" class="rec-sq rec-present" aria-label="حاضر">٢</button>');

    $page->call('day', $this->ahmad->id, '2026-10-12')
        ->assertReturned(fn (array $day) => $day['status'] === 'present'
            && collect($day['recitations'])->pluck('grade')->sort()->values()->all() === [0, 2, 3]
            && collect($day['recitations'])->pluck('title')->contains('المراجعة · تسميع حر'));

    // Refused: the page's call reads nothing back, and the day shows it could not be read.
    $page->call('day', $this->stranger->id, '2026-10-12')->assertReturned(null);
});

it('keeps the dates to days gone by and a quarter of a year apart', function () {
    $this->actingAs($this->supervisor, 'supervisor');

    Livewire::test(StudentRecord::class)
        ->set('to', '2026-12-01')->assertSet('to', '2026-10-15')
        ->set('from', '2026-11-01')->assertSet('from', '2026-10-15')
        ->set('from', '2026-01-01')->assertSet('to', Carbon::parse('2026-01-01')->addDays(StudentRecord::MAX_DAYS - 1)->toDateString())
        ->set('circle', (string) $this->foreignCircle->id)->assertSet('circle', '');
});

it('opens from the supervisor\'s menu', function () {
    $this->actingAs($this->supervisor, 'supervisor')
        ->get(route('supervisor.student-record'))
        ->assertOk()
        ->assertSee('سجل الطلاب');
});

it('counts the odes too, and narrows every square to the kind the filter picks', function () {
    // Ahmad also recited an ode on the 12th, graded on Riyadh's 12th though it was still the 11th in UTC.
    $ode = Ode::create(['name' => 'تحفة الأطفال']);
    $path = OdePath::create(['ode_id' => $ode->id, 'name' => 'مسار التحفة', 'start_date' => '2026-10-01', 'end_date' => '2026-11-01']);
    $day = OdePathDay::create(['ode_path_id' => $path->id, 'day_number' => 1, 'date' => '2026-10-12', 'day_name' => 'الاثنين', 'from_verse_number' => 1, 'to_verse_number' => 4]);
    $plan = StudentOdePlan::create(['student_id' => $this->ahmad->id, 'ode_path_id' => $path->id, 'start_date' => '2026-10-01', 'status' => 'active', 'created_by_role' => 'supervisor']);
    StudentOdeAchievement::create(['student_ode_plan_id' => $plan->id, 'ode_path_day_id' => $day->id, 'hifz_achievement' => 3, 'hifz_graded_at' => '2026-10-11 22:30:00']);

    $cell = fn (?string $filter) => recordRows(StudentRecordGrid::build([$this->circle->id], '2026-10-11', '2026-10-15', $filter))['أحمد']['cells']['2026-10-12'];

    expect($cell(null)['recited'])->toBe(3)
        ->and($cell('ode')['recited'])->toBe(1)
        ->and($cell('hifz')['recited'])->toBe(1)
        ->and($cell('review')['recited'])->toBe(1)
        // Only a «لم يسمع» review on the plan: graded, nothing recited.
        ->and(recordRows(StudentRecordGrid::build([$this->circle->id], '2026-10-11', '2026-10-15', 'hadith'))['أحمد']['cells']['2026-10-12'])
        ->toMatchArray(['recited' => 0, 'graded' => false]);

    $this->actingAs($this->supervisor, 'supervisor');
    Livewire::test(StudentRecord::class)
        ->set('from', '2026-10-11')
        ->assertSee('المنظومات')
        ->set('filter', 'ode')
        ->call('day', $this->ahmad->id, '2026-10-12')
        ->assertReturned(fn (array $day) => collect($day['recitations'])->pluck('title')->all() === ['المنظومات · حفظ']
            && $day['recitations'][0]['range'] === 'تحفة الأطفال: الأبيات 1 - 4');
});

it('offers the mutun and the odes only where a stage memorises them', function () {
    $this->stage->update(['hadith_enabled' => false]);
    $this->actingAs($this->supervisor, 'supervisor');

    Livewire::test(StudentRecord::class)
        ->assertSeeHtml('wire:click="$set(\'filter\', \'ode\')"')
        ->assertDontSeeHtml('wire:click="$set(\'filter\', \'hadith\')"')
        ->set('filter', 'hadith')
        ->assertSet('filter', '');
});
