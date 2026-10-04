<?php

use App\Models\RoleScreenPermission;
use App\Models\Screen;
use App\Models\Student;
use App\Models\Teacher;

beforeEach(function () {
    $this->teacher = Teacher::factory()->create(['email' => 'teacher@example.com']);
});

it('issues a token named after the device to an approved teacher', function () {
    $this->postJson('/api/v1/teacher/login', [
        'email' => 'teacher@example.com',
        'password' => 'password',
        'device_name' => 'Pixel 9',
    ])
        ->assertSuccessful()
        ->assertJsonStructure(['data' => ['token', 'teacher' => ['id', 'name', 'email', 'phone', 'is_approved']]])
        ->assertJsonPath('data.teacher.id', $this->teacher->id);

    expect($this->teacher->tokens()->value('name'))->toBe('Pixel 9');
});

it('stays stateless for the browser app served from the site itself', function () {
    $site = rtrim(config('app.url'), '/');

    // The browser build at /app/ calls the API from the site's own origin. A
    // session started here would hold its requests to CSRF checks it cannot pass.
    $this->withHeaders(['Origin' => $site, 'Referer' => $site.'/app/login'])
        ->postJson('/api/v1/teacher/login', [
            'email' => 'teacher@example.com',
            'password' => 'password',
            'device_name' => 'Safari',
        ])
        ->assertSuccessful()
        ->assertCookieMissing(config('session.cookie'));
});

it('refuses a wrong password', function () {
    $this->postJson('/api/v1/teacher/login', ['email' => 'teacher@example.com', 'password' => 'wrong'])
        ->assertUnauthorized()
        ->assertJsonPath('message', 'بيانات الدخول غير صحيحة.');
});

it('refuses a teacher the administration has not approved yet', function () {
    Teacher::factory()->create(['email' => 'pending@example.com', 'is_approved' => false]);

    $this->postJson('/api/v1/teacher/login', ['email' => 'pending@example.com', 'password' => 'password'])
        ->assertForbidden()
        ->assertJsonPath('message', 'لم يتم تفعيل حسابك من قبل الإدارة بعد.');
});

it('does not let a student sign in to the teacher app', function () {
    Student::factory()->create(['email' => 'student@example.com']);

    $this->postJson('/api/v1/teacher/login', ['email' => 'student@example.com', 'password' => 'password'])
        ->assertUnauthorized();
});

it('throttles repeated sign-in attempts', function () {
    foreach (range(1, 5) as $attempt) {
        $this->postJson('/api/v1/teacher/login', ['email' => 'teacher@example.com', 'password' => 'wrong'])
            ->assertUnauthorized();
    }

    $this->postJson('/api/v1/teacher/login', ['email' => 'teacher@example.com', 'password' => 'wrong'])
        ->assertTooManyRequests();
});

it('revokes only the token used to sign out', function () {
    $phone = $this->teacher->createToken('phone')->plainTextToken;
    $this->teacher->createToken('tablet');

    $this->withToken($phone)->postJson('/api/v1/teacher/logout')->assertSuccessful();

    expect($this->teacher->tokens()->pluck('name')->all())->toBe(['tablet']);
});

it('requires a token to sync', function () {
    $this->getJson('/api/v1/teacher/sync')->assertUnauthorized();
});

it('stops a teacher whose approval was withdrawn after signing in', function () {
    $token = $this->teacher->createToken('phone')->plainTextToken;
    $this->teacher->update(['is_approved' => false]);

    $this->withToken($token)->getJson('/api/v1/teacher/sync')
        ->assertForbidden()
        ->assertJsonPath('code', 'not_approved');
});

it('stops syncing once the attendance page is switched off for teachers', function () {
    $screen = Screen::where('route_name', 'teacher.attendance')->firstOrFail();
    RoleScreenPermission::where('screen_id', $screen->id)->delete();

    $this->withToken($this->teacher->createToken('phone')->plainTextToken)
        ->getJson('/api/v1/teacher/sync')
        ->assertForbidden()
        ->assertJsonPath('code', 'page_disabled');
});
