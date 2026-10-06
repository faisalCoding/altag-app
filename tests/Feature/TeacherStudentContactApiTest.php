<?php

use App\Models\Circle;
use App\Models\Guardian;
use App\Models\Student;
use App\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->circle = Circle::factory()->create();
    $this->teacher = Teacher::factory()->create();
    $this->teacher->circles()->attach($this->circle->id);

    $this->student = Student::factory()->create(['circle_id' => $this->circle->id, 'phone' => null, 'guardian_id' => null]);

    Sanctum::actingAs($this->teacher);
});

function contactUrl(string $path = ''): string
{
    return '/api/v1/teacher/students/'.test()->student->id.$path;
}

function makeGuardian(string $name, ?string $phone): Guardian
{
    return Guardian::create([
        'name' => $name,
        'phone' => $phone,
        'email' => Str::random(8).'@example.com',
        'password' => bcrypt('password'),
        'is_approved' => true,
    ]);
}

it('sets the student\'s number as the academy stores it, however it was typed', function () {
    $this->postJson(contactUrl('/phone'), ['phone' => '٠٥٠ ١٢٣ ٤٥٦٧'])
        ->assertSuccessful()
        ->assertJsonPath('data.student.id', $this->student->id)
        ->assertJsonPath('data.student.phone', '966501234567');

    expect($this->student->fresh()->phone)->toBe('966501234567');

    $this->postJson(contactUrl('/phone'), ['phone' => null])->assertSuccessful();
    expect($this->student->fresh()->phone)->toBeNull();
});

it('refuses a number that is not a Saudi mobile', function () {
    $this->postJson(contactUrl('/phone'), ['phone' => '12345'])->assertUnprocessable()->assertJsonValidationErrors('phone');
});

it('refuses a student outside the teacher\'s circles', function () {
    $this->student = Student::factory()->create(['circle_id' => Circle::factory()->create()->id]);

    $this->postJson(contactUrl('/phone'), ['phone' => '0501234567'])
        ->assertForbidden()
        ->assertJsonPath('code', 'student_unavailable');
});

it('finds a guardian by number whichever way it was stored, and says nothing more of them', function () {
    $stored = makeGuardian('أبو أحمد', '0551234567');
    makeGuardian('ولي أمر آخر', '966559999999');

    $this->postJson(contactUrl('/guardian/lookup'), ['phone' => '966551234567'])
        ->assertSuccessful()
        ->assertExactJson(['data' => ['guardians' => [['id' => $stored->id, 'name' => 'أبو أحمد']]]]);

    $this->postJson(contactUrl('/guardian/lookup'), ['phone' => '0500000000'])
        ->assertExactJson(['data' => ['guardians' => []]]);
});

it('links the guardian found by the number', function () {
    $guardian = makeGuardian('أبو أحمد', '966551234567');

    $this->postJson(contactUrl('/guardian'), ['phone' => '0551234567', 'guardian_id' => $guardian->id])
        ->assertSuccessful()
        ->assertJsonPath('data.student.guardian_name', 'أبو أحمد')
        ->assertJsonPath('data.student.guardian_phone', '966551234567');

    expect($this->student->fresh()->guardian_id)->toBe($guardian->id);
});

it('refuses to link a guardian the number does not belong to', function () {
    $guardian = makeGuardian('أبو خالد', '966551234567');

    $this->postJson(contactUrl('/guardian'), ['phone' => '0559999999', 'guardian_id' => $guardian->id])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'guardian_unavailable');

    expect($this->student->fresh()->guardian_id)->toBeNull();
});

it('gives a guardian an account from a name and a number the academy does not hold', function () {
    $this->postJson(contactUrl('/guardian'), ['phone' => '0551234567', 'name' => ' أبو سعد '])
        ->assertSuccessful()
        ->assertJsonPath('data.student.guardian_name', 'أبو سعد');

    $guardian = Guardian::sole();

    expect($guardian->phone)->toBe('966551234567')
        ->and($guardian->is_approved)->toBeTrue()
        ->and($guardian->access_token)->not->toBeNull()
        ->and($guardian->hasRole('guardian'))->toBeTrue()
        ->and($this->student->fresh()->guardian_id)->toBe($guardian->id);
});

it('asks for the guardian\'s name before making an account', function () {
    $this->postJson(contactUrl('/guardian'), ['phone' => '0551234567'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('name');

    expect(Guardian::count())->toBe(0);
});

it('links rather than duplicates a guardian the number already belongs to', function () {
    makeGuardian('أبو أحمد', '966551234567');

    $this->postJson(contactUrl('/guardian'), ['phone' => '0551234567', 'name' => 'أبو أحمد'])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'guardian_exists');

    expect(Guardian::count())->toBe(1);
});

it('corrects the linked guardian\'s number', function () {
    $guardian = makeGuardian('أبو أحمد', '966551234567');
    $this->student->update(['guardian_id' => $guardian->id]);

    $this->postJson(contactUrl('/guardian/phone'), ['phone' => '0567654321'])
        ->assertSuccessful()
        ->assertJsonPath('data.student.guardian_phone', '966567654321');

    expect($guardian->fresh()->phone)->toBe('966567654321');
});

it('has no guardian\'s number to correct while none is linked', function () {
    $this->postJson(contactUrl('/guardian/phone'), ['phone' => '0567654321'])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'no_guardian');
});
