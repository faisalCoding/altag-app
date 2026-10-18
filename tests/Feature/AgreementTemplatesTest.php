<?php

use App\Livewire\Manager\AgreementFollowUp as ManagerAgreementFollowUp;
use App\Livewire\Manager\AgreementReports;
use App\Livewire\Manager\Agreements as ManagerAgreements;
use App\Livewire\Public\AgreementRequestSign;
use App\Livewire\Supervisor\AgreementFollowUp;
use App\Livewire\Supervisor\Agreements as SupervisorAgreements;
use App\Livewire\Teacher\Agreements as TeacherAgreements;
use App\Models\Agreement;
use App\Models\AgreementRequest;
use App\Models\Circle;
use App\Models\Guardian;
use App\Models\Manager;
use App\Models\Stage;
use App\Models\Student;
use App\Models\Supervisor;
use App\Models\Teacher;
use App\Support\Agreements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->stage = Stage::factory()->create(['name' => 'السنابل']);
    $this->supervisor = Supervisor::factory()->create();
    $this->supervisor->stages()->attach($this->stage->id);
    $this->manager = Manager::factory()->create();

    $this->circle = Circle::factory()->create(['stage_id' => $this->stage->id, 'name' => 'حلقة النور']);
    $this->teacher = Teacher::factory()->create(['name' => 'المعلم سالم']);
    $this->teacher->circles()->attach($this->circle->id);

    $this->guardian = Guardian::factory()->create(['name' => 'أبو أحمد', 'phone' => '0501234567']);
    $this->student = Student::factory()->create([
        'circle_id' => $this->circle->id, 'status' => 'active', 'name' => 'أحمد الطالب',
        'phone' => '0559876543', 'guardian_id' => $this->guardian->id,
    ]);
    $this->classmate = Student::factory()->create(['circle_id' => $this->circle->id, 'status' => 'active', 'name' => 'بدر الزميل']);

    $this->otherStage = Stage::factory()->create(['name' => 'الغراس']);
    $this->stranger = Student::factory()->create([
        'circle_id' => Circle::factory()->create(['stage_id' => $this->otherStage->id])->id, 'status' => 'active', 'name' => 'طالب غريب',
    ]);
});

/**
 * Write and publish an agreement from a role's page.
 *
 * @param  array<string, mixed>  $attributes
 */
function writeAgreement(object $test, string $role, array $attributes = []): Agreement
{
    $test->actingAs($role === 'manager' ? $test->manager : $test->supervisor, $role);

    Livewire::test($role === 'manager' ? ManagerAgreements::class : SupervisorAgreements::class)
        ->call('create')
        ->set('kind', $attributes['kind'] ?? 'template')
        ->set('title', $attributes['title'] ?? 'تعهد بعد غياب')
        ->set('type', $attributes['type'] ?? 'pledge')
        ->set('audience', $attributes['audience'] ?? 'both')
        ->set('allStages', $attributes['allStages'] ?? false)
        ->set('stageIds', $attributes['stageIds'] ?? ($attributes['allStages'] ?? false ? [] : [$test->stage->id]))
        ->set('topics', [['title' => 'الحضور', 'clauses' => ['ألا أتغيب بلا عذر.']]])
        ->call('publish')
        ->assertHasNoErrors();

    return Agreement::latest('id')->first();
}

it('lets the manager impose a general agreement on every circle, which the supervisor follows but cannot edit', function () {
    $agreement = writeAgreement($this, 'manager', ['kind' => 'general', 'allStages' => true, 'title' => 'ميثاق المجمع']);

    expect($agreement)->all_stages->toBeTrue()->author_role->toBe('manager')->kind->toBe('general')
        ->and($agreement->stages)->toBeEmpty()
        ->and(Agreements::pending($this->student, 'student')->pluck('id')->all())->toBe([$agreement->id])
        ->and(Agreements::pending($this->stranger, 'guardian')->pluck('id')->all())->toBe([$agreement->id]);

    $this->actingAs($this->supervisor, 'supervisor');
    Livewire::test(SupervisorAgreements::class)
        ->assertSee('ميثاق المجمع')
        ->assertSee('من إعداد الإدارة')
        ->call('edit', $agreement->id)
        ->assertNotFound();

    // The supervisor follows it over their own stage only.
    Livewire::test(AgreementFollowUp::class, ['agreementId' => $agreement->id])
        ->assertSee('أحمد الطالب')
        ->assertDontSee('طالب غريب');

    $this->actingAs($this->manager, 'manager');
    Livewire::test(ManagerAgreementFollowUp::class, ['agreementId' => $agreement->id])
        ->assertSee('أحمد الطالب')
        ->assertSee('طالب غريب');
});

