<?php

use App\Livewire\Manager\AttendanceReports;
use App\Livewire\Manager\Stages;
use App\Models\Circle;
use App\Models\Manager;
use App\Models\Stage;
use App\Models\Supervisor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(Manager::factory()->create(), 'manager');

    // Deliberately not alphabetical, so "ordered" cannot pass by accident.
    $this->third = Stage::factory()->create(['name' => 'ابتدائية', 'position' => 3]);
    $this->first = Stage::factory()->create(['name' => 'ثانوية', 'position' => 1]);
    $this->second = Stage::factory()->create(['name' => 'متوسطة', 'position' => 2]);
});

it('returns stages in the arranged order wherever they are asked for', function () {
    expect(Stage::pluck('name')->all())->toBe(['ثانوية', 'متوسطة', 'ابتدائية']);
    expect(Stage::all()->pluck('name')->all())->toBe(['ثانوية', 'متوسطة', 'ابتدائية']);
    expect(Stage::get(['id', 'name'])->pluck('name')->all())->toBe(['ثانوية', 'متوسطة', 'ابتدائية']);
});

it('follows the arrangement through a relation too', function () {
    $supervisor = Supervisor::factory()->create();
    $supervisor->stages()->attach([$this->third->id, $this->first->id, $this->second->id]);

    expect($supervisor->stages()->get()->pluck('name')->all())->toBe(['ثانوية', 'متوسطة', 'ابتدائية']);
});

it('lets a query that means something else by its order keep it', function () {
    // The promotion ladder sorts by rung; position only breaks its ties.
    $this->first->update(['level' => 3]);
    $this->second->update(['level' => 1]);
    $this->third->update(['level' => 2]);

    expect(Stage::orderBy('level')->pluck('name')->all())->toBe(['متوسطة', 'ابتدائية', 'ثانوية']);
});

it('moves a stage up and down from the manager page', function () {
    Livewire::test(Stages::class)->call('moveStage', $this->second->id, -1);

    expect(Stage::pluck('name')->all())->toBe(['متوسطة', 'ثانوية', 'ابتدائية']);

    Livewire::test(Stages::class)->call('moveStage', $this->second->id, 1);

    expect(Stage::pluck('name')->all())->toBe(['ثانوية', 'متوسطة', 'ابتدائية']);
});

it('does nothing at the ends of the list', function () {
    Livewire::test(Stages::class)->call('moveStage', $this->first->id, -1);
    Livewire::test(Stages::class)->call('moveStage', $this->third->id, 1);

    expect(Stage::pluck('name')->all())->toBe(['ثانوية', 'متوسطة', 'ابتدائية']);
});

it('sorts out positions that collided or were never set', function () {
    Stage::query()->update(['position' => 0]);

    // With every position identical the fallback is the name, and a swap can
    // only mean something once each stage owns a distinct number again.
    Livewire::test(Stages::class)->call('moveStage', $this->third->id, 1);

    expect(Stage::pluck('position')->all())->toBe([1, 2, 3]);
});

it('shows the manager the list in its own order, not newest first', function () {
    // The page used to sort by created_at, which hid the very order it sets.
    $page = Livewire::test(Stages::class);

    expect($page->get('stages')->pluck('name')->all())->toBe(['ثانوية', 'متوسطة', 'ابتدائية']);
});

it('lists the attendance report stage by stage in the arranged order', function () {
    // Positions run against the ids on purpose. The report ordered by stage_id,
    // and my first check happened to create the stages in position order, so it
    // passed while proving nothing.
    $oldest = Stage::factory()->create(['name' => 'الأقدم', 'position' => 3]);
    $newest = Stage::factory()->create(['name' => 'الأحدث', 'position' => 1]);

    expect($oldest->id)->toBeLessThan($newest->id);

    foreach ([$oldest, $newest] as $stage) {
        Circle::factory()->create(['stage_id' => $stage->id, 'name' => 'حلقة '.$stage->name]);
    }

    $report = Livewire::test(AttendanceReports::class)
        ->set('fromDate', '2026-09-28')
        ->set('toDate', '2026-09-30');

    // Asserted on what the page actually draws, in the order it draws it.
    $html = $report->html();

    expect(strpos($html, 'الأحدث'))->toBeLessThan(strpos($html, 'الأقدم'));
});

it('moves the report when the manager moves a stage', function () {
    // The guarantee the manager is actually after: arrange them once, and the
    // report that lists them all follows.
    Stage::query()->delete(); // only these two, so one move is one place

    $top = Stage::factory()->create(['name' => 'الأولى', 'position' => 1]);
    $bottom = Stage::factory()->create(['name' => 'الأخيرة', 'position' => 2]);

    foreach ([$top, $bottom] as $stage) {
        Circle::factory()->create(['stage_id' => $stage->id, 'name' => 'حلقة '.$stage->name]);
    }

    $render = fn () => Livewire::test(AttendanceReports::class)
        ->set('fromDate', '2026-09-28')->set('toDate', '2026-09-30')->html();

    $before = $render();
    expect(strpos($before, 'الأولى'))->toBeLessThan(strpos($before, 'الأخيرة'));

    Livewire::test(Stages::class)->call('moveStage', $bottom->id, -1);

    $after = $render();
    expect(strpos($after, 'الأخيرة'))->toBeLessThan(strpos($after, 'الأولى'));
});
