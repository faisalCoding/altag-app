<?php

use App\Livewire\Supervisor\FormResponses;
use App\Livewire\Supervisor\Students as SupervisorStudents;
use App\Models\Circle;
use App\Models\Form;
use App\Models\FormResponse;
use App\Models\Stage;
use App\Models\Student;
use App\Models\Supervisor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->stage = Stage::create(['name' => 'المرحلة الابتدائية']);
    $this->otherStage = Stage::create(['name' => 'المرحلة المتوسطة']);
    $this->circle = Circle::create(['name' => 'حلقة الفجر', 'stage_id' => $this->stage->id]);

    $this->supervisor = Supervisor::factory()->create();
    $this->supervisor->stages()->attach([$this->stage->id, $this->otherStage->id]);

    $this->form = Form::create([
        'supervisor_id' => $this->supervisor->id,
        'title' => 'استمارة التسجيل',
        'slug' => 'reg-form',
        'color' => '#14b8a6',
        'fields' => [
            ['id' => 'f_name', 'type' => 'text', 'label' => 'الاسم الكامل', 'is_student_name' => true],
            ['id' => 'f_email', 'type' => 'text', 'label' => 'البريد', 'is_student_username' => true],
            ['id' => 'f_phone', 'type' => 'text', 'label' => 'رقم الجوال'],
            ['id' => 'f_birth', 'type' => 'date', 'label' => 'تاريخ الميلاد'],
            ['id' => 'f_nat', 'type' => 'text', 'label' => 'الجنسية'],
            ['id' => 'f_id', 'type' => 'text', 'label' => 'رقم الهوية'],
        ],
    ]);

    $this->actingAs($this->supervisor, 'supervisor');
});

it('maps all guessed fields from a response into the new student account', function () {
    $response = FormResponse::create([
        'form_id' => $this->form->id,
        'answers' => [
            'f_name' => 'عمر فاروق',
            'f_email' => 'omar',
            'f_phone' => '050-123-4567',
            'f_birth' => '2010-03-15',
            'f_nat' => 'سعودي',
            'f_id' => '1122334455',
        ],
    ]);

    Livewire::test(FormResponses::class, ['formId' => $this->form->id])
        ->call('openCreateModal', $response->id)
        ->assertSet('newStudentName', 'عمر فاروق')
        ->assertSet('newStudentEmail', 'omar')
        ->assertSet('newStudentPhone', '0501234567') // normalized (digits only)
        ->assertSet('newStudentBirthDate', '2010-03-15')
        ->assertSet('newStudentNationality', 'سعودي')
        ->assertSet('newStudentNationalId', '1122334455')
        ->call('createStudentAccount')
        ->assertHasNoErrors();

    $student = Student::where('name', 'عمر فاروق')->first();
    expect($student)->not->toBeNull();
    expect($student->email)->toBe('omar@altag-student.com');
    expect($student->phone)->toBe('0501234567');
    expect($student->birth_date->toDateString())->toBe('2010-03-15');
    expect($student->nationality)->toBe('سعودي');
    expect($student->national_id)->toBe('1122334455');
    expect($student->status)->toBe('registering');
});

it('generates a random email when the random toggle is on', function () {
    $response = FormResponse::create([
        'form_id' => $this->form->id,
        'answers' => ['f_name' => 'طالب بلا بريد'],
    ]);

    Livewire::test(FormResponses::class, ['formId' => $this->form->id])
        ->call('openCreateModal', $response->id)
        ->set('newStudentRandomEmail', true)
        ->call('createStudentAccount')
        ->assertHasNoErrors();

    $student = Student::where('name', 'طالب بلا بريد')->first();
    expect($student->email)->toStartWith('std_');
    expect($student->email)->toEndWith('@altag-student.com');
});

it('creates a student in a stage without a circle and resolves the effective stage', function () {
    $response = FormResponse::create([
        'form_id' => $this->form->id,
        'answers' => ['f_name' => 'طالب بلا حلقة', 'f_email' => 'noc'],
    ]);

    Livewire::test(FormResponses::class, ['formId' => $this->form->id])
        ->call('openCreateModal', $response->id)
        ->set('targetStageId', $this->stage->id)
        ->call('createStudentAccount')
        ->assertHasNoErrors();

    $student = Student::where('name', 'طالب بلا حلقة')->first();
    expect($student->circle_id)->toBeNull();
    expect($student->stage_id)->toBe($this->stage->id);
    expect($student->effective_stage_id)->toBe($this->stage->id);
});