it('asks nobody of a template until a teacher sends it, and offers the teacher those of their stages', function () {
    $own = writeAgreement($this, 'supervisor', ['title' => 'تعهد بعد غياب']);
    $everyone = writeAgreement($this, 'manager', ['title' => 'إقرار السلوك', 'allStages' => true]);
    writeAgreement($this, 'manager', ['title' => 'نموذج الغراس', 'stageIds' => [$this->otherStage->id]]);

    expect(Agreements::pending($this->student, 'student'))->toBeEmpty()
        ->and(Agreements::templatesFor([$this->stage->id])->modelKeys())->toEqualCanonicalizing([$own->id, $everyone->id]);

    $this->actingAs($this->teacher, 'teacher');
    Livewire::test(TeacherAgreements::class)
        ->set('tab', 'templates')
        ->assertSee('تعهد بعد غياب')
        ->assertSee('إقرار السلوك')
        ->assertDontSee('نموذج الغراس');
});

it('sends a template to the students the teacher picks, as often as needed, each send its own request', function () {
    $template = writeAgreement($this, 'supervisor');
    $this->actingAs($this->teacher, 'teacher');

    $page = Livewire::test(TeacherAgreements::class)
        ->set('tab', 'templates')
        ->set('templateId', $template->id)
        ->assertSet('sendTo', 'both')
        ->assertSet('sendType', 'pledge')
        ->assertSee('بدر الزميل')
        ->assertDontSee('طالب غريب')
        ->set('recipients', [$this->student->id, $this->classmate->id])
        ->set('sendTo', 'guardian')
        ->set('sendType', 'guidelines')
        ->set('note', 'بعد غياب ثلاثة أيام')
        ->call('send')
        ->assertHasNoErrors()
        ->assertSet('recipients', []);

    expect(AgreementRequest::count())->toBe(2)
        ->and(AgreementRequest::where('student_id', $this->student->id)->sole())
        ->party->toBe('guardian')
        ->type->toBe('guidelines')
        ->note->toBe('بعد غياب ثلاثة أيام')
        ->sent_by->toBe($this->teacher->id)
        ->agreement_version_id->toBe($template->currentVersion->id);

    // Again to the same student, to them and their guardian: two more requests, the first kept.
    $page->set('recipients', [$this->student->id])->set('sendTo', 'both')->set('note', '')->call('send');
    expect(AgreementRequest::where('student_id', $this->student->id)->count())->toBe(3);

    // Only the teacher's own students.
    $page->set('recipients', [$this->stranger->id])->call('send')->assertHasErrors('recipients.0');
    $page->set('recipients', [])->call('send')->assertHasErrors('recipients');
    expect(AgreementRequest::count())->toBe(4);
});

it('takes the answer to a send through its own link, once, keeping every send with its date', function () {
    $template = writeAgreement($this, 'supervisor');
    [$first] = Agreements::send($template, [$this->student], 'student', 'pledge', 'تأخر متكرر', $this->teacher);
    [$second] = Agreements::send($template, [$this->student], 'student', 'pledge', null, $this->teacher);

    $this->get(Agreements::requestLink($first))->assertOk()->assertSee('تأخر متكرر')->assertSee('المعلم سالم');
    $this->get(route('agreements.request', ['request' => $first->id]))->assertForbidden();
    $this->assertGuest('student');

    $page = Livewire::test(AgreementRequestSign::class, ['request' => $first->id]);
    expect($page->viewData('agreements')[0])->toMatchArray(['id' => $first->id, 'version' => 1, 'type' => 'تعهد', 'signature' => null]);

    $page->call('sign', $first->id, 1)->assertReturned(fn (array $result) => $result['ok'] && $result['signedBy'] === 'أحمد الطالب');
    $signedAt = $first->fresh()->signed_at;
    $this->travel(1)->day();
    $page->call('sign', $first->id, 1);

    expect($first->fresh())
        ->signer_name->toBe('أحمد الطالب')
        ->channel->toBe('link')
        ->content_hash->not->toBeNull()
        ->signed_at->toEqual($signedAt)
        ->and($second->fresh()->signed_at)->toBeNull()
        ->and(Agreements::openRequests($this->student, 'student')->modelKeys())->toBe([$second->id]);
});

