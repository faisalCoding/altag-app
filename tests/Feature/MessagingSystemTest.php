<?php

use App\Models\Circle;
use App\Models\Conversation;
use App\Models\Guardian;
use App\Models\Manager;
use App\Models\Message;
use App\Models\Stage;
use App\Models\Student;
use App\Models\Supervisor;
use App\Models\Teacher;
use App\Services\MessagingService;
use Livewire\Livewire;

it('finds or creates a single 1:1 conversation between two participants', function () {
    $student = Student::factory()->create();
    $teacher = Teacher::factory()->create();

    $first = Conversation::findOrCreateBetween('student', $student->id, 'teacher', $teacher->id);
    $second = Conversation::findOrCreateBetween('teacher', $teacher->id, 'student', $student->id);

    expect($first->id)->toBe($second->id);
    expect(Conversation::count())->toBe(1);
    expect($first->participants)->toHaveCount(2);
});

it('only surfaces people the searcher actually has a relationship with', function () {
    $stage = Stage::create(['name' => 'المرحلة الأولى']);
    $circle = Circle::create(['name' => 'حلقة النور', 'stage_id' => $stage->id]);

    $teacherInCircle = Teacher::factory()->create(['name' => 'أحمد المعلم']);
    $teacherInCircle->circles()->attach($circle->id);

    $student = Student::factory()->create(['name' => 'أحمد الطالب', 'circle_id' => $circle->id]);

    $unrelatedTeacher = Teacher::factory()->create(['name' => 'أحمد الغريب']);
    Manager::factory()->create(['name' => 'سالم المدير']);

    $results = MessagingService::searchDirectory('أحمد', 'student', $student->id);
    $names = collect($results)->pluck('name');

    expect($names)->toContain('أحمد المعلم');
    expect($names)->not->toContain('أحمد الطالب'); // never yourself
    expect($names)->not->toContain('أحمد الغريب'); // no shared circle
});

it('always allows messaging the manager and vice versa', function () {
    $student = Student::factory()->create(['name' => 'طالب']);
    Manager::factory()->create(['name' => 'المدير العام']);

    $results = MessagingService::searchDirectory('مدير', 'student', $student->id);

    expect(collect($results)->pluck('name'))->toContain('المدير العام');
});

it('blocks starting a conversation with someone outside the sender relationships', function () {
    $student = Student::factory()->create();
    $unrelatedTeacher = Teacher::factory()->create();

    $this->actingAs($student, 'student');

    Livewire::test('messaging.inbox')
        ->call('startConversation', 'teacher', $unrelatedTeacher->id);

    expect(Conversation::count())->toBe(0);
});

it('allows a guardian to message only their own child, not other students', function () {
    $guardian = Guardian::factory()->create();
    $ownChild = Student::factory()->create(['guardian_id' => $guardian->id]);
    $otherChild = Student::factory()->create();

    expect(MessagingService::isAllowedToMessage('guardian', $guardian->id, 'student', $ownChild->id))->toBeTrue();
    expect(MessagingService::isAllowedToMessage('guardian', $guardian->id, 'student', $otherChild->id))->toBeFalse();
});

it('allows a supervisor to message teachers and students only within their supervised stages', function () {
    $stage = Stage::create(['name' => 'المرحلة الأولى']);
    $otherStage = Stage::create(['name' => 'المرحلة الثانية']);
    $circle = Circle::create(['name' => 'حلقة النور', 'stage_id' => $stage->id]);
    $otherCircle = Circle::create(['name' => 'حلقة أخرى', 'stage_id' => $otherStage->id]);

    $supervisor = Supervisor::factory()->create();
    $supervisor->stages()->attach($stage->id);

    $teacherInScope = Teacher::factory()->create();
    $teacherInScope->circles()->attach($circle->id);

    $teacherOutOfScope = Teacher::factory()->create();
    $teacherOutOfScope->circles()->attach($otherCircle->id);

    expect(MessagingService::isAllowedToMessage('supervisor', $supervisor->id, 'teacher', $teacherInScope->id))->toBeTrue();
    expect(MessagingService::isAllowedToMessage('supervisor', $supervisor->id, 'teacher', $teacherOutOfScope->id))->toBeFalse();
});

it('sends a message and reflects it as unread for the other participant', function () {
    $circle = Circle::create(['name' => 'حلقة النور', 'stage_id' => Stage::create(['name' => 'المرحلة الأولى'])->id]);
    $student = Student::factory()->create(['circle_id' => $circle->id]);
    $teacher = Teacher::factory()->create();
    $teacher->circles()->attach($circle->id);

    $this->actingAs($student, 'student');

    Livewire::test('messaging.inbox')
        ->call('startComposing')
        ->set('recipientSearch', $teacher->name)
        ->call('startConversation', 'teacher', $teacher->id)
        ->set('newMessageBody', 'السلام عليكم يا أستاذ')
        ->call('sendMessage')
        ->assertSee('السلام عليكم يا أستاذ');

    expect(Message::count())->toBe(1);
    expect(MessagingService::unreadCountFor('teacher', $teacher->id))->toBe(1);
    expect(MessagingService::unreadCountFor('student', $student->id))->toBe(0);
});

it('marks a conversation as read once the recipient opens it', function () {
    $student = Student::factory()->create();
    $teacher = Teacher::factory()->create();

    $conversation = Conversation::findOrCreateBetween('student', $student->id, 'teacher', $teacher->id);
    Message::create([
        'conversation_id' => $conversation->id,
        'sender_type' => 'student',
        'sender_id' => $student->id,
        'body' => 'مرحباً',
    ]);

    expect(MessagingService::unreadCountFor('teacher', $teacher->id))->toBe(1);

    $this->actingAs($teacher, 'teacher');

    Livewire::test('messaging.inbox')->call('selectConversation', $conversation->id);

    expect(MessagingService::unreadCountFor('teacher', $teacher->id))->toBe(0);
});

it('prevents sending a message into a conversation the user does not belong to', function () {
    $studentA = Student::factory()->create();
    $studentB = Student::factory()->create();
    $teacher = Teacher::factory()->create();

    $conversation = Conversation::findOrCreateBetween('student', $studentA->id, 'teacher', $teacher->id);

    $this->actingAs($studentB, 'student');

    Livewire::test('messaging.inbox')
        ->call('selectConversation', $conversation->id)
        ->set('newMessageBody', 'محاولة تطفل')
        ->call('sendMessage');

    expect(Message::count())->toBe(0);
});

it('shows real unread counts in the student sidebar badge', function () {
    $student = Student::factory()->create();
    $teacher = Teacher::factory()->create();

    $conversation = Conversation::findOrCreateBetween('student', $student->id, 'teacher', $teacher->id);
    Message::create([
        'conversation_id' => $conversation->id,
        'sender_type' => 'teacher',
        'sender_id' => $teacher->id,
        'body' => 'رسالة من المعلم',
    ]);

    $this->actingAs($student, 'student');

    $this->get(route('student.dashboard'))
        ->assertSuccessful()
        ->assertSee('الرسائل');

    expect(MessagingService::unreadCountFor('student', $student->id))->toBe(1);
});