it('lets the circle win over a directly chosen stage (no divergence)', function () {
    $response = FormResponse::create([
        'form_id' => $this->form->id,
        'answers' => ['f_name' => 'طالب بحلقة', 'f_email' => 'wc'],
    ]);

    Livewire::test(FormResponses::class, ['formId' => $this->form->id])
        ->call('openCreateModal', $response->id)
        ->set('targetCircleId', $this->circle->id)
        ->set('targetStageId', $this->otherStage->id) // should be ignored in favor of the circle
        ->call('createStudentAccount')
        ->assertHasNoErrors();

    $student = Student::where('name', 'طالب بحلقة')->first();
    expect($student->circle_id)->toBe($this->circle->id);
    expect($student->stage_id)->toBeNull();
    expect($student->fresh()->effective_stage_id)->toBe($this->stage->id); // the circle's stage
});

it('applies one shared password to all bulk-created accounts', function () {
    FormResponse::create(['form_id' => $this->form->id, 'answers' => ['f_name' => 'أحمد', 'f_email' => 'ahmad']]);
    FormResponse::create(['form_id' => $this->form->id, 'answers' => ['f_name' => 'سالم', 'f_email' => 'salim']]);

    Livewire::test(FormResponses::class, ['formId' => $this->form->id])
        ->call('openBulkModal')
        ->set('bulkPassword', 'shared-secret')
        ->call('analyzeBulk')
        ->call('createReadyStudents')
        ->assertHasNoErrors();

    $ahmad = Student::where('name', 'أحمد')->first();
    $salim = Student::where('name', 'سالم')->first();
    expect(Hash::check('shared-secret', $ahmad->password))->toBeTrue();
    expect(Hash::check('shared-secret', $salim->password))->toBeTrue();
});

it('sorts bulk responses into ready and needs-review, and creates after manual review', function () {
    $valid = FormResponse::create(['form_id' => $this->form->id, 'answers' => ['f_name' => 'صالح', 'f_email' => 'saleh']]);
    $noName = FormResponse::create(['form_id' => $this->form->id, 'answers' => ['f_email' => 'noname']]);
    $badDate = FormResponse::create(['form_id' => $this->form->id, 'answers' => ['f_name' => 'تاريخ خاطئ', 'f_birth' => 'ليس تاريخًا']]);

    $component = Livewire::test(FormResponses::class, ['formId' => $this->form->id])
        ->call('openBulkModal')
        ->call('analyzeBulk');

    expect($component->get('bulkReady'))->toHaveCount(1);
    expect($component->get('bulkNeedsReview'))->toHaveCount(2);

    // Create only the ready one first.
    $component->call('createReadyStudents');
    expect(Student::where('name', 'صالح')->exists())->toBeTrue();
    expect($noName->fresh()->student_id)->toBeNull();

    // Fix the missing name and create that reviewed response.
    $component->set("reviewEdits.{$noName->id}.name", 'اسم مُصحّح')
        ->call('createReviewedStudent', $noName->id)
        ->assertHasNoErrors();

    expect(Student::where('name', 'اسم مُصحّح')->exists())->toBeTrue();
    expect($noName->fresh()->student_id)->not->toBeNull();
});

it('rejects placing a student into a stage or circle outside the supervisor scope', function () {
    // A stage/circle the supervisor is NOT assigned to.
    $foreignStage = Stage::create(['name' => 'مرحلة أخرى']);
    $foreignCircle = Circle::create(['name' => 'حلقة بعيدة', 'stage_id' => $foreignStage->id]);

    $response = FormResponse::create(['form_id' => $this->form->id, 'answers' => ['f_name' => 'طالب خارج النطاق', 'f_email' => 'oos']]);

    Livewire::test(FormResponses::class, ['formId' => $this->form->id])
        ->call('openCreateModal', $response->id)
        ->set('targetCircleId', $foreignCircle->id)
        ->call('createStudentAccount')
        ->assertHasErrors('targetCircleId');

    Livewire::test(FormResponses::class, ['formId' => $this->form->id])
        ->call('openCreateModal', $response->id)
        ->set('targetStageId', $foreignStage->id)
        ->call('createStudentAccount')
        ->assertHasErrors('targetStageId');

    expect(Student::where('name', 'طالب خارج النطاق')->exists())->toBeFalse();
});

