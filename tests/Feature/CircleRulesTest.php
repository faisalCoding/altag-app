<?php

use App\Livewire\Manager\RulesAcknowledgements;
use App\Livewire\Manager\RulesEditor;
use App\Livewire\Shared\RulesAcknowledgement as RulesPage;
use App\Models\AppNotification;
use App\Models\Manager;
use App\Models\RuleAcknowledgement;
use App\Models\RuleClause;
use App\Models\RuleSection;
use App\Models\RuleVersion;
use App\Models\Supervisor;
use App\Models\Teacher;
use App\Support\CircleRules;
use Livewire\Livewire;

/*
 * A draft of two sections: the first speaks to everyone and to teachers, the
 * second to supervisors, with an empty clause that publishing leaves out.
 */
beforeEach(function () {
    $conduct = RuleSection::create(['title' => 'الانضباط', 'position' => 1]);
    $conduct->clauses()->createMany([
        ['audience' => 'shared', 'body' => 'الحضور قبل بدء الحلقة بعشر دقائق.', 'position' => 1],
        ['audience' => 'teacher', 'body' => 'تحضير الطلاب في يومه.', 'position' => 2],
    ]);
    $follow = RuleSection::create(['title' => 'المتابعة', 'position' => 2]);
    $follow->clauses()->createMany([
        ['audience' => 'supervisor', 'body' => 'متابعة تحضير المعلمين أسبوعياً.', 'position' => 1],
        ['audience' => 'shared', 'body' => '   ', 'position' => 2],
    ]);

    $this->manager = Manager::factory()->create();
    $this->teacher = Teacher::factory()->create(['phone' => '0501234567', 'access_token' => 'tok-teacher']);
    $this->supervisor = Supervisor::factory()->create();

    // A supervisor who also teaches.
    $this->both = Teacher::factory()->create(['name' => 'سالم المشرف']);
    $this->both->roles()->create(['role' => 'supervisor', 'is_approved' => true]);
});

function publishRules(bool $asking = true): RuleVersion
{
    return CircleRules::publish(test()->manager, $asking, null);
}

it('freezes the draft into a version, leaving out empty clauses', function () {
    $version = publishRules();

    RuleClause::query()->update(['body' => 'نص آخر']);

    expect($version->number)->toBe(1)
        ->and($version->fresh()->content)->toBe([
            ['title' => 'الانضباط', 'clauses' => [
                ['audience' => 'shared', 'body' => 'الحضور قبل بدء الحلقة بعشر دقائق.'],
                ['audience' => 'teacher', 'body' => 'تحضير الطلاب في يومه.'],
            ]],
            ['title' => 'المتابعة', 'clauses' => [
                ['audience' => 'supervisor', 'body' => 'متابعة تحضير المعلمين أسبوعياً.'],
            ]],
        ]);
});

it('gives each reader the shared clauses and those of their roles, numbered through', function () {
    $version = publishRules();
    $bodies = fn (array $text) => collect($text)->flatMap(fn ($section) => collect($section['clauses'])->map(fn ($c) => $c['number'].' '.$c['body']))->all();

    expect($bodies(CircleRules::textFor($version, ['teacher'])))->toBe(['1 الحضور قبل بدء الحلقة بعشر دقائق.', '2 تحضير الطلاب في يومه.'])
        ->and($bodies(CircleRules::textFor($version, ['supervisor'])))->toBe(['1 الحضور قبل بدء الحلقة بعشر دقائق.', '2 متابعة تحضير المعلمين أسبوعياً.'])
        ->and(CircleRules::textFor($version, ['teacher', 'supervisor']))->toHaveCount(2)
        ->and(CircleRules::rolesOf($this->both))->toBe(['teacher', 'supervisor']);
});

it('asks for the word once a version asks, and again only on a change of substance', function () {
    expect(CircleRules::owes($this->teacher, 'teacher'))->toBeFalse();

    $first = publishRules(asking: false);

    expect($first->requires_acknowledgement)->toBeTrue()
        ->and(CircleRules::owes($this->teacher, 'teacher'))->toBeTrue();

    CircleRules::acknowledge($this->teacher, $first, '10.0.0.1', 'Safari');
    expect(CircleRules::owes($this->teacher, 'teacher'))->toBeFalse();

    publishRules(asking: false);
    expect(CircleRules::owes($this->teacher, 'teacher'))->toBeFalse();

    publishRules(asking: true);
    expect(CircleRules::owes($this->teacher, 'teacher'))->toBeTrue();
});

it('takes one word for every role a person holds, and asks again for a role gained after', function () {
    $version = publishRules();
    $teacher = $this->teacher;

    CircleRules::acknowledge($teacher, $version, null, null);
    $teacher->roles()->create(['role' => 'supervisor', 'is_approved' => true]);

    expect(CircleRules::owes($teacher, 'teacher'))->toBeFalse()
        ->and(CircleRules::owes($teacher->fresh(), 'supervisor'))->toBeTrue();

    CircleRules::acknowledge($teacher->fresh(), $version, null, null);

    expect(RuleAcknowledgement::where('user_id', $teacher->id)->count())->toBe(1)
        ->and(RuleAcknowledgement::where('user_id', $teacher->id)->value('roles'))->toBe(['teacher', 'supervisor'])
        ->and(CircleRules::owes($teacher, 'supervisor'))->toBeFalse();
});

