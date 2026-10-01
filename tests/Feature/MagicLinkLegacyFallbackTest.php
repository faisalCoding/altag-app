<?php

use App\Models\Guardian;
use App\Models\Teacher;
use Illuminate\Support\Facades\DB;

/**
 * When the 6 role tables merged into `users`, a person holding several roles
 * kept only the first-migrated role's access_token — links issued for their
 * other roles started 404ing in production. These tests cover the legacy
 * fallback that follows the old table + id_migration_map to the live user.
 */
it('logs a merged teacher in through their pre-consolidation magic link', function () {
    // The unified user: was a guardian AND a teacher; the guardian token won.
    $user = Teacher::create([
        'name' => 'معلم مدموج',
        'email' => 'merged@example.com',
        'password' => bcrypt('password'),
        'access_token' => 'guardian-token-that-won',
        'is_approved' => true,
        'is_data_completed' => true,
    ]);

    // The legacy teachers row still holds the teacher token that was sent out.
    $legacyId = DB::table('teachers')->insertGetId([
        'name' => 'معلم مدموج',
        'email' => 'merged@example.com',
        'password' => bcrypt('password'),
        'access_token' => 'old-teacher-token',
        'is_approved' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('id_migration_map')->insert([
        'old_table' => 'teachers',
        'old_id' => $legacyId,
        'new_id' => $user->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->get('/teacher-magic/old-teacher-token')
        ->assertRedirect(route('teacher.dashboard'));

    expect(auth()->guard('teacher')->id())->toBe($user->id);
});

it('still resolves current tokens directly from the users table', function () {
    $guardian = Guardian::create([
        'name' => 'ولي أمر',
        'email' => 'guardian@example.com',
        'password' => bcrypt('password'),
        'access_token' => 'current-guardian-token',
        'is_approved' => true,
    ]);

    $this->get('/guardian-magic/current-guardian-token')
        ->assertRedirect(route('guardian.dashboard'));

    expect(auth()->guard('guardian')->id())->toBe($guardian->id);
});

it('returns 404 for a token that exists nowhere', function () {
    $this->get('/teacher-magic/completely-unknown-token')->assertNotFound();
});

it('returns 404 for a legacy token whose row was never migrated', function () {
    DB::table('teachers')->insert([
        'name' => 'معلم غير مرحّل',
        'email' => 'unmigrated@example.com',
        'password' => bcrypt('password'),
        'access_token' => 'unmigrated-token',
        'is_approved' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->get('/teacher-magic/unmigrated-token')->assertNotFound();
});

it('follows a teacher link onward only within this site', function () {
    $teacher = Teacher::factory()->create(['access_token' => 'onward-token', 'is_approved' => true, 'is_data_completed' => true]);
    $attendance = route('teacher.attendance', ['date' => '2026-10-01']);

    $this->get(route('teacher.magic-link', ['token' => 'onward-token', 'redirect' => $attendance]))
        ->assertRedirect($attendance);

    auth()->guard('teacher')->logout();

    $this->get(route('teacher.magic-link', ['token' => 'onward-token', 'redirect' => 'https://evil.example/login']))
        ->assertRedirect(route('teacher.dashboard'));
});
