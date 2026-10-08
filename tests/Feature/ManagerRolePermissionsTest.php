<?php

use App\Models\Manager;
use App\Models\Role;
use App\Models\RoleScreenPermission;
use App\Models\Screen;
use Livewire\Livewire;

it('renders the permissions page for the manager with teacher pages visible', function () {
    $manager = Manager::factory()->create();
    $this->actingAs($manager, 'manager');

    $this->get(route('manager.role-permissions'))
        ->assertSuccessful()
        ->assertSee('صلاحيات الصفحات')
        ->assertSee('إدارة الطلاب');
});

it('lets the manager disable a currently-enabled page for a role', function () {
    $manager = Manager::factory()->create();
    $this->actingAs($manager, 'manager');

    $teacherRole = Role::where('key', 'teacher')->firstOrFail();
    $screen = Screen::where('route_name', 'teacher.students')->firstOrFail();

    expect(RoleScreenPermission::where('role_id', $teacherRole->id)->where('screen_id', $screen->id)->exists())->toBeTrue();

    Livewire::test('manager.role-permissions')
        ->call('toggle', $teacherRole->id, $screen->id);

    expect(RoleScreenPermission::where('role_id', $teacherRole->id)->where('screen_id', $screen->id)->exists())->toBeFalse();
});

it('lets the manager re-enable a previously disabled page', function () {
    $manager = Manager::factory()->create();
    $this->actingAs($manager, 'manager');

    $teacherRole = Role::where('key', 'teacher')->firstOrFail();
    $screen = Screen::where('route_name', 'teacher.students')->firstOrFail();

    RoleScreenPermission::where('role_id', $teacherRole->id)->where('screen_id', $screen->id)->delete();

    Livewire::test('manager.role-permissions')
        ->call('toggle', $teacherRole->id, $screen->id);

    expect(RoleScreenPermission::where('role_id', $teacherRole->id)->where('screen_id', $screen->id)->exists())->toBeTrue();
});

it('refuses to disable a protected dashboard route', function () {
    $manager = Manager::factory()->create();
    $this->actingAs($manager, 'manager');

    $teacherRole = Role::where('key', 'teacher')->firstOrFail();
    $dashboardScreen = Screen::where('route_name', 'teacher.dashboard')->firstOrFail();

    Livewire::test('manager.role-permissions')
        ->call('toggle', $teacherRole->id, $dashboardScreen->id);

    expect(RoleScreenPermission::where('role_id', $teacherRole->id)->where('screen_id', $dashboardScreen->id)->exists())->toBeTrue();
});

it('switches the active role tab and shows that role pages', function () {
    $manager = Manager::factory()->create();
    $this->actingAs($manager, 'manager');

    $studentRole = Role::where('key', 'student')->firstOrFail();

    Livewire::test('manager.role-permissions')
        ->call('setActiveRole', $studentRole->id)
        ->assertSet('activeRoleId', $studentRole->id)
        ->assertSee('سجل الانضباط')
        ->assertSee('التقارير');
});

it('refuses to grant a system role a screen owned by another role', function () {
    $manager = Manager::factory()->create();
    $this->actingAs($manager, 'manager');

    $managerRole = Role::where('key', 'manager')->firstOrFail();
    $teacherScreen = Screen::where('route_name', 'teacher.attendance')->firstOrFail();

    Livewire::test('manager.role-permissions')
        ->call('toggle', $managerRole->id, $teacherScreen->id);

    expect(RoleScreenPermission::where('role_id', $managerRole->id)->where('screen_id', $teacherScreen->id)->exists())
        ->toBeFalse();
});

it('shows a system role only its own screens, not other roles pages', function () {
    $manager = Manager::factory()->create();
    $this->actingAs($manager, 'manager');

    $managerRole = Role::where('key', 'manager')->firstOrFail();

    Livewire::test('manager.role-permissions')
        ->call('setActiveRole', $managerRole->id)
        ->assertSee('تقارير الحضور والغياب') // a manager screen
        ->assertDontSee('سجل الحضور');       // teacher.attendance — must not leak in
});

it('offers no way to create a role: the five built-in ones are all there are', function () {
    $this->actingAs(Manager::factory()->create(), 'manager');

    Livewire::test('manager.role-permissions')
        ->assertDontSee('إضافة دور')
        ->assertDontSee('إنشاء دور جديد');

    expect(method_exists(Role::class, 'staff'))->toBeFalse();
});
