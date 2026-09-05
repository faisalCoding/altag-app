<?php

use App\Http\Middleware\RequirePendingSurveys;
use App\Jobs\SendGuardianWhatsappJob;
use App\Livewire\Public\FormSubmit;
use App\Livewire\Supervisor\FormBuilder;
use App\Livewire\Supervisor\ManageForms;
use App\Models\AppNotification;
use App\Models\Circle;
use App\Models\Form;
use App\Models\FormAssignment;
use App\Models\Guardian;
use App\Models\Leaderboard;
use App\Models\Manager;
use App\Models\Stage;
use App\Models\Student;
use App\Models\Supervisor;
use App\Models\Teacher;
use App\Services\SurveyAssignmentService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->stage = Stage::factory()->create();
    $this->circle = Circle::factory()->create(['stage_id' => $this->stage->id]);

    $this->teacher = Teacher::factory()->create();
    $this->teacher->circles()->attach($this->circle->id);

    $this->guardian = Guardian::factory()->create();
    $this->student = Student::factory()->create([
        'circle_id' => $this->circle->id,
        'guardian_id' => $this->guardian->id,
    ]);

    $this->supervisor = Supervisor::factory()->create();
});

/** @param array<string, mixed> $audience */
function survey(array $audience, array $extra = []): Form
{
    return Form::create(array_merge([
        'supervisor_id' => Supervisor::factory()->create()->id,
        'title' => 'استبانة الرضا',
        'slug' => 'survey-'.uniqid(),
        'color' => '#7a2727',
        'fields' => [[
            'id' => 'q_rating',
            'type' => 'rating',
            'label' => 'ما مدى رضاك؟',
            'required' => true,
            'options' => [],
            'max' => 5,
        ]],
        'audience' => $audience,
        'status' => 'published',
    ], $extra));
}

// ── Audience resolution ───────────────────────────────────────────────

it('asks every guardian when the audience says all of them', function () {
    Guardian::factory()->count(2)->create();

    $form = survey(['all_guardians' => true]);

    expect(SurveyAssignmentService::sync($form))->toBe(3);
    expect(FormAssignment::where('form_id', $form->id)->count())->toBe(3);
    expect(FormAssignment::where('form_id', $form->id)->pluck('role')->unique()->all())->toBe(['guardian']);
});

it('reaches the guardians of a stage through the children they hold', function () {
    // A guardian whose child sits in another stage must not be asked.
    $otherStage = Stage::factory()->create();
    $otherCircle = Circle::factory()->create(['stage_id' => $otherStage->id]);
    $outsider = Guardian::factory()->create();
    Student::factory()->create(['circle_id' => $otherCircle->id, 'guardian_id' => $outsider->id]);

    $form = survey(['stage_ids_for_guardians' => [$this->stage->id]]);
    SurveyAssignmentService::sync($form);

    $asked = FormAssignment::where('form_id', $form->id)->pluck('user_id');

    expect($asked->all())->toBe([$this->guardian->id]);
    expect($asked)->not->toContain($outsider->id);
});

it('reaches the teachers of a circle', function () {
    $otherTeacher = Teacher::factory()->create();
    $otherTeacher->circles()->attach(Circle::factory()->create(['stage_id' => $this->stage->id])->id);

    $form = survey(['circle_ids_for_teachers' => [$this->circle->id]]);
    SurveyAssignmentService::sync($form);

    expect(FormAssignment::where('form_id', $form->id)->pluck('user_id')->all())
        ->toBe([$this->teacher->id]);
});

it('asks named people directly', function () {
    $form = survey(['teacher_ids' => [$this->teacher->id]]);
    SurveyAssignmentService::sync($form);

    expect(FormAssignment::where('form_id', $form->id)->pluck('user_id')->all())
        ->toBe([$this->teacher->id]);
});

it('asks nobody when the audience names nobody', function () {
    $form = survey([]);

    expect(SurveyAssignmentService::sync($form))->toBe(0);
    expect(FormAssignment::count())->toBe(0);
});

it('leaves out students who are no longer active', function () {
    $gone = Student::factory()->create(['circle_id' => $this->circle->id, 'status' => 'withdrawn']);

    $form = survey(['all_students' => true]);
    SurveyAssignmentService::sync($form);

    expect(FormAssignment::where('form_id', $form->id)->pluck('user_id'))->not->toContain($gone->id);
});

