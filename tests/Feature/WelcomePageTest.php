<?php

use App\Models\Circle;
use App\Models\Student;
use App\Models\Teacher;
use App\Support\Branding;

it('opens on the sign-in form itself rather than a button to it', function () {
    $response = $this->get(route('home'))->assertOk();

    // One door, not four portals — and not a landing page that makes a student
    // click twice to reach the only thing they came for.
    $response->assertSee('البريد الإلكتروني')
        ->assertSee('تسجيل الدخول')
        ->assertSee(route('register'), false)
        ->assertDontSee('/student/login', false)
        ->assertDontSee('/teacher/login', false)
        ->assertDontSee('/supervisor/login', false);
});

it('names the academy once on the first screen', function () {
    $content = $this->get(route('home'))->assertOk()->getContent();

    // It was said three times running: small, large, and inside the greeting.
    $body = substr($content, (int) strpos($content, '<body'));
    $hero = substr($body, 0, (int) strpos($body, 'id="about"'));
    $visible = preg_replace('/\s+/', ' ', strip_tags($hero));

    expect(substr_count($visible, Branding::siteName()))->toBe(2); // الشريط العلوي، والعنوان
});

it('carries the sections a visitor scrolls for', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertSee('من نحن')
        ->assertSee('ما يقدّمه')
        ->assertSee('أسئلة شائعة')
        ->assertSee('تواصل');
});

it('counts in the digits the rest of the app counts in', function () {
    Student::factory()->count(12)->create();
    Teacher::factory()->count(3)->create();
    Circle::factory()->count(2)->create();

    $this->get(route('home'))
        ->assertOk()
        ->assertSee('id="figures"', false)
        ->assertSee('١٢')
        ->assertSee('٣');
});

it('says nothing about its numbers when it has none', function () {
    // A new academy reading "إحصاءات حية" beside three zeros learns only that
    // nobody tends the page.
    $this->get(route('home'))
        ->assertOk()
        ->assertDontSee('id="figures"', false);
});

it('asks its questions in the Arabic the rest of the site is written in', function () {
    // The four answers were in Egyptian colloquial: «إزاي أعمل حساب جديد؟».
    $this->get(route('home'))
        ->assertOk()
        ->assertSee('كيف أُنشئ حساباً جديداً؟')
        ->assertDontSee('إزاي')
        ->assertDontSee('قد إيه')
        ->assertDontSee('تقدر');
});

it('sends a signed-in visitor to their dashboard instead of a login form', function () {
    $this->actingAs(Teacher::factory()->create(), 'teacher');

    $this->get(route('home'))
        ->assertOk()
        ->assertSee('الذهاب إلى لوحتك')
        ->assertDontSee('البريد الإلكتروني');
});
