<?php

use App\Models\Manager;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

it('creates an approved manager who can sign in', function () {
    $this->artisan('manager:create', [
        'name' => 'مدير المجمع',
        'email' => 'boss@second.test',
        '--password' => 'Str0ng!opening',
    ])->assertSuccessful();

    $manager = Manager::whereRoleState(fn ($q) => $q->where('is_approved', true))
        ->where('email', 'boss@second.test')
        ->first();

    expect($manager)->not->toBeNull();
    expect($manager->name)->toBe('مدير المجمع');
    expect(Hash::check('Str0ng!opening', $manager->password))->toBeTrue();
});

it('generates and shows a password when none is given', function () {
    $this->artisan('manager:create', ['name' => 'مدير', 'email' => 'gen@second.test'])
        ->expectsOutputToContain('تظهر مرة واحدة')
        ->assertSuccessful();

    expect(Manager::where('email', 'gen@second.test')->exists())->toBeTrue();
});

it('lowercases the address so a capitalised retype is not a second account', function () {
    $this->artisan('manager:create', [
        'name' => 'مدير',
        'email' => 'Boss@Second.Test',
        '--password' => 'Str0ng!opening',
    ])->assertSuccessful();

    expect(Manager::where('email', 'boss@second.test')->exists())->toBeTrue();
});

it('refuses an address any role already holds', function () {
    Student::factory()->create(['email' => 'taken@second.test']);

    $this->artisan('manager:create', [
        'name' => 'مدير',
        'email' => 'taken@second.test',
        '--password' => 'Str0ng!opening',
    ])->assertFailed();

    expect(Manager::where('email', 'taken@second.test')->exists())->toBeFalse();
});

it('refuses a password the application would refuse anywhere else', function () {
    $this->artisan('manager:create', [
        'name' => 'مدير',
        'email' => 'weak@second.test',
        '--password' => 'short',
    ])->assertFailed();

    expect(Manager::where('email', 'weak@second.test')->exists())->toBeFalse();
});
