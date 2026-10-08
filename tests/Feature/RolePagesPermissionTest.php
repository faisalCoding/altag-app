<?php

use App\Models\Manager;
use App\Models\RoleScreenPermission;
use App\Models\Screen;
use App\Models\Teacher;
use App\Support\RolePages;
use Illuminate\Support\Facades\DB;

function disableScreen(string $routeName): void
{
    $screen = Screen::where('route_name', $routeName)->firstOrFail();
    RoleScreenPermission::where('screen_id', $screen->id)->delete();
}

it('treats every seeded page as enabled by default', function () {
    expect(RolePages::isEnabled('teacher', 'teacher.students'))->toBeTrue();
    expect(RolePages::isEnabled('supervisor', 'supervisor.circles'))->toBeTrue();
});

it('disables a page once its RoleScreenPermission row is removed', function () {
    disableScreen('teacher.students');

    expect(RolePages::isEnabled('teacher', 'teacher.students'))->toBeFalse();
    expect(RolePages::isEnabled('teacher', 'teacher.attendance'))->toBeTrue();
});

it('also blocks nested child routes when the parent page is disabled', function () {
    disableScreen('teacher.leaderboards');

    expect(RolePages::isEnabled('teacher', 'teacher.leaderboards'))->toBeFalse();
    expect(RolePages::isEnabled('teacher', 'teacher.leaderboards.grade'))->toBeFalse();
});

it('never disables the dashboard route even without a permission row', function () {
    disableScreen('teacher.dashboard');

    expect(RolePages::isEnabled('teacher', 'teacher.dashboard'))->toBeTrue();
});

it('blocks direct URL access to a disabled page via the enforcement middleware', function () {
    $teacher = Teacher::factory()->create();
    disableScreen('teacher.discipline');

    $this->actingAs($teacher, 'teacher')
        ->get(route('teacher.discipline'))
        ->assertForbidden();

    $this->get(route('teacher.attendance'))->assertSuccessful();
});

it('now also gates manager routes, unlike before this feature existed', function () {
    $manager = Manager::factory()->create();
    disableScreen('manager.students');

    $this->actingAs($manager, 'manager')
        ->get(route('manager.students'))
        ->assertForbidden();

    $this->get(route('manager.circles'))->assertSuccessful();
});

it('queries the screen catalog and permission set at most once each per request despite the middleware plus a dozen-plus sidebar checks', function () {
    $teacher = Teacher::factory()->create();

    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = $query->sql;
    });

    $this->actingAs($teacher, 'teacher')
        ->get(route('teacher.student-plans'))
        ->assertSuccessful();

    $permissionQueries = collect($queries)
        ->filter(fn ($sql) => str_contains($sql, 'role_screen_permissions'))
        ->count();

    expect($permissionQueries)->toBeLessThanOrEqual(1);
});
