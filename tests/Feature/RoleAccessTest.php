<?php

use App\Models\Manager;
use App\Models\Student;
use App\Models\Teacher;

use function Pest\Laravel\actingAs;

it('redirects guests to login when accessing a dashboard', function () {
    $response = $this->get('/manager/dashboard');
    $response->assertRedirect(route('manager.login'));
});

it('allows manager to access manager dashboard', function () {
    $manager = Manager::factory()->create();
    actingAs($manager, 'manager')->get('/manager/dashboard')->assertStatus(200);
});

it('prevents student from accessing manager dashboard and redirects them', function () {
    $student = Student::factory()->create();
    $response = actingAs($student, 'student')->get('/manager/dashboard');
    // Because auth:manager restricts the route, and the student active session is auth:student,
    // the guest redirector will redirect to manager.login 
    $response->assertRedirect(route('manager.login'));
});

it('redirects authenticated user accessing /dashboard to their specific dashboard', function () {
    $teacher = Teacher::factory()->create();
    $response = actingAs($teacher, 'teacher')->get('/dashboard');
    $response->assertRedirect(route('teacher.dashboard'));
});
