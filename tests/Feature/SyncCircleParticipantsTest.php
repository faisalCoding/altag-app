<?php

use App\Models\Circle;
use App\Models\Stage;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon\Carbon::setTestNow('2026-09-15 09:00:00');

    $this->circle = Circle::factory()->create(['name' => 'جامعيين', 'stage_id' => Stage::factory()->create()->id]);

    $this->staying = Student::factory()->create(['circle_id' => $this->circle->id, 'name' => 'عبدالله أحمد شلبي', 'status' => 'left']);
    $this->alsoStaying = Student::factory()->create(['circle_id' => $this->circle->id, 'name' => 'وسام عكيش', 'status' => 'left']);
    $this->leaving = Student::factory()->create(['circle_id' => $this->circle->id, 'name' => 'طالب سابق', 'status' => 'active']);

    $this->elsewhere = Student::factory()->create(['name' => 'طالب حلقة أخرى', 'status' => 'active']);
});

it('changes nothing without --apply', function () {
    $this->artisan('circle:participants', [
        'circle' => 'جامعيين',
        '--names' => 'عبدالله أحمد شلبي,وسام عكيش',
    ])->assertSuccessful();

    expect($this->staying->fresh()->status)->toBe('left');
    expect($this->leaving->fresh()->status)->toBe('active');
});

it('marks the listed students as participating and everyone else as left', function () {
    $this->artisan('circle:participants', [
        'circle' => 'جامعيين',
        '--names' => 'عبدالله أحمد شلبي,وسام عكيش',
        '--apply' => true,
    ])->assertSuccessful();

    expect($this->staying->fresh()->status)->toBe('active');
    expect($this->alsoStaying->fresh()->status)->toBe('active');
    expect($this->leaving->fresh()->status)->toBe('left');
});

it('backdates the change so last week can still be marked', function () {
    $this->artisan('circle:participants', [
        'circle' => 'جامعيين',
        '--names' => 'عبدالله أحمد شلبي,وسام عكيش',
        '--apply' => true,
    ]);

    // Default effective date is a week ago, not today.
    $history = $this->staying->statusHistories()->where('status', 'active')->first();

    expect($history->start_date->format('Y-m-d'))->toBe('2026-09-08');
});

it('honours an explicit effective date', function () {
    $this->artisan('circle:participants', [
        'circle' => 'جامعيين',
        '--names' => 'وسام عكيش',
        '--since' => '2026-09-01',
        '--apply' => true,
    ])->assertSuccessful();

    expect($this->alsoStaying->statusHistories()->where('status', 'active')->first()->start_date->format('Y-m-d'))
        ->toBe('2026-09-01');
});

it('never touches a student in another circle', function () {
    $this->artisan('circle:participants', [
        'circle' => 'جامعيين',
        '--names' => 'وسام عكيش',
        '--apply' => true,
    ]);

    expect($this->elsewhere->fresh()->status)->toBe('active');
});

it('refuses the whole run when one name does not match', function () {
    $this->artisan('circle:participants', [
        'circle' => 'جامعيين',
        '--names' => 'وسام عكيش,اسم غير موجود',
        '--apply' => true,
    ])->assertFailed();

    // Nothing at all may change, including the name that did match.
    expect($this->alsoStaying->fresh()->status)->toBe('left');
    expect($this->leaving->fresh()->status)->toBe('active');
});

it('suggests the closest name when one is mistyped', function () {
    $this->artisan('circle:participants', [
        'circle' => 'جامعيين',
        '--names' => 'وسام عكيشي',
    ])
        ->expectsOutputToContain('وسام عكيش')
        ->assertFailed();
});

it('matches across spacing, hamza and tatweel', function () {
    $this->artisan('circle:participants', [
        'circle' => 'جامعيين',
        // "عبد الله" spaced, bare alef, and a stray trailing space.
        '--names' => 'عبد الله احمد شلبي ',
        '--apply' => true,
    ])->assertSuccessful();

    expect($this->staying->fresh()->status)->toBe('active');
});

it('strips the numbering off a pasted list', function () {
    $path = tempnam(sys_get_temp_dir(), 'roll').'.txt';
    file_put_contents($path, "١. عبدالله أحمد شلبي\n٢. وسام عكيش\n");

    $this->artisan('circle:participants', [
        'circle' => 'جامعيين',
        '--file' => $path,
        '--apply' => true,
    ])->assertSuccessful();

    expect($this->staying->fresh()->status)->toBe('active');
    expect($this->alsoStaying->fresh()->status)->toBe('active');

    unlink($path);
});

it('refuses a list that names someone twice', function () {
    $this->artisan('circle:participants', [
        'circle' => 'جامعيين',
        '--names' => 'وسام عكيش,وسام عكيش',
        '--apply' => true,
    ])->assertFailed();

    expect($this->alsoStaying->fresh()->status)->toBe('left');
});

it('refuses an ambiguous circle', function () {
    Circle::factory()->create(['name' => 'جامعيين 2', 'stage_id' => $this->circle->stage_id]);

    $this->artisan('circle:participants', [
        'circle' => 'جامعيين',
        '--names' => 'وسام عكيش',
    ])->assertFailed();
});

it('refuses a malformed date', function () {
    $this->artisan('circle:participants', [
        'circle' => 'جامعيين',
        '--names' => 'وسام عكيش',
        '--since' => '15/09/2026',
        '--apply' => true,
    ])->assertFailed();

    expect($this->alsoStaying->fresh()->status)->toBe('left');
});
