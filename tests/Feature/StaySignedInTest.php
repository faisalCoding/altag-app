<?php

use App\Livewire\Auth\Login;
use App\Models\Teacher;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;

/*
 * A session alone lapses after two hours without a request, and teachers,
 * supervisors and students alike were sent back to sign in again and again.
 * A sign-in is remembered unless the user says otherwise, and signing out of
 * one device leaves their others signed in.
 */

it('remembers a sign-in unless the box is unticked', function () {
    $teacher = Teacher::factory()->create(['password' => bcrypt('password')]);

    Livewire::test(Login::class)
        ->assertSet('remember', true)
        ->set('email', $teacher->email)
        ->set('password', 'password')
        ->call('login');

    expect($teacher->fresh()->remember_token)->not->toBeNull();
});

it('remembers a sign-in from a WhatsApp link', function () {
    $teacher = Teacher::factory()->create(['access_token' => 'teacher-link-token', 'is_data_completed' => true]);

    $this->get('/teacher-magic/teacher-link-token')
        ->assertRedirect(route('teacher.dashboard'))
        ->assertCookie(Auth::guard('teacher')->getRecallerName());
});

it('signs out of this device alone, leaving the others remembered', function () {
    $teacher = Teacher::factory()->create(['remember_token' => 'kept-by-the-other-phone']);

    $this->actingAs($teacher, 'teacher')
        ->post(route('logout'))
        ->assertRedirect(route('home'));

    expect($teacher->fresh()->remember_token)->toBe('kept-by-the-other-phone');
    $this->assertGuest('teacher');
});
