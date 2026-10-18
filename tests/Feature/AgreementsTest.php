<?php

use App\Livewire\Public\AgreementSign;
use App\Livewire\Supervisor\AgreementFollowUp;
use App\Livewire\Supervisor\Agreements as SupervisorAgreements;
use App\Livewire\Teacher\Agreements as TeacherAgreements;
use App\Models\Agreement;
use App\Models\AgreementSignature;
use App\Models\Circle;
use App\Models\Guardian;
use App\Models\Stage;
use App\Models\Student;
use App\Models\Supervisor;
use App\Models\Teacher;
use App\Support\Agreements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->stage = Stage::factory()->create(['name' => 'السنابل']);
    $this->supervisor = Supervisor::factory()->create();
    $this->supervisor->stages()->attach($this->stage->id);

    $this->circle = Circle::factory()->create(['stage_id' => $this->stage->id, 'name' => 'حلقة النور']);
    $this->teacher = Teacher::factory()->create();
    $this->teacher->circles()->attach($this->circle->id);

    $this->guardian = Guardian::factory()->create(['name' => 'أبو أحمد', 'phone' => '0501234567']);
    $this->student = Student::factory()->create([
        'circle_id' => $this->circle->id, 'status' => 'active', 'name' => 'أحمد الطالب',
        'phone' => '0559876543', 'guardian_id' => $this->guardian->id,
    ]);

    $this->otherStage = Stage::factory()->create(['name' => 'الغراس']);
    $this->stranger = Student::factory()->create([
        'circle_id' => Circle::factory()->create(['stage_id' => $this->otherStage->id])->id, 'status' => 'active',
    ]);
});

function publishAgreement(object $test, array $attributes = []): Agreement
{
    $test->actingAs($test->supervisor, 'supervisor');

    Livewire::test(SupervisorAgreements::class)
        ->call('create')
        ->set('title', $attributes['title'] ?? 'تعهد الالتزام')
        ->set('type', $attributes['type'] ?? 'pledge')
        ->set('audience', $attributes['audience'] ?? 'both')
        ->set('stageIds', [$test->stage->id])
        ->set('clauses', $attributes['clauses'] ?? ['الحضور قبل بدء الحلقة.', 'إحضار المصحف.'])
        ->call('publish')
        ->assertHasNoErrors();

    return Agreement::latest('id')->first();
}

it('lets the supervisor write an agreement for their stages and publish it', function () {
    $agreement = publishAgreement($this);

    expect($agreement->status)->toBe('published')
        ->and($agreement->stages->modelKeys())->toBe([$this->stage->id])
        ->and($agreement->currentVersion)->number->toBe(1)
        ->and($agreement->currentVersion->clauses)->toBe(['الحضور قبل بدء الحلقة.', 'إحضار المصحف.']);

    // Another supervisor's stage is not theirs to pick.
    Livewire::test(SupervisorAgreements::class)
        ->call('create')
        ->set('title', 'إقرار')
        ->set('stageIds', [$this->otherStage->id])
        ->set('clauses', ['بند'])
        ->call('publish')
        ->assertHasErrors('stageIds.0');
});

it('asks the students of its stages, and their guardians when it asks both', function () {
    $agreement = publishAgreement($this);
    publishAgreement($this, ['title' => 'توجيهات للطالب', 'audience' => 'student', 'type' => 'guidelines']);

    expect(Agreements::pending($this->student, 'student'))->toHaveCount(2)
        ->and(Agreements::pending($this->student, 'guardian')->pluck('id')->all())->toBe([$agreement->id])
        ->and(Agreements::pending($this->stranger, 'student'))->toBeEmpty();
});

it('records the student\'s word through their own link, without signing anyone in', function () {
    $agreement = publishAgreement($this);

    $this->get(Agreements::link($this->student, 'student'))
        ->assertOk()
        ->assertSee('تعهد الالتزام')
        ->assertSee('إحضار المصحف.');
    $this->assertGuest('student');

    // The link is the student's alone: tampered with, it opens nothing.
    $this->get(route('agreements.sign', ['student' => $this->student->id, 'party' => 'student']))->assertForbidden();

    $page = Livewire::test(AgreementSign::class, ['student' => $this->student->id, 'party' => 'student'])
        ->call('sign', $agreement->id)
        ->assertHasErrors("ticked.{$agreement->id}");

    $page->set("ticked.{$agreement->id}", true)->call('sign', $agreement->id)->assertHasNoErrors();

    expect(AgreementSignature::sole())
        ->party->toBe('student')
        ->signer_name->toBe('أحمد الطالب')
        ->channel->toBe('link')
        ->type->toBe('pledge')
        ->and(Agreements::owes($agreement, $this->student, 'student'))->toBeFalse()
        ->and(Agreements::owes($agreement, $this->student, 'guardian'))->toBeTrue();
});

