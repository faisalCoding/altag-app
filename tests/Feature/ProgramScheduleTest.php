<?php

use App\Models\Circle;
use App\Models\Guardian;
use App\Models\ScheduleActivity;
use App\Models\ScheduleCell;
use App\Models\ScheduleTrack;
use App\Models\ScheduleWeek;
use App\Models\Setting;
use App\Models\Stage;
use App\Models\Student;
use App\Services\ProgramScheduleService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-09-28 10:00:00'); // Monday of week one.

    Setting::setVal(ProgramScheduleService::SETTING_KEY, json_encode([
        'title' => 'نوابغ المستقبل',
        'start_date' => '2026-09-27',
        'weekdays' => [0, 1, 2, 3, 4],
    ]));

    $this->stage = Stage::factory()->create();
    $this->otherStage = Stage::factory()->create();
    $this->circle = Circle::factory()->create(['stage_id' => $this->stage->id]);
    $this->guardian = Guardian::factory()->create(['is_approved' => true]);
    $this->child = Student::factory()->create([
        'name' => 'سعد',
        'guardian_id' => $this->guardian->id,
        'circle_id' => $this->circle->id,
    ]);

    $this->track = ScheduleTrack::factory()->create(['name' => 'مرحلة الاختبار']);
    $this->track->stages()->attach($this->stage);
    $this->otherTrack = ScheduleTrack::factory()->create(['name' => 'مرحلة أخرى']);
    $this->otherTrack->stages()->attach($this->otherStage);

    $this->lesson = ScheduleActivity::factory()->create(['name' => 'الدرس العلمي', 'icon' => 'bulb', 'color' => 'yellow']);
    $this->prayer = ScheduleActivity::factory()->routine()->create(['name' => 'صلاة المغرب', 'icon' => 'mosque']);

    $this->week = ScheduleWeek::factory()->for($this->track, 'track')->create([
        'memo' => ['poem' => 'المنظومة البيضاء', 'unit' => 'البيتان', 'reps' => '٢٠', 'verses' => [1 => '٣ - ٤']],
    ]);
    ScheduleCell::factory()->for($this->week, 'week')->create(['weekday' => 1, 'position' => 0, 'schedule_activity_id' => $this->prayer->id]);
    ScheduleCell::factory()->for($this->week, 'week')->create([
        'weekday' => 1, 'position' => 1, 'schedule_activity_id' => $this->lesson->id, 'detail' => 'مصادر الإيمان',
    ]);
});

it('shows a guardian today\'s programme for their child\'s track', function () {
    $this->actingAs($this->guardian, 'guardian');

    $this->get(route('guardian.schedule'))
        ->assertSuccessful()
        ->assertSee('جدول البرنامج')
        ->assertSee('الدرس العلمي')
        ->assertSee('مصادر الإيمان')
        ->assertSee('المنظومة البيضاء: البيتان (٣ - ٤)')
        ->assertSee('التكرار: ٢٠ مرة')
        ->assertDontSee('مرحلة أخرى');
});

it('tells a guardian when no track is linked to their children', function () {
    $this->track->stages()->detach();
    $this->actingAs($this->guardian, 'guardian');

    $this->get(route('guardian.schedule'))
        ->assertSuccessful()
        ->assertSee('لا يوجد جدول مرتبط بمراحل أبنائك بعد.')
        ->assertDontSee('الدرس العلمي');
});

it('keeps draft weeks from guardians', function () {
    $this->week->update(['is_draft' => true]);
    $this->actingAs($this->guardian, 'guardian');

    $this->get(route('guardian.schedule'))
        ->assertSuccessful()
        ->assertSee('لم يُنشر جدول هذا الأسبوع بعد.')
        ->assertDontSee('مصادر الإيمان');
});

it('shows the week poster and a month of the changing activities', function () {
    $this->actingAs($this->guardian, 'guardian');

    Livewire::test('shared.program-schedule', ['role' => 'guardian'])
        ->call('show', 'week')
        ->assertSee('الأسبوع الأول')
        ->assertSee('مصادر الإيمان')
        ->assertSee(route('guardian.schedule.print', $this->week))
        ->call('show', 'month')
        ->assertSee('مصادر الإيمان')
        ->assertDontSee('صلاة المغرب')
        ->call('openDay', '2026-09-28')
        ->assertSet('view', 'day')
        ->assertSee('صلاة المغرب');
});