it('searches responses across all fields, not just the name', function () {
    FormResponse::create(['form_id' => $this->form->id, 'answers' => ['f_name' => 'محمد', 'f_nat' => 'مصري', 'f_phone' => '0551112233']]);
    FormResponse::create(['form_id' => $this->form->id, 'answers' => ['f_name' => 'علي', 'f_nat' => 'سعودي', 'f_phone' => '0509998877']]);

    // Match by nationality (Arabic, non-name field).
    Livewire::test(FormResponses::class, ['formId' => $this->form->id])
        ->set('search', 'مصري')
        ->assertSee('محمد')
        ->assertDontSee('علي');

    // Match by phone (a different non-name field).
    Livewire::test(FormResponses::class, ['formId' => $this->form->id])
        ->set('search', '9998877')
        ->assertSee('علي')
        ->assertDontSee('محمد');
});

it('only displays and filters by stages filled in the responses', function () {
    // Add a third stage that the supervisor manages but has no responses
    $unfilledStage = Stage::create(['name' => 'المرحلة الثانوية']);
    $this->supervisor->stages()->attach($unfilledStage->id);

    // Create a response with $this->stage (linked to student)
    $student = Student::create([
        'name' => 'طالب ابتدائي',
        'email' => 'pri@altag-student.com',
        'password' => bcrypt('password'),
        'stage_id' => $this->stage->id,
        'status' => 'registering',
        'is_approved' => false,
    ]);
    FormResponse::create([
        'form_id' => $this->form->id,
        'student_id' => $student->id,
        'answers' => ['f_name' => 'طالب ابتدائي'],
    ]);

    // Create a response with $this->otherStage (not linked, but has stage name in answers)
    FormResponse::create([
        'form_id' => $this->form->id,
        'answers' => ['f_name' => 'طالب متوسط', 'f_nat' => 'المرحلة المتوسطة'],
    ]);

    $component = Livewire::test(FormResponses::class, ['formId' => $this->form->id]);

    $component->assertViewHas('filterStages', function ($filterStages) {
        $filterStageNames = collect($filterStages)->pluck('name')->all();

        return in_array('المرحلة الابتدائية', $filterStageNames) &&
               in_array('المرحلة المتوسطة', $filterStageNames) &&
               ! in_array('المرحلة الثانوية', $filterStageNames);
    });

    // Filter by $this->stage
    $component->set('filterStageIds', [$this->stage->id])
        ->assertSee('طالب ابتدائي')
        ->assertDontSee('طالب متوسط');

    // Filter by $this->otherStage
    $component->set('filterStageIds', [$this->otherStage->id])
        ->assertSee('طالب متوسط')
        ->assertDontSee('طالب ابتدائي');
});

it('can filter responses by custom form fields', function () {
    // Create form with custom fields including select/multiselect and text
    $customForm = Form::create([
        'supervisor_id' => $this->supervisor->id,
        'title' => 'استمارة مخصصة',
        'slug' => 'custom-form',
        'color' => '#14b8a6',
        'fields' => [
            ['id' => 'q_city', 'type' => 'select', 'label' => 'المدينة', 'options' => ['الرياض', 'جدة', 'الدمام']],
            ['id' => 'q_hobbies', 'type' => 'multiselect', 'label' => 'الهوايات', 'options' => ['القراءة', 'الرياضة', 'الرسم']],
            ['id' => 'q_notes', 'type' => 'text', 'label' => 'ملاحظات'],
        ],
    ]);

    // Create some responses
    FormResponse::create([
        'form_id' => $customForm->id,
        'answers' => ['q_city' => 'الرياض', 'q_hobbies' => ['القراءة', 'الرسم'], 'q_notes' => 'طالب متميز'],
    ]);

    FormResponse::create([
        'form_id' => $customForm->id,
        'answers' => ['q_city' => 'جدة', 'q_hobbies' => ['الرياضة'], 'q_notes' => 'مهتم جداً'],
    ]);

    // Test with select question
    Livewire::test(FormResponses::class, ['formId' => $customForm->id])
        ->set('filterFieldId', 'q_city')
        ->set('filterFieldValue', 'الرياض')
        ->assertViewHas('responses', function ($responses) {
            return $responses->count() === 1 && $responses->first()->answers['q_city'] === 'الرياض';
        })
        ->set('filterFieldValue', 'جدة')
        ->assertViewHas('responses', function ($responses) {
            return $responses->count() === 1 && $responses->first()->answers['q_city'] === 'جدة';
        });

    // Test with multiselect question
    Livewire::test(FormResponses::class, ['formId' => $customForm->id])
        ->set('filterFieldId', 'q_hobbies')
        ->set('filterFieldValue', 'الرياضة')
        ->assertViewHas('responses', function ($responses) {
            return $responses->count() === 1 && $responses->first()->answers['q_city'] === 'جدة'; // Jeddah has الرياضة
        })
        ->set('filterFieldValue', 'الرسم')
        ->assertViewHas('responses', function ($responses) {
            return $responses->count() === 1 && $responses->first()->answers['q_city'] === 'الرياض'; // Riyadh has الرسم
        });

    // Test with text question
    Livewire::test(FormResponses::class, ['formId' => $customForm->id])
        ->set('filterFieldId', 'q_notes')
        ->set('filterFieldValue', 'متميز')
        ->assertViewHas('responses', function ($responses) {
            return $responses->count() === 1 && str_contains($responses->first()->answers['q_notes'], 'متميز');
        });
});