it('asks the guardian their name through the link, and takes their account\'s when signed in', function () {
    $template = writeAgreement($this, 'supervisor');
    [$request] = Agreements::send($template, [$this->student], 'guardian', 'policies', null, $this->teacher);

    Livewire::test(AgreementRequestSign::class, ['request' => $request->id])
        ->call('sign', $request->id, 1, '')
        ->assertReturned(fn (array $result) => ! $result['ok'] && $result['name']);

    $this->actingAs($this->guardian, 'guardian');
    Livewire::test(AgreementRequestSign::class, ['request' => $request->id])
        ->assertViewHas('needsName', false)
        ->call('sign', $request->id, 1);

    expect($request->fresh())->signer_name->toBe('أبو أحمد')->channel->toBe('account')->signer_id->toBe($this->guardian->id);
});

it('lets the teacher call off a send still waiting, whose link then asks nothing', function () {
    $template = writeAgreement($this, 'supervisor');
    [$request] = Agreements::send($template, [$this->student], 'student', 'pledge', null, $this->teacher);
    [$foreign] = Agreements::send($template, [$this->stranger], 'student', 'pledge', null, null);

    $this->actingAs($this->teacher, 'teacher');
    Livewire::test(TeacherAgreements::class)
        ->set('tab', 'templates')
        ->assertSeeHtml('data-request-open')
        ->call('cancelRequest', $request->id)
        ->call('cancelRequest', $foreign->id)
        ->assertNotFound();

    expect($request->fresh()->cancelled_at)->not->toBeNull()
        ->and($foreign->fresh()->cancelled_at)->toBeNull();

    Livewire::test(AgreementRequestSign::class, ['request' => $request->id])
        ->assertSee('أُلغي هذا الطلب')
        ->call('sign', $request->id, 1)
        ->assertReturned(fn (array $result) => ! $result['ok']);

    expect($request->fresh()->signed_at)->toBeNull();
});

it('follows up a template send by send, with each one\'s link while it waits', function () {
    $template = writeAgreement($this, 'supervisor');
    [$answered] = Agreements::send($template, [$this->student], 'student', 'pledge', 'بعد الإنذار', $this->teacher);
    Agreements::answer($answered, 'أحمد الطالب', null, 'link', null, null);
    Agreements::send($template, [$this->student], 'guardian', 'pledge', null, $this->teacher);

    Livewire::test(AgreementFollowUp::class, ['agreementId' => $template->id])
        ->assertSee('بعد الإنذار')
        ->assertSee('المعلم سالم')
        ->assertSeeHtml('data-request-signed')
        ->assertSeeHtml('https://wa.me/966501234567')
        ->assertViewHas('totals', ['sent' => 2, 'signed' => 1, 'students' => 1])
        ->set('show', 'owed')
        ->assertDontSee('بعد الإنذار');
});

it('reports on the agreements in general and student by student', function () {
    $general = writeAgreement($this, 'manager', ['kind' => 'general', 'allStages' => true, 'title' => 'ميثاق المجمع', 'audience' => 'student']);
    Agreements::sign($general, $this->student, 'student', 'أحمد الطالب', null, 'link', null, null);
    $template = writeAgreement($this, 'manager', ['title' => 'إقرار السلوك', 'allStages' => true]);
    [$request] = Agreements::send($template, [$this->student, $this->classmate], 'student', 'pledge', 'تنبيه', $this->teacher);
    Agreements::answer($request, 'أحمد الطالب', null, 'link', null, null);

    $this->actingAs($this->manager, 'manager');
    $report = Livewire::test(AgreementReports::class);

    $overview = $report->viewData('overview')->firstWhere('agreement.id', $general->id);
    expect($overview['parties']['student'])->toBe(['signed' => 1, 'total' => 3])
        ->and($report->viewData('byTeacher')->first())->toMatchArray(['name' => 'المعلم سالم', 'sent' => 2, 'signed' => 1])
        ->and($report->viewData('byTemplate')->first())->toMatchArray(['title' => 'إقرار السلوك', 'sent' => 2, 'signed' => 1]);

    // One stage: its students only.
    $report->set('stage', (string) $this->stage->id);
    expect($report->viewData('overview')->first()['parties']['student'])->toBe(['signed' => 1, 'total' => 2]);

    $report->set('mode', 'individual')
        ->set('search', 'أحمد')
        ->assertSee('أحمد الطالب')
        ->call('pickStudent', $this->student->id)
        ->assertSee('ميثاق المجمع')
        ->assertSee('إقرار السلوك')
        ->assertSee('تنبيه')
        ->assertSeeHtml('data-request-signed');
});