it('lets the screen choose the week\'s layout until the reader does', function () {
    $this->actingAs($this->guardian, 'guardian');

    // Unchosen: the poster on a wide screen, the cards on a phone.
    Livewire::test('shared.program-schedule', ['role' => 'guardian'])
        ->call('show', 'week')
        ->assertSet('layout', '')
        ->assertSeeHtml('overflow-x-auto pb-3 max-md:hidden')
        ->assertSeeHtml('grid gap-4 sm:grid-cols-2 xl:grid-cols-3 md:hidden')
        // Chosen: that one alone, on every screen.
        ->call('setLayout', 'cards')
        ->assertDontSeeHtml('overflow-x-auto pb-3')
        ->assertSeeHtml('class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3"');
});

it('ignores a track the reader is not linked to', function () {
    $this->actingAs($this->guardian, 'guardian');

    Livewire::test('shared.program-schedule', ['role' => 'guardian'])
        ->call('selectTrack', $this->otherTrack->id)
        ->assertSet('trackId', null)
        ->call('selectTrack', $this->track->id)
        ->assertSet('trackId', $this->track->id);
});

it('has no schedule page for a student', function () {
    $this->actingAs($this->child, 'student');

    expect(Route::has('student.schedule'))->toBeFalse()
        ->and(Route::has('student.schedule.print'))->toBeFalse();

    Livewire::test('shared.program-schedule', ['role' => 'student'])->assertForbidden();
});

it('prints only a published week of a readable track', function () {
    $this->actingAs($this->guardian, 'guardian');

    $this->get(route('guardian.schedule.print', $this->week))
        ->assertSuccessful()
        ->assertSee('مرحلة الاختبار')
        ->assertSee('مصادر الإيمان');

    $otherWeek = ScheduleWeek::factory()->for($this->otherTrack, 'track')->create();
    $this->get(route('guardian.schedule.print', $otherWeek))->assertNotFound();

    $this->week->update(['is_draft' => true]);
    $this->get(route('guardian.schedule.print', $this->week))->assertNotFound();
});

it('numbers weeks from the sunday of the start date', function () {
    Setting::setVal(ProgramScheduleService::SETTING_KEY, json_encode(['start_date' => '2026-09-29']));
    $schedule = new ProgramScheduleService;

    expect($schedule->firstSunday()->toDateString())->toBe('2026-09-27')
        ->and($schedule->weekNumberFor(CarbonImmutable::parse('2026-10-04')))->toBe(2)
        ->and($schedule->weekNumberFor(CarbonImmutable::parse('2026-09-26')))->toBe(0)
        ->and($schedule->dateFor(2, 4)->toDateString())->toBe('2026-10-08')
        ->and($schedule->stepProgramDay(CarbonImmutable::parse('2026-10-01'), 1)->toDateString())->toBe('2026-10-04');
});

it('pads each day to the track columns and keeps wide boxes wide', function () {
    $fun = ScheduleActivity::factory()->create(['name' => 'البرنامج الترفيهي']);
    ScheduleCell::factory()->for($this->week, 'week')->create(['weekday' => 4, 'position' => 0, 'schedule_activity_id' => $this->prayer->id]);
    ScheduleCell::factory()->for($this->week, 'week')->create(['weekday' => 4, 'position' => 1, 'span' => 4, 'schedule_activity_id' => $fun->id]);

    $grid = (new ProgramScheduleService)->grid($this->week->load('cells.activity', 'track'));

    expect(collect($grid[1])->pluck('span')->all())->toBe([1, 1, 1, 1, 1])
        ->and(collect($grid[1])->filter(fn ($slot) => $slot['cell'] === null))->toHaveCount(3)
        ->and(collect($grid[4])->pluck('span')->all())->toBe([1, 4])
        ->and($grid[4][1]['cell']->displayName())->toBe('البرنامج الترفيهي');
});

it('seeds the three programme tracks and links them to matching school stages', function () {
    ScheduleTrack::query()->delete();
    ScheduleActivity::query()->delete();
    $primary = Stage::factory()->create(['name' => 'الإبتدائية الاولية']);
    $upper = Stage::factory()->create(['name' => 'الابتدائية العليا']);

    $migration = require database_path('migrations/2026_09_29_081414_seed_future_generations_program_schedule.php');
    $migration->up();

    $tracks = ScheduleTrack::with('stages', 'weeks.cells')->orderBy('sort_order')->get();

    expect($tracks->pluck('name')->all())->toBe(['المرحلة الأولية', 'المرحلة المتوسطة', 'المرحلة العليا'])
        ->and($tracks[0]->stages->pluck('id')->all())->toBe([$primary->id])
        ->and($tracks[1]->stages)->toBeEmpty()
        ->and($tracks[2]->stages->pluck('id')->all())->toBe([$upper->id])
        ->and($tracks[0]->weeks->first()->cells->where('weekday', 4)->pluck('span')->all())->toBe([1, 6])
        ->and(ScheduleActivity::where('name', 'الدرس العلمي')->first()->topics)->toContain('أركان الإيمان الستة');
});