it('adds newcomers on a re-run without asking anyone twice', function () {
    $form = survey(['all_guardians' => true]);

    expect(SurveyAssignmentService::sync($form))->toBe(1);

    Guardian::factory()->create(); // joined after the survey went out

    expect(SurveyAssignmentService::sync($form))->toBe(1);
    expect(FormAssignment::where('form_id', $form->id)->count())->toBe(2);
});

it('asks a person holding two targeted roles only once', function () {
    $both = Teacher::factory()->create();
    $both->roles()->create(['role' => 'supervisor', 'is_approved' => true]);

    $form = survey(['teacher_ids' => [$both->id], 'supervisor_ids' => [$both->id]]);
    SurveyAssignmentService::sync($form);

    expect(FormAssignment::where('form_id', $form->id)->where('user_id', $both->id)->count())->toBe(1);
});

// ── Notifying ─────────────────────────────────────────────────────────

it('notifies each person once, however often it is called', function () {
    $form = survey(['all_guardians' => true]);
    SurveyAssignmentService::sync($form);

    expect(SurveyAssignmentService::notifyPending($form))->toBe(1);
    expect(SurveyAssignmentService::notifyPending($form))->toBe(0);

    expect(AppNotification::where('recipient_id', $this->guardian->id)
        ->where('type', 'survey')->count())->toBe(1);
});

// ── The gate ──────────────────────────────────────────────────────────

it('holds the app shut for someone who owes a blocking survey', function () {
    $form = survey(['teacher_ids' => [$this->teacher->id]], ['is_blocking' => true]);
    SurveyAssignmentService::sync($form);

    $this->actingAs($this->teacher, 'teacher')
        ->get(route('teacher.dashboard'))
        ->assertRedirect(route('surveys.required'));
});

it('lets an ordinary survey pass without shutting anything', function () {
    $form = survey(['teacher_ids' => [$this->teacher->id]]); // is_blocking defaults false
    SurveyAssignmentService::sync($form);

    $this->actingAs($this->teacher, 'teacher')
        ->get(route('teacher.dashboard'))
        ->assertSuccessful();
});

it('keeps the survey itself reachable from behind the gate', function () {
    $form = survey(['teacher_ids' => [$this->teacher->id]], ['is_blocking' => true]);
    SurveyAssignmentService::sync($form);

    $this->actingAs($this->teacher, 'teacher')
        ->get(route('forms.submit', $form->slug))
        ->assertSuccessful();
});

it('keeps the gate screen and logging out reachable from behind the gate', function () {
    $form = survey(['teacher_ids' => [$this->teacher->id]], ['is_blocking' => true]);
    SurveyAssignmentService::sync($form);

    $this->actingAs($this->teacher, 'teacher')
        ->get(route('surveys.required'))
        ->assertSuccessful()
        ->assertSee($form->title);

    $this->actingAs($this->teacher, 'teacher')
        ->post(route('logout'))
        ->assertRedirect(route('home'));
});

it('never shuts the app on a manager, who is who unshuts it', function () {
    $manager = Manager::factory()->create();
    $form = survey(['all_managers' => true], ['is_blocking' => true]);
    SurveyAssignmentService::sync($form);

    // Still asked and still counted — only never barred.
    expect(FormAssignment::where('form_id', $form->id)->where('user_id', $manager->id)->exists())->toBeTrue();

    $this->actingAs($manager, 'manager')
        ->get(route('manager.dashboard'))
        ->assertSuccessful();
});

it('opens the app the moment the survey is answered', function () {
    $form = survey(['teacher_ids' => [$this->teacher->id]], ['is_blocking' => true]);
    SurveyAssignmentService::sync($form);

    $this->actingAs($this->teacher, 'teacher');

    Livewire::test(FormSubmit::class, ['slug' => $form->slug])
        ->set('answers.q_rating', 5)
        ->call('submit')
        ->assertHasNoErrors();

    $assignment = FormAssignment::where('form_id', $form->id)->sole();
    expect($assignment->status)->toBe('completed');
    expect($assignment->form_response_id)->not->toBeNull();

    $this->get(route('teacher.dashboard'))->assertSuccessful();
});

it('stops blocking once the due date has passed', function () {
    Carbon\Carbon::setTestNow('2026-08-20 10:00:00');

    $form = survey(['teacher_ids' => [$this->teacher->id]], [
        'is_blocking' => true,
        'due_date' => '2026-08-19',
    ]);
    SurveyAssignmentService::sync($form);

    // Still owed, and still listed — but no longer a locked door.
    expect(FormAssignment::where('form_id', $form->id)->pending()->count())->toBe(1);

    $this->actingAs($this->teacher, 'teacher')
        ->get(route('teacher.dashboard'))
        ->assertSuccessful();
});