it('reminds a teacher above every page until they give their word, and keeps what they read', function () {
    $version = publishRules();
    $this->actingAs($this->teacher, 'teacher');

    $this->get(route('teacher.dashboard'))->assertSuccessful()->assertSee('data-rules-reminder', false);

    Livewire::test(RulesPage::class, ['role' => 'teacher'])
        ->assertSee('تحضير الطلاب في يومه.')
        ->assertDontSee('متابعة تحضير المعلمين أسبوعياً.')
        ->call('acknowledge')
        ->assertHasErrors('agreed')
        ->set('agreed', true)
        ->call('acknowledge')
        ->assertHasNoErrors()
        ->assertSee('أقررتَ بالإصدار');

    $acknowledgement = RuleAcknowledgement::sole();

    expect($acknowledgement->user_id)->toBe($this->teacher->id)
        ->and($acknowledgement->rule_version_id)->toBe($version->id)
        ->and($acknowledgement->content_hash)->toBe(CircleRules::hashOf(CircleRules::textFor($version, ['teacher'])))
        ->and($acknowledgement->ip)->not->toBeNull();

    $this->get(route('teacher.dashboard'))->assertSuccessful()->assertDontSee('data-rules-reminder', false);
});

it('shows a supervisor who teaches both parts on either page', function () {
    publishRules();
    $this->actingAs(Supervisor::find($this->both->id), 'supervisor');

    Livewire::test(RulesPage::class, ['role' => 'supervisor'])
        ->assertSee('تحضير الطلاب في يومه.')
        ->assertSee('متابعة تحضير المعلمين أسبوعياً.')
        ->assertSee('تقرؤها بصفتك معلم ومشرف');
});

it('lets the manager write, publish and tell everyone', function () {
    RuleSection::query()->delete();
    $this->actingAs($this->manager, 'manager');

    $editor = Livewire::test(RulesEditor::class)->call('addSection');
    $section = RuleSection::sole();
    $clause = $section->clauses()->sole();

    $editor->set("titles.{$section->id}", 'التعامل مع الطلاب')
        ->set("bodies.{$clause->id}", 'الرفق بالطالب.')
        ->set("audiences.{$clause->id}", 'teacher')
        ->set("audiences.{$clause->id}", 'nobody')
        ->call('openPublish')
        ->set('kind', 'wording')
        ->call('publish');

    $version = RuleVersion::sole();

    expect($version->requires_acknowledgement)->toBeTrue()
        ->and($version->content)->toBe([['title' => 'التعامل مع الطلاب', 'clauses' => [['audience' => 'teacher', 'body' => 'الرفق بالطالب.']]]])
        ->and(AppNotification::where('type', 'rules_published')->where('recipient_type', 'teacher')->count())->toBe(2)
        ->and(AppNotification::where('type', 'rules_published')->where('recipient_type', 'supervisor')->count())->toBe(2);
});

it('follows up who owes their word, with a reminder onto the rules page and the word on paper', function () {
    $version = publishRules();
    CircleRules::acknowledge($this->supervisor, $version, '10.0.0.2', 'Chrome');
    $this->actingAs($this->manager, 'manager');

    $people = collect(Livewire::test(RulesAcknowledgements::class)->instance()->people)->keyBy('id');

    expect($people[$this->supervisor->id]['owed'])->toBe([])
        ->and($people[$this->teacher->id]['owed'])->toBe(['teacher'])
        ->and($people[$this->both->id]['owed'])->toBe(['teacher', 'supervisor']);

    $url = $people[$this->teacher->id]['reminder'];
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    expect($url)->toStartWith('https://wa.me/966501234567?')
        ->and($query['text'])->toContain(route('teacher.magic-link', ['token' => 'tok-teacher', 'redirect' => route('teacher.rules')]));

    Livewire::test(RulesAcknowledgements::class)
        ->set('status', 'pending')
        ->assertSee($this->teacher->name)
        ->assertDontSee($this->supervisor->name);

    $pdf = $this->get(route('manager.circle-rules.pdf', RuleAcknowledgement::sole()));

    $pdf->assertSuccessful();
    expect($pdf->streamedContent())->toStartWith('%PDF');
});

it('keeps the paper and the follow-up to the manager', function () {
    $acknowledgement = CircleRules::acknowledge($this->teacher, publishRules(), null, null);
    $this->actingAs($this->teacher, 'teacher');

    $this->get(route('manager.circle-rules.pdf', $acknowledgement))->assertRedirect();
    $this->get(route('manager.circle-rules'))->assertRedirect();
});

it('opens a supervisor\'s link onto a page of this site only', function () {
    $this->supervisor->update(['access_token' => 'tok-sup']);

    $this->get(route('supervisor.magic-link', ['token' => 'tok-sup', 'redirect' => route('supervisor.rules')]))
        ->assertRedirect(route('supervisor.rules'));

    auth('supervisor')->logout();

    $this->get(route('supervisor.magic-link', ['token' => 'tok-sup', 'redirect' => 'https://evil.example/x']))
        ->assertRedirect(route('supervisor.dashboard'));
});
