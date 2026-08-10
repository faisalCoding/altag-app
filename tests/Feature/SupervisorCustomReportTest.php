<?php

use App\Livewire\Supervisor\CustomReport;
use App\Models\Circle;
use App\Models\Stage;
use App\Models\Student;
use App\Models\Supervisor;
use App\Support\RolePages;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-07-08 10:00:00');

    $this->supervisor = Supervisor::factory()->create();

    // Two stages under this supervisor, each with a circle.
    $this->stageA = Stage::factory()->create(['name' => 'السنابل']);
    $this->stageB = Stage::factory()->create(['name' => 'الغراس']);
    $this->supervisor->stages()->attach([$this->stageA->id, $this->stageB->id]);

    $this->circleA = Circle::factory()->create(['stage_id' => $this->stageA->id, 'name' => 'حلقة أ']);
    $this->circleB = Circle::factory()->create(['stage_id' => $this->stageB->id, 'name' => 'حلقة ب']);

    $this->studentA = Student::factory()->create(['circle_id' => $this->circleA->id, 'status' => 'active', 'name' => 'طالب أ']);
    $this->studentB = Student::factory()->create(['circle_id' => $this->circleB->id, 'status' => 'active', 'name' => 'طالب ب']);

    // A stage the supervisor does NOT oversee.
    $this->foreignStage = Stage::factory()->create(['name' => 'خارج الإشراف']);
    $this->foreignCircle = Circle::factory()->create(['stage_id' => $this->foreignStage->id]);
    $this->foreignStudent = Student::factory()->create(['circle_id' => $this->foreignCircle->id, 'status' => 'active']);

    $this->actingAs($this->supervisor, 'supervisor');
});

it('shows an empty prompt until something is selected', function () {
    Livewire::test(CustomReport::class)
        ->assertViewHas('hasSelection', false)
        ->assertViewHas('report', null)
        ->assertSee('اختر مرحلة أو حلقة واحدة على الأقل');
});

it('lists only the stages and circles the supervisor oversees', function () {
    Livewire::test(CustomReport::class)
        ->assertViewHas('stages', fn ($stages) => $stages->pluck('id')->sort()->values()->all()
            === collect([$this->stageA->id, $this->stageB->id])->sort()->values()->all())
        ->assertSee('حلقة أ')
        ->assertSee('حلقة ب')
        ->assertDontSee('خارج الإشراف');
});

it('combines two circles from different stages into one report', function () {
    Livewire::test(CustomReport::class)
        ->call('toggleCircle', $this->circleA->id)
        ->call('toggleCircle', $this->circleB->id)
        ->assertViewHas('hasSelection', true)
        ->assertViewHas('students', fn ($students) => $students->pluck('id')->sort()->values()->all()
            === collect([$this->studentA->id, $this->studentB->id])->sort()->values()->all());
});

it('combines whole stages', function () {
    Livewire::test(CustomReport::class)
        ->call('toggleStage', $this->stageA->id)
        ->call('toggleStage', $this->stageB->id)
        ->assertViewHas('students', fn ($students) => $students->count() === 2);
});

it('counts a student once when their circle and its stage are both selected', function () {
    Livewire::test(CustomReport::class)
        ->call('toggleStage', $this->stageA->id)
        ->call('toggleCircle', $this->circleA->id)
        ->assertViewHas('students', fn ($students) => $students->count() === 1
            && $students->first()->id === $this->studentA->id);
});

it('toggles a selection off again', function () {
    Livewire::test(CustomReport::class)
        ->call('toggleCircle', $this->circleA->id)
        ->assertViewHas('hasSelection', true)
        ->call('toggleCircle', $this->circleA->id)
        ->assertViewHas('hasSelection', false);
});

it('ignores circles and stages outside the supervisor scope', function () {
    Livewire::test(CustomReport::class)
        ->set('circleIds', [(string) $this->foreignCircle->id])
        ->set('stageIds', [(string) $this->foreignStage->id])
        ->assertViewHas('hasSelection', false)
        ->assertViewHas('students', fn ($students) => $students->isEmpty());
});

it('builds a report over the combined students', function () {
    Livewire::test(CustomReport::class)
        ->call('toggleStage', $this->stageA->id)
        ->assertViewHas('report', fn ($report) => is_array($report)
            && array_key_exists('totals', $report)
            && array_key_exists('perStudent', $report)
            && $report['perStudent']->count() === 1);
});

it('clears the whole selection', function () {
    Livewire::test(CustomReport::class)
        ->call('toggleStage', $this->stageA->id)
        ->call('toggleCircle', $this->circleB->id)
        ->assertViewHas('hasSelection', true)
        ->call('clearSelection')
        ->assertViewHas('hasSelection', false)
        ->assertSet('circleIds', [])
        ->assertSet('stageIds', []);
});

it('keeps the custom range dates when the preset is custom', function () {
    Livewire::test(CustomReport::class)
        ->set('preset', 'custom')
        ->assertSet('fromDate', '2026-07-02')
        ->assertSet('toDate', '2026-07-08');
});

it('registers the page for the supervisor sidebar', function () {
    expect(RolePages::isEnabled('supervisor', 'supervisor.custom-reports'))->toBeTrue();
});