it('does not block for a survey still in draft', function () {
    $form = survey(['teacher_ids' => [$this->teacher->id]], [
        'is_blocking' => true,
        'status' => 'draft',
    ]);
    SurveyAssignmentService::sync($form);

    $this->actingAs($this->teacher, 'teacher')
        ->get(route('teacher.dashboard'))
        ->assertSuccessful();
});

it('does not block someone who was never asked', function () {
    $form = survey(['teacher_ids' => [$this->teacher->id]], ['is_blocking' => true]);
    SurveyAssignmentService::sync($form);

    $bystander = Teacher::factory()->create();

    $this->actingAs($bystander, 'teacher')
        ->get(route('teacher.dashboard'))
        ->assertSuccessful();
});

it('sends someone with nothing owed away from the gate screen', function () {
    $this->actingAs($this->teacher, 'teacher')
        ->get(route('surveys.required'))
        ->assertRedirect('/');
});

it('leaves a stranger answering by public link with nothing to complete', function () {
    $form = survey(['teacher_ids' => [$this->teacher->id]], ['is_blocking' => true]);
    SurveyAssignmentService::sync($form);

    // Nobody signed in: the response saves and no assignment is touched.
    Livewire::test(FormSubmit::class, ['slug' => $form->slug])
        ->set('answers.q_rating', 3)
        ->call('submit')
        ->assertHasNoErrors();

    expect(FormAssignment::where('form_id', $form->id)->pending()->count())->toBe(1);
});

it('counts how far the audience has got', function () {
    $form = survey(['all_guardians' => true]);
    Guardian::factory()->count(3)->create();
    SurveyAssignmentService::sync($form);

    expect($form->completion())->toBe(['assigned' => 4, 'completed' => 0, 'rate' => 0]);

    SurveyAssignmentService::completeFor($form, $this->guardian->id);

    expect($form->fresh()->completion())->toBe(['assigned' => 4, 'completed' => 1, 'rate' => 25]);
});

// ── The middleware itself ─────────────────────────────────────────────
// The exempt routes above are public and never carry this middleware, so the
// exemption list can only be proven by running the middleware directly.

/** Run the gate against a named route and report whether it let the request by. */
function gatePasses(string $routeName, string $path = '/anywhere'): bool
{
    $request = Request::create($path);
    $request->setRouteResolver(fn () => tap(
        new Route(['GET'], $path, []),
        fn ($r) => $r->name($routeName)
    ));

    // A fabricated request carries no resolver of its own, so it would see
    // nobody signed in and wave every request through for the wrong reason.
    $request->setUserResolver(fn ($guard = null) => auth()->guard($guard)->user());

    $response = app(RequirePendingSurveys::class)
        ->handle($request, fn () => new Response('passed'));

    return $response->getContent() === 'passed';
}

it('lets every escape route through the gate', function (string $routeName) {
    $form = survey(['teacher_ids' => [$this->teacher->id]], ['is_blocking' => true]);
    SurveyAssignmentService::sync($form);
    $this->actingAs($this->teacher, 'teacher');

    expect(gatePasses($routeName))->toBeTrue();
})->with(['logout', 'login', 'pending-approval', 'forms.submit', 'forms.report', 'surveys.required', 'switch-role']);

it('turns an ordinary route away while a blocking survey is owed', function () {
    $form = survey(['teacher_ids' => [$this->teacher->id]], ['is_blocking' => true]);
    SurveyAssignmentService::sync($form);
    $this->actingAs($this->teacher, 'teacher');

    expect(gatePasses('teacher.students'))->toBeFalse();
});

it('lets livewire through, or the survey could never be filled in', function () {
    $form = survey(['teacher_ids' => [$this->teacher->id]], ['is_blocking' => true]);
    SurveyAssignmentService::sync($form);
    $this->actingAs($this->teacher, 'teacher');

    expect(gatePasses('livewire.update', '/livewire/update'))->toBeTrue();
});

it('lets a manager through an ordinary route even while owing one', function () {
    $manager = Manager::factory()->create();
    $form = survey(['all_managers' => true], ['is_blocking' => true]);
    SurveyAssignmentService::sync($form);
    $this->actingAs($manager, 'manager');

    expect(gatePasses('manager.students'))->toBeTrue();
});

