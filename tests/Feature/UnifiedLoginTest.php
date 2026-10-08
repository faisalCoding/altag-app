<?php

use App\Livewire\Auth\Login;
use App\Models\Guardian;
use App\Models\Manager;
use App\Models\Student;
use App\Models\Supervisor;
use App\Models\Teacher;
use Livewire\Livewire;

it('renders the unified login page at /login', function () {
    $this->get(route('login'))
        ->assertSuccessful()
        ->assertSee('تسجيل الدخول');
});

dataset('guards', [
    'manager' => [Manager::class, 'manager'],
    'supervisor' => [Supervisor::class, 'supervisor'],
    'teacher' => [Teacher::class, 'teacher'],
    'student' => [Student::class, 'student'],
    'guardian' => [Guardian::class, 'guardian'],
]);

it('logs a user in via the unified login and redirects straight to their own dashboard', function (string $model, string $guard) {
    $user = $model::factory()->create(['is_approved' => true]);

    Livewire::test(Login::class)
        ->set('email', $user->email)
        ->set('password', 'password')
        ->call('login')
        ->assertRedirect(route("{$guard}.dashboard"));

    $this->assertAuthenticatedAs($user, $guard);
})->with('guards');

it('ignores a stale intended url left over from an earlier guest bounce', function () {
    $teacher = Teacher::factory()->create(['is_approved' => true]);

    // Simulate the exact scenario reported as a bug: the visitor was earlier
    // bounced from a protected teacher page to /teacher/login (which stores
    // that url as "intended"), then navigated to the unified /login instead.
    session(['url.intended' => url('/teacher/login')]);

    Livewire::test(Login::class)
        ->set('email', $teacher->email)
        ->set('password', 'password')
        ->call('login')
        ->assertRedirect(route('teacher.dashboard'));

    expect(session()->has('url.intended'))->toBeFalse();
});

it('returns the visitor to the deep-linked page they were bounced from', function () {
    $teacher = Teacher::factory()->create(['is_approved' => true]);

    session(['url.intended' => url('/teacher/attendance')]);

    Livewire::test(Login::class)
        ->set('email', $teacher->email)
        ->set('password', 'password')
        ->call('login')
        ->assertRedirect(url('/teacher/attendance'));

    expect(session()->has('url.intended'))->toBeFalse();
});

it('ignores an intended url belonging to a different role than the one that logged in', function () {
    $teacher = Teacher::factory()->create(['is_approved' => true]);

    session(['url.intended' => url('/manager/settings')]);

    Livewire::test(Login::class)
        ->set('email', $teacher->email)
        ->set('password', 'password')
        ->call('login')
        ->assertRedirect(route('teacher.dashboard'));
});

it('rejects invalid credentials with a generic error', function () {
    Livewire::test(Login::class)
        ->set('email', 'nobody@example.com')
        ->set('password', 'wrong-password')
        ->call('login')
        ->assertHasErrors('email');

    expect(auth()->check())->toBeFalse();
});

it('makes a guesser wait after five wrong passwords, even when the sixth is right', function () {
    $teacher = Teacher::factory()->create(['is_approved' => true]);

    foreach (range(1, 5) as $attempt) {
        Livewire::test(Login::class)
            ->set('email', $teacher->email)
            ->set('password', 'wrong-password')
            ->call('login')
            ->assertHasErrors('email');
    }

    Livewire::test(Login::class)
        ->set('email', $teacher->email)
        ->set('password', 'password')
        ->call('login')
        ->assertHasErrors('email')
        ->assertSee('محاولات دخول كثيرة');

    $this->assertGuest('teacher');
});