it('creates accounts only for the selected responses', function () {
    $pick1 = FormResponse::create(['form_id' => $this->form->id, 'answers' => ['f_name' => 'محدد أول', 'f_email' => 'pick1']]);
    $pick2 = FormResponse::create(['form_id' => $this->form->id, 'answers' => ['f_name' => 'محدد ثاني', 'f_email' => 'pick2']]);
    $skip = FormResponse::create(['form_id' => $this->form->id, 'answers' => ['f_name' => 'غير محدد', 'f_email' => 'skip']]);

    Livewire::test(FormResponses::class, ['formId' => $this->form->id])
        ->set('selectedResponseIds', [$pick1->id, $pick2->id])
        ->call('openBulkModal', true)
        ->call('analyzeBulk')
        ->call('createReadyStudents')
        ->assertHasNoErrors();

    expect(Student::where('name', 'محدد أول')->exists())->toBeTrue();
    expect(Student::where('name', 'محدد ثاني')->exists())->toBeTrue();
    expect(Student::where('name', 'غير محدد')->exists())->toBeFalse();

    // The unselected response stays unprocessed.
    expect($skip->fresh()->student_id)->toBeNull();
});

it('toggles select-all across unprocessed responses', function () {
    $r1 = FormResponse::create(['form_id' => $this->form->id, 'answers' => ['f_name' => 'أ', 'f_email' => 'a']]);
    $r2 = FormResponse::create(['form_id' => $this->form->id, 'answers' => ['f_name' => 'ب', 'f_email' => 'b']]);

    $component = Livewire::test(FormResponses::class, ['formId' => $this->form->id])
        ->call('toggleSelectAllUnprocessed');

    expect($component->get('selectedResponseIds'))->toHaveCount(2);

    $component->call('toggleSelectAllUnprocessed');
    expect($component->get('selectedResponseIds'))->toHaveCount(0);
});

it('shows circle-less stage-assigned students in the supervisor students list', function () {
    $student = Student::create([
        'name' => 'طالب مرحلة فقط',
        'email' => 'stageonly@altag-student.com',
        'password' => bcrypt('password'),
        'circle_id' => null,
        'stage_id' => $this->stage->id,
        'status' => 'registering',
        'is_approved' => false,
    ]);

    $component = Livewire::test(SupervisorStudents::class);
    $listed = $component->viewData('students')->getCollection();

    expect($listed->pluck('id'))->toContain($student->id);
});

it('bulk deletes circle-less stage-assigned students', function () {
    $student = Student::create([
        'name' => 'طالب للحذف',
        'email' => 'todelete@altag-student.com',
        'password' => bcrypt('password'),
        'circle_id' => null,
        'stage_id' => $this->stage->id,
        'status' => 'registering',
        'is_approved' => false,
    ]);

    Livewire::test(SupervisorStudents::class)
        ->set('selectedIds', [(string) $student->id])
        ->set('deleteConfirmationInput', 'تأكيد الحذف')
        ->call('confirmBulkDelete');

    expect(Student::find($student->id))->toBeNull();
});

it('releases a linked form response back to the pool when its student is deleted', function () {
    $response = FormResponse::create([
        'form_id' => $this->form->id,
        'answers' => ['f_name' => 'طالب مرتبط', 'f_email' => 'linked'],
    ]);

    Livewire::test(FormResponses::class, ['formId' => $this->form->id])
        ->call('openCreateModal', $response->id)
        ->set('targetStageId', $this->stage->id)
        ->call('createStudentAccount')
        ->assertHasNoErrors();

    $student = Student::where('name', 'طالب مرتبط')->first();
    expect($response->fresh()->student_id)->toBe($student->id);
    expect($response->fresh()->is_processed)->toBeTrue();

    // Deleting the student must release the response (both fields reset).
    $student->delete();

    expect($response->fresh()->student_id)->toBeNull();
    expect($response->fresh()->is_processed)->toBeFalse();
});