// ── Publishing from the builder ───────────────────────────────────────

it('asks the audience only when published, never on an ordinary save', function () {
    $supervisor = Supervisor::factory()->create();
    $supervisor->stages()->attach($this->stage->id);
    $this->actingAs($supervisor, 'supervisor');

    $component = Livewire::test(FormBuilder::class)
        ->set('title', 'رضا المعلمين')
        ->set('slug', 'teacher-satisfaction')
        ->set('fields', [[
            'id' => 'q1', 'type' => 'rating', 'label' => 'رضاك؟',
            'required' => true, 'options' => [], 'max' => 5,
        ]])
        ->set('audience', ['teacher_ids' => [$this->teacher->id]]);

    // Saving writes the survey and asks nobody.
    $component->call('save', false)->assertHasNoErrors();
    expect(FormAssignment::count())->toBe(0);
    expect(Form::where('slug', 'teacher-satisfaction')->sole()->status)->toBe('draft');

    // Publishing is what reaches people.
    $component->call('publish');

    $form = Form::where('slug', 'teacher-satisfaction')->sole();
    expect($form->status)->toBe('published');
    expect($form->published_at)->not->toBeNull();
    expect(FormAssignment::where('form_id', $form->id)->count())->toBe(1);
});

it('refuses to publish a survey aimed at nobody', function () {
    $supervisor = Supervisor::factory()->create();
    $this->actingAs($supervisor, 'supervisor');

    Livewire::test(FormBuilder::class)
        ->set('title', 'بلا جمهور')
        ->set('slug', 'no-audience')
        ->set('fields', [[
            'id' => 'q1', 'type' => 'rating', 'label' => 'رضاك؟',
            'required' => true, 'options' => [], 'max' => 5,
        ]])
        ->set('audience', [])
        ->call('publish');

    expect(Form::where('slug', 'no-audience')->exists())->toBeFalse();
    expect(FormAssignment::count())->toBe(0);
});

it('publishes twice without asking anyone twice', function () {
    $supervisor = Supervisor::factory()->create();
    $supervisor->stages()->attach($this->stage->id);
    $this->actingAs($supervisor, 'supervisor');

    $component = Livewire::test(FormBuilder::class)
        ->set('title', 'مكرر')
        ->set('slug', 'twice')
        ->set('fields', [[
            'id' => 'q1', 'type' => 'rating', 'label' => 'رضاك؟',
            'required' => true, 'options' => [], 'max' => 5,
        ]])
        ->set('audience', ['teacher_ids' => [$this->teacher->id]]);

    $component->call('publish');
    $component->call('publish');

    expect(FormAssignment::where('user_id', $this->teacher->id)->count())->toBe(1);
    expect(AppNotification::where('type', 'survey')->count())->toBe(1);
});

it('counts the audience before anyone is asked', function () {
    $supervisor = Supervisor::factory()->create();
    $this->actingAs($supervisor, 'supervisor');

    $component = Livewire::test(FormBuilder::class)
        ->set('audience', ['all_guardians' => true]);

    expect($component->instance()->audienceSize())->toBe(1);
    expect(FormAssignment::count())->toBe(0); // counting asks nobody
});

// ── The "owed by you" card ────────────────────────────────────────────

it('shows a pending survey on the dashboard of whoever owes it', function () {
    $form = survey(['teacher_ids' => [$this->teacher->id]]);
    SurveyAssignmentService::sync($form);

    $this->actingAs($this->teacher, 'teacher')
        ->get(route('teacher.dashboard'))
        ->assertSuccessful()
        ->assertSee('مطلوب منك')
        ->assertSee($form->title);
});

it('shows nothing on the dashboard of someone who owes none', function () {
    $form = survey(['teacher_ids' => [$this->teacher->id]]);
    SurveyAssignmentService::sync($form);

    $this->actingAs(Teacher::factory()->create(), 'teacher')
        ->get(route('teacher.dashboard'))
        ->assertSuccessful()
        ->assertDontSee($form->title);
});

it('drops the card once the survey is answered', function () {
    $form = survey(['teacher_ids' => [$this->teacher->id]]);
    SurveyAssignmentService::sync($form);
    SurveyAssignmentService::completeFor($form, $this->teacher->id);

    $this->actingAs($this->teacher, 'teacher')
        ->get(route('teacher.dashboard'))
        ->assertSuccessful()
        ->assertDontSee($form->title);
});

