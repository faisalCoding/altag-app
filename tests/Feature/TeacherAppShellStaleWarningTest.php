<?php

use App\Models\Circle;
use App\Models\Stage;
use App\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('renders the idle-based stale data warning on the teacher app shell', function () {
    $stage = Stage::factory()->create();
    $circle = Circle::factory()->create(['stage_id' => $stage->id]);
    $teacher = Teacher::factory()->create();
    $teacher->circles()->attach($circle->id);

    $response = $this->actingAs($teacher, 'teacher')->get(route('teacher.attendance'));

    $response->assertSuccessful();

    // The warning is armed by inactivity (not page age), counted by the clock
    // and checked again when the page comes back from the background — a
    // locked phone pauses its timers — and can be dismissed.
    $response->assertSee('checkStale', false);
    $response->assertSee('visibilitychange', false);
    $response->assertSee('registerActivity', false);
    $response->assertSee('dismissStaleWarning', false);
    $response->assertSee('قد تكون البيانات غير محدّثة');
    $response->assertSee('env(safe-area-inset-top)', false);
    $response->assertDontSee('🔄');
});

it('opens a teacher page with the asked tab built and the others loading behind it', function () {
    $teacher = Teacher::factory()->create();
    $teacher->circles()->attach(Circle::factory()->create()->id);

    // The dashboard is a tab of the same page: the five in the bottom bar load behind it.
    $dashboard = $this->actingAs($teacher, 'teacher')->get(route('teacher.dashboard'))->assertSuccessful()->getContent();

    expect($dashboard)->toContain('teacher-app-shell')
        ->toContain("activeTab: 'dashboard'")
        // Deferred tabs arrive as placeholders, each loading on its own after the page.
        ->toContain('aria-busy="true"');

    $tasmeeh = $this->get(route('teacher.tasmeeh'))->assertSuccessful()->getContent();

    expect($tasmeeh)->toContain("activeTab: 'tasmeeh'")
        ->toContain('التسميع والمتابعة');
});
