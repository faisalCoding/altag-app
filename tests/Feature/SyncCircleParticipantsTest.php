<?php

use App\Models\Circle;
use App\Models\Stage;
use App\Models\Student;
use App\Models\StudentStatusHistory;
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

it('refuses an unknown name unless creating is asked for', function () {
    $this->artisan('circle:participants', [
        'circle' => 'جامعيين',
        '--names' => 'وسام عكيش,طالب جديد تماماً',
        '--apply' => true,
    ])->assertFailed();

    expect(Student::where('name', 'طالب جديد تماماً')->exists())->toBeFalse();
});

it('creates an account for a name nobody has', function () {
    $this->artisan('circle:participants', [
        'circle' => 'جامعيين',
        '--names' => 'وسام عكيش,طالب جديد تماماً',
        '--create' => true,
        '--apply' => true,
    ])->assertSuccessful();

    $created = Student::where('name', 'طالب جديد تماماً')->first();

    expect($created)->not->toBeNull();
    expect($created->circle_id)->toBe($this->circle->id);
    expect($created->status)->toBe('active');
    expect($created->joined_at->format('Y-m-d'))->toBe('2026-09-08');
    expect($created->email)->toEndWith('@altag.local');
});

it('approves a created student, or the teacher could never mark them', function () {
    $this->artisan('circle:participants', [
        'circle' => 'جامعيين',
        '--names' => 'طالب جديد تماماً',
        '--create' => true,
        '--apply' => true,
    ]);

    $created = Student::whereRoleState(fn ($q) => $q->where('is_approved', true))
        ->where('name', 'طالب جديد تماماً')
        ->first();

    expect($created)->not->toBeNull();
});

it('gives every created student a login that works', function () {
    $this->artisan('circle:participants', [
        'circle' => 'جامعيين',
        '--names' => 'طالب أول جديد,طالب ثان جديد',
        '--create' => true,
        '--apply' => true,
    ]);

    $emails = Student::whereIn('name', ['طالب أول جديد', 'طالب ثان جديد'])->pluck('email');

    expect($emails)->toHaveCount(2);
    expect($emails->unique())->toHaveCount(2);
    expect(Student::whereIn('name', ['طالب أول جديد', 'طالب ثان جديد'])->pluck('password')->filter())->toHaveCount(2);
});

it('writes the new credentials to a file when asked', function () {
    $path = tempnam(sys_get_temp_dir(), 'creds').'.csv';

    $this->artisan('circle:participants', [
        'circle' => 'جامعيين',
        '--names' => 'طالب جديد تماماً',
        '--create' => true,
        '--credentials' => $path,
        '--apply' => true,
    ])->assertSuccessful();

    $csv = file_get_contents($path);

    expect($csv)->toContain('طالب جديد تماماً')->toContain('@altag.local');
    expect(substr(sprintf('%o', fileperms($path)), -4))->toBe('0600');

    unlink($path);
});

it('moves an existing student in rather than creating a second of them', function () {
    $otherCircle = Circle::factory()->create(['name' => 'حلقة أخرى', 'stage_id' => $this->circle->stage_id]);
    $wanderer = Student::factory()->create(['circle_id' => $otherCircle->id, 'name' => 'طالب متنقل', 'status' => 'left']);

    $this->artisan('circle:participants', [
        'circle' => 'جامعيين',
        '--names' => 'طالب متنقل',
        '--create' => true,
        '--apply' => true,
    ])->assertSuccessful();

    expect(Student::where('name', 'طالب متنقل')->count())->toBe(1);
    expect($wanderer->fresh()->circle_id)->toBe($this->circle->id);
    expect($wanderer->fresh()->status)->toBe('active');
});

it('treats a near-miss as a typo rather than a new person', function () {
    // One letter away from "وسام عكيش" already on the roll.
    $this->artisan('circle:participants', [
        'circle' => 'جامعيين',
        '--names' => 'وسام عكيشي',
        '--create' => true,
        '--apply' => true,
    ])
        ->expectsOutputToContain('قريب جداً')
        ->assertFailed();

    expect(Student::where('name', 'وسام عكيشي')->exists())->toBeFalse();
});

it('creates a near-miss anyway when told to', function () {
    $this->artisan('circle:participants', [
        'circle' => 'جامعيين',
        '--names' => 'وسام عكيشي',
        '--create' => true,
        '--force' => true,
        '--apply' => true,
    ])->assertSuccessful();

    expect(Student::where('name', 'وسام عكيشي')->exists())->toBeTrue();
});

it('creates nothing on a dry run', function () {
    $this->artisan('circle:participants', [
        'circle' => 'جامعيين',
        '--names' => 'طالب جديد تماماً',
        '--create' => true,
    ])->assertSuccessful();

    expect(Student::where('name', 'طالب جديد تماماً')->exists())->toBeFalse();
});

it('rolls the created accounts back if a later step fails', function () {
    // A status change that cannot be backdated past an earlier one makes the
    // service throw part way through the transaction.
    StudentStatusHistory::create([
        'student_id' => $this->leaving->id,
        'status' => 'active',
        'start_date' => '2026-09-01',
    ]);
    StudentStatusHistory::create([
        'student_id' => $this->leaving->id,
        'status' => 'left',
        'start_date' => '2026-09-10',
    ]);
    $this->leaving->update(['status' => 'left']);

    $this->artisan('circle:participants', [
        'circle' => 'جامعيين',
        '--names' => 'طالب جديد تماماً',
        '--create' => true,
        '--since' => '2026-08-25',
        '--apply' => true,
    ])->assertFailed();

    expect(Student::where('name', 'طالب جديد تماماً')->exists())->toBeFalse();
});