// ── WhatsApp for guardians ────────────────────────────────────────────

it('sends guardians the link on whatsapp as well as in the app', function () {
    Queue::fake();

    $this->guardian->update(['phone' => '966500000000']);

    // The sending session hangs off a circle's active competition.
    $leaderboard = Leaderboard::create([
        'circle_id' => $this->circle->id,
        'supervisor_id' => $this->supervisor->id,
        'title' => 'مسابقة',
        'start_date' => now()->subMonth()->toDateString(),
        'end_date' => now()->addMonth()->toDateString(),
        'is_active' => true,
    ]);
    $leaderboard->circles()->attach($this->circle->id);

    $form = survey(['all_guardians' => true]);
    SurveyAssignmentService::sync($form);
    SurveyAssignmentService::notifyPending($form);

    Queue::assertPushed(
        SendGuardianWhatsappJob::class,
        fn ($job) => $job->phone === '966500000000' && str_contains($job->message, $form->title)
    );
});

it('still assigns a guardian who has no phone', function () {
    Queue::fake();

    $this->guardian->update(['phone' => null]);

    $form = survey(['all_guardians' => true]);
    SurveyAssignmentService::sync($form);

    expect(SurveyAssignmentService::notifyPending($form))->toBe(1);

    Queue::assertNothingPushed();
    expect(FormAssignment::where('form_id', $form->id)->count())->toBe(1);
});

it('does not whatsapp roles that live inside the app', function () {
    Queue::fake();

    $this->teacher->update(['phone' => '966500000001']);

    $form = survey(['teacher_ids' => [$this->teacher->id]]);
    SurveyAssignmentService::sync($form);
    SurveyAssignmentService::notifyPending($form);

    Queue::assertNothingPushed();
});

// ── How far an author may reach ───────────────────────────────────────
// The audience arrives as JSON from a browser, so reach is decided from the
// form's recorded author and never from what the request asks for.

function authored(string $type, int $id, array $audience): Form
{
    return survey($audience, ['created_by_type' => $type, 'created_by_id' => $id]);
}

it('lets a manager reach the whole academy', function () {
    $manager = Manager::factory()->create();
    Guardian::factory()->count(2)->create();

    $form = authored('manager', $manager->id, ['all_guardians' => true]);

    expect(SurveyAssignmentService::sync($form))->toBe(3);
});

it('keeps a teacher inside their own circles however wide they aim', function () {
    $mine = Student::factory()->create(['circle_id' => $this->circle->id]);

    $otherCircle = Circle::factory()->create(['stage_id' => $this->stage->id]);
    $notMine = Student::factory()->create(['circle_id' => $otherCircle->id]);

    // Aiming at every student in the academy.
    $form = authored('teacher', $this->teacher->id, ['all_students' => true]);
    SurveyAssignmentService::sync($form);

    $asked = FormAssignment::where('form_id', $form->id)->pluck('user_id');

    expect($asked)->toContain($mine->id);
    expect($asked)->not->toContain($notMine->id);
});

it('lets a teacher reach the guardians of their own students only', function () {
    $otherCircle = Circle::factory()->create(['stage_id' => $this->stage->id]);
    $otherGuardian = Guardian::factory()->create();
    Student::factory()->create(['circle_id' => $otherCircle->id, 'guardian_id' => $otherGuardian->id]);

    $form = authored('teacher', $this->teacher->id, ['all_guardians' => true]);
    SurveyAssignmentService::sync($form);

    $asked = FormAssignment::where('form_id', $form->id)->pluck('user_id');

    expect($asked)->toContain($this->guardian->id);   // the guardian of their student
    expect($asked)->not->toContain($otherGuardian->id);
});

it('keeps a supervisor inside their own stages', function () {
    $supervisor = Supervisor::factory()->create();
    $supervisor->stages()->attach($this->stage->id);

    $otherStage = Stage::factory()->create();
    $otherCircle = Circle::factory()->create(['stage_id' => $otherStage->id]);
    $outsider = Student::factory()->create(['circle_id' => $otherCircle->id]);

    $form = authored('supervisor', $supervisor->id, ['all_students' => true]);
    SurveyAssignmentService::sync($form);

    $asked = FormAssignment::where('form_id', $form->id)->pluck('user_id');

    expect($asked)->toContain($this->student->id);
    expect($asked)->not->toContain($outsider->id);
});