it('takes the guardian\'s name through the link, and their account\'s when signed in', function () {
    $agreement = publishAgreement($this);

    Livewire::test(AgreementSign::class, ['student' => $this->student->id, 'party' => 'guardian'])
        ->set("ticked.{$agreement->id}", true)
        ->call('sign', $agreement->id)
        ->assertHasErrors('signerName')
        ->set('signerName', 'سعيد والد أحمد')
        ->call('sign', $agreement->id)
        ->assertHasNoErrors();

    expect(AgreementSignature::sole())->signer_name->toBe('سعيد والد أحمد')->channel->toBe('link')->signer_id->toBeNull();

    $second = publishAgreement($this, ['title' => 'سياسات الحلقة', 'type' => 'policies']);
    $this->actingAs($this->guardian, 'guardian');

    Livewire::test(AgreementSign::class, ['student' => $this->student->id, 'party' => 'guardian'])
        ->assertSet('signerName', 'أبو أحمد')
        ->set("ticked.{$second->id}", true)
        ->call('sign', $second->id);

    expect(AgreementSignature::where('agreement_id', $second->id)->sole())
        ->channel->toBe('account')
        ->signer_id->toBe($this->guardian->id);
});

it('keeps the word over a wording fix and asks again after a substantive edit', function () {
    $agreement = publishAgreement($this);
    Agreements::sign($agreement, $this->student, 'student', 'أحمد الطالب', null, 'link', null, null);

    $this->actingAs($this->supervisor, 'supervisor');
    Livewire::test(SupervisorAgreements::class)
        ->call('edit', $agreement->id)
        ->set('clauses.0', 'الحضور قبل بدء الحلقة بخمس دقائق.')
        ->set('change', 'wording')
        ->call('publish');

    expect($agreement->fresh()->currentVersion->number)->toBe(2)
        ->and(Agreements::owes($agreement->fresh(), $this->student, 'student'))->toBeFalse();

    Livewire::test(SupervisorAgreements::class)
        ->call('edit', $agreement->id)
        ->set('clauses', ['بند جديد كلياً.'])
        ->set('change', 'substantive')
        ->call('publish');

    expect(Agreements::owes($agreement->fresh(), $this->student, 'student'))->toBeTrue();
});

it('lets the teacher call the agreement by another kind for their circle, the word keeping the kind it was read under', function () {
    $agreement = publishAgreement($this);
    $this->actingAs($this->teacher, 'teacher');

    Livewire::test(TeacherAgreements::class)
        ->assertSee('أحمد الطالب')
        ->assertDontSee($this->stranger->name)
        ->call('setType', $agreement->id, $this->circle->id, 'principles');

    expect(Agreements::typeFor($agreement->fresh(), $this->circle->id))->toBe('principles');

    $this->get(Agreements::link($this->student, 'student'))->assertSee('مبادئ');
    Agreements::sign($agreement->fresh(), $this->student, 'student', 'أحمد الطالب', null, 'link', null, null);
    expect(AgreementSignature::sole()->type)->toBe('principles');

    // The supervisor's own kind again takes the circle's away.
    Livewire::test(TeacherAgreements::class)->call('setType', $agreement->id, $this->circle->id, 'pledge');
    expect(Agreements::typeFor($agreement->fresh(), $this->circle->id))->toBe('pledge');

    // A circle that is not the teacher's is not theirs to rename.
    Livewire::test(TeacherAgreements::class)
        ->call('setType', $agreement->id, $this->stranger->circle_id, 'policies')
        ->assertNotFound();
});

it('follows up who has given their word, with each one\'s link to send', function () {
    $agreement = publishAgreement($this);
    Agreements::sign($agreement, $this->student, 'student', 'أحمد الطالب', null, 'link', null, null);

    Livewire::test(AgreementFollowUp::class, ['agreementId' => $agreement->id])
        ->assertSee('أحمد الطالب')
        ->assertSee('عبر الرابط')
        // The guardian still owes theirs: a WhatsApp message to their phone, with their own link.
        ->assertSeeHtml('https://wa.me/966501234567')
        ->assertDontSee($this->stranger->name)
        ->set('show', 'signed')
        ->assertSee('أحمد الطالب');

    $this->actingAs(Supervisor::factory()->create(), 'supervisor');
    Livewire::test(AgreementFollowUp::class, ['agreementId' => $agreement->id])->assertNotFound();
});

it('reminds the student and the guardian on their dashboards', function () {
    publishAgreement($this);

    $this->actingAs($this->guardian, 'guardian')
        ->get(route('guardian.dashboard'))
        ->assertSee('بانتظار إقرارك إقرار للطالب أحمد الطالب');
});

it('archives a published agreement rather than delete it', function () {
    $agreement = publishAgreement($this);

    Livewire::test(SupervisorAgreements::class)->call('delete', $agreement->id);
    expect($agreement->fresh())->not->toBeNull();

    Livewire::test(SupervisorAgreements::class)->call('archive', $agreement->id);
    expect($agreement->fresh()->status)->toBe('archived')
        ->and(Agreements::pending($this->student, 'student'))->toBeEmpty();
});

it('opens from the supervisor\'s and the teacher\'s menus', function () {
    publishAgreement($this);

    $this->actingAs($this->supervisor, 'supervisor')->get(route('supervisor.agreements'))->assertOk()->assertSee('تعهد الالتزام');
    $this->actingAs($this->teacher, 'teacher')->get(route('teacher.agreements'))->assertOk()->assertSee('أحمد الطالب');
});