it('reminds the guardian of a send waiting for them on their dashboard', function () {
    $template = writeAgreement($this, 'supervisor', ['title' => 'تعهد بعد غياب']);
    Agreements::send($template, [$this->student], 'guardian', 'pledge', 'غياب يومين', $this->teacher);

    $this->actingAs($this->guardian, 'guardian')
        ->get(route('guardian.dashboard'))
        ->assertSee('بانتظار إقرارك «تعهد بعد غياب» للطالب أحمد الطالب')
        ->assertSee('غياب يومين');
});

it('opens the manager\'s agreements page and its follow-up', function () {
    $agreement = writeAgreement($this, 'manager', ['kind' => 'general', 'allStages' => true, 'title' => 'ميثاق المجمع']);

    $this->actingAs($this->manager, 'manager')
        ->get(route('manager.agreements'))
        ->assertOk()
        ->assertSee('ميثاق المجمع');

    $this->get(route('manager.agreements.follow-up', $agreement))->assertOk()->assertSee('أحمد الطالب');
});

it('sends a template from the teacher app, with each send\'s link, and calls one off', function () {
    $template = writeAgreement($this, 'supervisor', ['title' => 'تعهد بعد غياب']);
    writeAgreement($this, 'manager', ['title' => 'نموذج الغراس', 'stageIds' => [$this->otherStage->id]]);
    Sanctum::actingAs($this->teacher);

    // The templates the teacher may send, and every send standing, travel with the sync.
    $sync = $this->getJson('/api/v1/teacher/sync')->assertSuccessful()->json('data');
    expect(collect($sync['agreement_templates'])->pluck('title')->all())->toBe(['تعهد بعد غياب'])
        ->and($sync['agreement_templates'][0])->toMatchArray(['type' => 'pledge', 'audience' => 'both', 'stage_ids' => [$this->stage->id]])
        ->and($sync['agreement_requests'])->toBe([]);

    $sent = $this->postJson('/api/v1/teacher/students/'.$this->student->id.'/agreement-requests', [
        'agreement_id' => $template->id, 'to' => 'both', 'type' => 'guidelines', 'note' => 'بعد غياب يومين',
    ])->assertSuccessful()->json('data.requests');

    expect($sent)->toHaveCount(2)
        ->and($sent[0])->toMatchArray(['student_id' => $this->student->id, 'title' => 'تعهد بعد غياب', 'party' => 'student', 'type' => 'guidelines', 'note' => 'بعد غياب يومين', 'sent_by' => 'المعلم سالم', 'signed_at' => null])
        ->and($sent[1]['party'])->toBe('guardian')
        ->and($sent[0]['link'])->toContain('/agreements/request/'.$sent[0]['id'].'?signature=');

    // Not to a student of another circle, nor a template their stage does not hold.
    $this->postJson('/api/v1/teacher/students/'.$this->stranger->id.'/agreement-requests', ['agreement_id' => $template->id, 'to' => 'student', 'type' => 'pledge'])
        ->assertForbidden();
    $foreignTemplate = Agreement::where('title', 'نموذج الغراس')->first();
    $this->postJson('/api/v1/teacher/students/'.$this->student->id.'/agreement-requests', ['agreement_id' => $foreignTemplate->id, 'to' => 'student', 'type' => 'pledge'])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'template_unavailable');

    $this->postJson('/api/v1/teacher/agreement-requests/'.$sent[1]['id'].'/cancel')->assertSuccessful();
    Agreements::answer(AgreementRequest::find($sent[0]['id']), 'أحمد الطالب', null, 'link', null, null);
    $this->postJson('/api/v1/teacher/agreement-requests/'.$sent[0]['id'].'/cancel')->assertUnprocessable()->assertJsonPath('code', 'request_answered');

    $sync = $this->getJson('/api/v1/teacher/sync')->json('data');
    expect($sync['agreement_requests'])->toHaveCount(1)
        ->and($sync['agreement_requests'][0])->toMatchArray(['id' => $sent[0]['id'], 'signed_by' => 'أحمد الطالب', 'link' => null]);
});
