<?php

use App\Models\Manager;
use App\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

it('sets a new password for the manager named on the command line', function () {
    $manager = Manager::factory()->create(['email' => 'boss@altag.test']);

    $this->artisan('manager:password', ['manager' => 'boss@altag.test', '--password' => 'Zف7!newPass'])
        ->assertSuccessful();

    expect(Hash::check('Zف7!newPass', $manager->fresh()->password))->toBeTrue();
});

it('finds the manager by phone or by name too', function () {
    $manager = Manager::factory()->create(['phone' => '0500000123', 'name' => 'تركي الموقري']);

    $this->artisan('manager:password', ['manager' => '0500000123', '--password' => 'Ph0ne!pass99'])
        ->assertSuccessful();
    expect(Hash::check('Ph0ne!pass99', $manager->fresh()->password))->toBeTrue();

    $this->artisan('manager:password', ['manager' => 'تركي الموقري', '--password' => 'Nam3!pass99'])
        ->assertSuccessful();
    expect(Hash::check('Nam3!pass99', $manager->fresh()->password))->toBeTrue();
});

it('falls back to the only manager when none is named', function () {
    $manager = Manager::factory()->create();

    $this->artisan('manager:password', ['--password' => 'Only1!manager'])
        ->assertSuccessful();

    expect(Hash::check('Only1!manager', $manager->fresh()->password))->toBeTrue();
});

it('refuses an identifier that matches no manager', function () {
    Manager::factory()->create(['email' => 'boss@altag.test']);

    $this->artisan('manager:password', ['manager' => 'nobody@altag.test', '--password' => 'What3ver!pass'])
        ->expectsOutputToContain('لا يوجد مدير بهذا المعرّف')
        ->assertFailed();
});

it('refuses an ambiguous identifier rather than guessing', function () {
    Manager::factory()->create(['name' => 'أبو الوليد', 'email' => 'one@altag.test']);
    Manager::factory()->create(['name' => 'أبو الوليد', 'email' => 'two@altag.test']);

    $this->artisan('manager:password', ['manager' => 'أبو الوليد', '--password' => 'What3ver!pass'])
        ->expectsOutputToContain('أكثر من مدير يطابق')
        ->assertFailed();
});

it('rejects a password the application would reject anywhere else', function () {
    $manager = Manager::factory()->create(['email' => 'boss@altag.test']);
    $before = $manager->password;

    $this->artisan('manager:password', ['manager' => 'boss@altag.test', '--password' => 'short'])
        ->assertFailed();

    expect($manager->fresh()->password)->toBe($before);
});

it('never touches a different role that shares the identifier', function () {
    $manager = Manager::factory()->create(['email' => 'boss@altag.test']);
    $teacher = Teacher::factory()->create(['email' => 'teacher@altag.test']);
    $teacherPassword = $teacher->password;

    $this->artisan('manager:password', ['manager' => 'teacher@altag.test', '--password' => 'Wr0ng!target99'])
        ->assertFailed();

    expect($teacher->fresh()->password)->toBe($teacherPassword);
    expect($manager->fresh()->password)->toBe($manager->password);
});