it('lets anyone ask themselves, even holding no circle at all', function () {
    $lonely = Teacher::factory()->create(); // attached to nothing

    $form = authored('teacher', $lonely->id, ['teacher_ids' => [$lonely->id, $this->teacher->id]]);
    SurveyAssignmentService::sync($form);

    $asked = FormAssignment::where('form_id', $form->id)->pluck('user_id');

    expect($asked->all())->toBe([$lonely->id]);
});

it('leaves a form with no recorded author alone', function () {
    // Predates the ownership morph; narrowing it would silently empty old surveys.
    $form = survey(['all_guardians' => true]); // no created_by

    expect(SurveyAssignmentService::sync($form))->toBe(1);
});

// ── Three roles, one builder ──────────────────────────────────────────

it('lets each role reach the builder and its list', function (string $role) {
    $user = match ($role) {
        'manager' => Manager::factory()->create(),
        'supervisor' => Supervisor::factory()->create(),
        'teacher' => Teacher::factory()->create(),
    };

    $this->actingAs($user, $role);

    $this->get(route("{$role}.forms"))->assertSuccessful();
    $this->get(route("{$role}.forms.create"))->assertSuccessful();
})->with(['manager', 'supervisor', 'teacher']);

it('records who wrote a form, whichever role they wrote it in', function () {
    $this->actingAs($this->teacher, 'teacher');

    Livewire::test(FormBuilder::class)
        ->set('title', 'رضا حلقتي')
        ->set('slug', 'my-circle')
        ->set('fields', [[
            'id' => 'q1', 'type' => 'rating', 'label' => 'رضاك؟',
            'required' => true, 'options' => [], 'max' => 5,
        ]])
        ->call('save', false)
        ->assertHasNoErrors();

    $form = Form::where('slug', 'my-circle')->sole();

    expect($form->created_by_type)->toBe('teacher');
    expect($form->created_by_id)->toBe($this->teacher->id);
});

it('shows each author their own forms and not other people\'s', function () {
    $otherTeacher = Teacher::factory()->create();

    $mine = survey([], ['created_by_type' => 'teacher', 'created_by_id' => $this->teacher->id, 'title' => 'استبانتي']);
    $theirs = survey([], ['created_by_type' => 'teacher', 'created_by_id' => $otherTeacher->id, 'title' => 'استبانة غيري']);

    $this->actingAs($this->teacher, 'teacher')
        ->get(route('teacher.forms'))
        ->assertSuccessful()
        ->assertSee($mine->title)
        ->assertDontSee($theirs->title);
});

it('lets a manager see every form in the academy', function () {
    $theirs = survey([], ['created_by_type' => 'teacher', 'created_by_id' => $this->teacher->id, 'title' => 'استبانة معلم']);

    $this->actingAs(Manager::factory()->create(), 'manager')
        ->get(route('manager.forms'))
        ->assertSuccessful()
        ->assertSee($theirs->title);
});

it('refuses to let one teacher delete another teacher\'s form', function () {
    $otherTeacher = Teacher::factory()->create();
    $theirs = survey([], ['created_by_type' => 'teacher', 'created_by_id' => $otherTeacher->id]);

    $this->actingAs($this->teacher, 'teacher');

    // Not visible to them at all, so the lookup fails before any check runs.
    expect(fn () => Livewire::test(ManageForms::class)->call('delete', $theirs->id))
        ->toThrow(ModelNotFoundException::class);

    expect(Form::whereKey($theirs->id)->exists())->toBeTrue();
});

it('lets a supervisor still open a form made before ownership was a morph', function () {
    $supervisor = Supervisor::factory()->create();

    // Only supervisor_id, exactly as every form created before the change.
    $legacy = Form::create([
        'supervisor_id' => $supervisor->id,
        'title' => 'نموذج قديم',
        'slug' => 'legacy-'.uniqid(),
        'color' => '#7a2727',
        'fields' => [['id' => 'q1', 'type' => 'text', 'label' => 'اسمك', 'required' => true, 'options' => []]],
    ]);

    $this->actingAs($supervisor, 'supervisor')
        ->get(route('supervisor.forms.edit', $legacy->id))
        ->assertSuccessful();

    $this->actingAs($supervisor, 'supervisor')
        ->get(route('supervisor.forms'))
        ->assertSuccessful()
        ->assertSee('نموذج قديم');
});
