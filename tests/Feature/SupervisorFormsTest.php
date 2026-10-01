<?php

use App\Livewire\Public\FormSubmit;
use App\Livewire\Supervisor\FormBuilder;
use App\Livewire\Supervisor\FormResponses;
use App\Models\Circle;
use App\Models\Form;
use App\Models\FormResponse;
use App\Models\Stage;
use App\Models\Student;
use App\Models\Supervisor;
use App\Models\Teacher;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->stage = Stage::create(['name' => 'مرحلة النماذج']);
    $this->circle = Circle::create(['name' => 'حلقة النماذج', 'stage_id' => $this->stage->id]);

    $this->supervisor = Supervisor::factory()->create();
    $this->supervisor->stages()->attach($this->stage->id);

    $this->teacher = Teacher::factory()->create();
    $this->teacher->circles()->attach($this->circle->id);

    $this->student = Student::create([
        'name' => 'طالب قديم',
        'email' => 'old_student@example.com',
        'password' => bcrypt('password'),
        'circle_id' => $this->circle->id,
        'is_approved' => true,
        'status' => 'active',
    ]);
});

it('restricts forms management access to supervisors', function () {
    // Unauthenticated user is redirected or gets forbidden
    $this->get(route('supervisor.forms'))->assertRedirect(route('login'));

    // Teacher cannot access supervisor forms
    $this->actingAs($this->teacher, 'teacher');
    $this->get(route('supervisor.forms'))->assertRedirect(route('login'));

    // Supervisor can access forms list page
    $this->actingAs($this->supervisor, 'supervisor');
    $this->get(route('supervisor.forms'))->assertSuccessful();
});

it('allows supervisor to create a form with custom fields', function () {
    $this->actingAs($this->supervisor, 'supervisor');

    Livewire::test(FormBuilder::class)
        ->set('title', 'نموذج تسجيل 2026')
        ->set('description', 'سجل بياناتك هنا')
        ->set('color', '#14b8a6')
        ->set('slug', 'register-2026')
        ->set('fields', [
            [
                'id' => 'field_name',
                'type' => 'text',
                'label' => 'الاسم الكامل',
                'required' => true,
                'is_student_name' => true,
                'is_student_username' => false,
                'options' => [],
            ],
            [
                'id' => 'field_user',
                'type' => 'text',
                'label' => 'اسم المستخدم المفضل',
                'required' => true,
                'is_student_name' => false,
                'is_student_username' => true,
                'options' => [],
            ],
            [
                'id' => 'field_hobbies',
                'type' => 'multiselect',
                'label' => 'الهوايات',
                'required' => false,
                'is_student_name' => false,
                'is_student_username' => false,
                'options' => ['حفظ القرآن', 'القراءة', 'الرياضة'],
            ],
        ])
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('supervisor.forms'));

    $form = Form::where('slug', 'register-2026')->first();
    expect($form)->not->toBeNull();
    expect($form->supervisor_id)->toBe($this->supervisor->id);
    expect($form->color)->toBe('#14b8a6');
    expect($form->fields)->toHaveCount(3);
    expect($form->fields[0]['is_student_name'])->toBeTrue();
});

it('allows public visitor to submit response to the form', function () {
    $form = Form::create([
        'supervisor_id' => $this->supervisor->id,
        'title' => 'نموذج عام',
        'slug' => 'public-form',
        'color' => '#ef4444',
        'fields' => [
            [
                'id' => 'f_name',
                'type' => 'text',
                'label' => 'الاسم',
                'required' => true,
            ],
            [
                'id' => 'f_choice',
                'type' => 'select',
                'label' => 'الفرع الفقهي',
                'required' => true,
                'options' => ['شافعي', 'حنفي', 'مالكي'],
            ],
        ],
    ]);

    // View form page
    $this->get(route('forms.submit', 'public-form'))
        ->assertSuccessful()
        ->assertSee('نموذج عام');

    // Submit form response
    Livewire::test(FormSubmit::class, ['slug' => 'public-form'])
        ->set('answers.f_name', 'محمد أحمد')
        ->set('answers.f_choice', 'شافعي')
        ->call('submit')
        ->assertHasNoErrors();

    $response = FormResponse::where('form_id', $form->id)->first();
    expect($response)->not->toBeNull();
    expect($response->answers['f_name'])->toBe('محمد أحمد');
    expect($response->answers['f_choice'])->toBe('شافعي');
    expect($response->is_processed)->toBeFalse();
});

it('processes image uploads, compresses and converts to webp', function () {
    Storage::fake('public');

    $form = Form::create([
        'supervisor_id' => $this->supervisor->id,
        'title' => 'نموذج بصور',
        'slug' => 'image-form',
        'color' => '#3b82f6',
        'fields' => [
            [
                'id' => 'f_photo',
                'type' => 'image',
                'label' => 'صورة الهوية',
                'required' => true,
            ],
        ],
    ]);

    $file = UploadedFile::fake()->image('avatar.jpg', 1500, 1500);

    Livewire::test(FormSubmit::class, ['slug' => 'image-form'])
        ->set('temp_uploads.f_photo', $file)
        ->call('submit')
        ->assertHasNoErrors();

    $response = FormResponse::where('form_id', $form->id)->first();
    expect($response)->not->toBeNull();

    $savedPath = $response->answers['f_photo'];
    expect($savedPath)->not->toBeNull();
    expect(str_ends_with($savedPath, '.webp'))->toBeTrue();

    // Verify it exists in storage
    Storage::disk('public')->assertExists($savedPath);
});

it('allows supervisor to link response to existing student', function () {
    $form = Form::create([
        'supervisor_id' => $this->supervisor->id,
        'title' => 'نموذج ربط',
        'slug' => 'link-form',
        'color' => '#14b8a6',
        'fields' => [
            [
                'id' => 'f_name',
                'type' => 'text',
                'label' => 'الاسم الكامل',
                'required' => true,
                'is_student_name' => true,
            ],
        ],
    ]);

    $response = FormResponse::create([
        'form_id' => $form->id,
        'answers' => ['f_name' => 'الاسم المحدث للطلاب'],
    ]);

    $this->actingAs($this->supervisor, 'supervisor');

    // Link response to existing student, adopting response name
    Livewire::test(FormResponses::class, ['formId' => $form->id])
        ->call('openLinkModal', $response->id)
        ->set('linkStudentId', $this->student->id)
        ->set('linkNameOption', 'response')
        ->call('linkToExistingStudent')
        ->assertHasNoErrors();

    expect($response->fresh()->student_id)->toBe($this->student->id);
    expect($response->fresh()->is_processed)->toBeTrue();
    expect($this->student->fresh()->name)->toBe('الاسم المحدث للطلاب');
});

it('allows supervisor to create a new student account from response with registering status', function () {
    $form = Form::create([
        'supervisor_id' => $this->supervisor->id,
        'title' => 'نموذج تسجيل جديد',
        'slug' => 'new-student-form',
        'color' => '#14b8a6',
        'fields' => [
            [
                'id' => 'f_name',
                'type' => 'text',
                'label' => 'الاسم الكامل',
                'required' => true,
                'is_student_name' => true,
            ],
            [
                'id' => 'f_user',
                'type' => 'text',
                'label' => 'اسم المستخدم',
                'required' => true,
                'is_student_username' => true,
            ],
        ],
    ]);

    $response = FormResponse::create([
        'form_id' => $form->id,
        'answers' => [
            'f_name' => 'خالد عبد الله',
            'f_user' => 'khaled99',
        ],
    ]);

    $this->actingAs($this->supervisor, 'supervisor');

    Livewire::test(FormResponses::class, ['formId' => $form->id])
        ->call('openCreateModal', $response->id)
        ->assertSet('newStudentName', 'خالد عبد الله')
        ->assertSet('newStudentEmail', 'khaled99')
        ->set('targetCircleId', $this->circle->id)
        ->call('createStudentAccount')
        ->assertHasNoErrors();

    $newStudent = Student::where('name', 'خالد عبد الله')->first();
    expect($newStudent)->not->toBeNull();
    expect($newStudent->email)->toBe('khaled99@altag-student.com');
    expect($newStudent->status)->toBe('registering');
    expect($newStudent->is_approved)->toBeFalse();
    expect($newStudent->circle_id)->toBe($this->circle->id);
    // Circle wins: stage_id stays null and the effective stage comes from the circle.
    expect($newStudent->stage_id)->toBeNull();

    expect($response->fresh()->student_id)->toBe($newStudent->id);
    expect($response->fresh()->is_processed)->toBeTrue();
});

it('allows supervisor to bulk create students from all unlinked responses', function () {
    $form = Form::create([
        'supervisor_id' => $this->supervisor->id,
        'title' => 'نموذج تسجيل جماعي',
        'slug' => 'bulk-form',
        'color' => '#14b8a6',
        'fields' => [
            [
                'id' => 'f_name',
                'type' => 'text',
                'label' => 'الاسم',
                'is_student_name' => true,
            ],
            [
                'id' => 'f_user',
                'type' => 'text',
                'label' => 'اسم المستخدم',
                'is_student_username' => true,
            ],
        ],
    ]);

    $response1 = FormResponse::create([
        'form_id' => $form->id,
        'answers' => ['f_name' => 'ياسر محمد', 'f_user' => 'yasser1'],
    ]);

    $response2 = FormResponse::create([
        'form_id' => $form->id,
        'answers' => ['f_name' => 'سعد خالد', 'f_user' => 'saad2'],
    ]);

    $this->actingAs($this->supervisor, 'supervisor');

    Livewire::test(FormResponses::class, ['formId' => $form->id])
        ->call('openBulkModal')
        ->set('bulkCircleId', $this->circle->id)
        ->call('analyzeBulk')
        ->call('createReadyStudents')
        ->assertHasNoErrors();

    expect(Student::where('name', 'ياسر محمد')->exists())->toBeTrue();
    expect(Student::where('name', 'سعد خالد')->exists())->toBeTrue();

    $student1 = Student::where('name', 'ياسر محمد')->first();
    expect($student1->status)->toBe('registering');
    expect($student1->is_approved)->toBeFalse();
    expect($student1->circle_id)->toBe($this->circle->id);

    expect($response1->fresh()->student_id)->toBe($student1->id);
    expect($response1->fresh()->is_processed)->toBeTrue();
    expect($response2->fresh()->is_processed)->toBeTrue();
});

it('exports the form responses as an Excel-compatible CSV', function () {
    $form = Form::create([
        'supervisor_id' => $this->supervisor->id,
        'title' => 'نموذج التصدير',
        'slug' => 'export-form',
        'color' => '#14b8a6',
        'fields' => [
            ['id' => 'f_name', 'type' => 'text', 'label' => 'الاسم', 'is_student_name' => true],
            ['id' => 'f_hobbies', 'type' => 'multiselect', 'label' => 'الهوايات', 'options' => ['قراءة', 'رياضة']],
        ],
    ]);

    FormResponse::create([
        'form_id' => $form->id,
        'answers' => ['f_name' => 'سعيد الزهراني', 'f_hobbies' => ['قراءة', 'رياضة']],
    ]);

    $this->actingAs($this->supervisor, 'supervisor');

    // The export grid: header carries the field labels + status columns.
    $rows = Livewire::test(FormResponses::class, ['formId' => $form->id])
        ->instance()
        ->responsesExportRows();

    expect($rows[0])->toContain('الاسم', 'الهوايات', 'الطالب المرتبط');
    expect($rows[1])->toContain('سعيد الزهراني', 'قراءة، رياضة', 'غير معالج');

    // The action returns a file download.
    Livewire::test(FormResponses::class, ['formId' => $form->id])
        ->call('exportExcel')
        ->assertFileDownloaded();
});

it('allows supervisor to save a form with policy_text and success_text', function () {
    $this->actingAs($this->supervisor, 'supervisor');

    Livewire::test(FormBuilder::class)
        ->set('title', 'نموذج الشروط والنجاح')
        ->set('description', 'تعبئة استمارة')
        ->set('color', '#ef4444')
        ->set('slug', 'policy-success-form')
        ->set('policy_text', "الشروط:\n١. الشرط الأول\n٢. الشرط الثاني")
        ->set('success_text', "شكراً لكم!\nتم تسجيل البيانات بنجاح.")
        ->set('fields', [
            [
                'id' => 'field_name',
                'type' => 'text',
                'label' => 'الاسم الكامل',
                'required' => true,
                'is_student_name' => true,
                'is_student_username' => false,
                'options' => [],
            ],
        ])
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('supervisor.forms'));

    $form = Form::where('slug', 'policy-success-form')->first();
    expect($form)->not->toBeNull();
    expect($form->policy_text)->toBe("الشروط:\n١. الشرط الأول\n٢. الشرط الثاني");
    expect($form->success_text)->toBe("شكراً لكم!\nتم تسجيل البيانات بنجاح.");
});

it('shows policy and success text on form submission', function () {
    $form = Form::create([
        'supervisor_id' => $this->supervisor->id,
        'title' => 'نموذج الشروط والنجاح العام',
        'slug' => 'public-policy-form',
        'color' => '#ef4444',
        'policy_text' => 'سياسة التقديم المعتمدة',
        'success_text' => 'تم استلام الطلب الخاص بك بنجاح، شكراً لك.',
        'fields' => [
            [
                'id' => 'f_name',
                'type' => 'text',
                'label' => 'الاسم',
                'required' => true,
            ],
        ],
    ]);

    // View form page - should see policy text
    $this->get(route('forms.submit', 'public-policy-form'))
        ->assertSuccessful()
        ->assertSee('سياسة التقديم المعتمدة')
        ->assertSee('موافق، الانتقال إلى الاستمارة');

    // Submit form response and verify it sets submitted = true
    Livewire::test(FormSubmit::class, ['slug' => 'public-policy-form'])
        ->set('answers.f_name', 'أحمد علي')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSet('submitted', true)
        ->assertSee('تم استلام الطلب الخاص بك بنجاح، شكراً لك.');
});

it('assembles split date inputs into standard date string on submission', function () {
    $form = Form::create([
        'supervisor_id' => $this->supervisor->id,
        'title' => 'نموذج تاريخ ميلاد',
        'slug' => 'birthdate-form',
        'color' => '#3b82f6',
        'fields' => [
            [
                'id' => 'f_birthday',
                'type' => 'date',
                'label' => 'تاريخ الميلاد',
                'required' => true,
            ],
        ],
    ]);

    Livewire::test(FormSubmit::class, ['slug' => 'birthdate-form'])
        ->set('date_parts.f_birthday.year', '1995')
        ->set('date_parts.f_birthday.month', '5')
        ->set('date_parts.f_birthday.day', '12')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSet('submitted', true);

    $response = FormResponse::where('form_id', $form->id)->first();
    expect($response)->not->toBeNull();
    expect($response->answers['f_birthday'])->toBe('1995-05-12');
});

it('allows supervisor to create a form with allow_other enabled', function () {
    $this->actingAs($this->supervisor, 'supervisor');

    Livewire::test(FormBuilder::class)
        ->set('title', 'نموذج بخيار أخرى')
        ->set('slug', 'other-option-builder-form')
        ->set('fields', [
            [
                'id' => 'f_select',
                'type' => 'select',
                'label' => 'الخيارات',
                'required' => true,
                'allow_other' => true,
                'options' => ['الأول', 'الثاني'],
            ],
        ])
        ->call('save')
        ->assertHasNoErrors();

    $form = Form::where('slug', 'other-option-builder-form')->first();
    expect($form)->not->toBeNull();
    expect($form->fields[0]['allow_other'])->toBeTrue();
});

it('handles other option submission for select and multiselect fields', function () {
    $form = Form::create([
        'supervisor_id' => $this->supervisor->id,
        'title' => 'نموذج تجربة خيار أخرى',
        'slug' => 'other-submit-form',
        'color' => '#3b82f6',
        'fields' => [
            [
                'id' => 'f_sel',
                'type' => 'select',
                'label' => 'اللون المفضل',
                'required' => true,
                'allow_other' => true,
                'options' => ['أحمر', 'أزرق'],
            ],
            [
                'id' => 'f_multi',
                'type' => 'multiselect',
                'label' => 'الاهتمامات',
                'required' => true,
                'allow_other' => true,
                'options' => ['البرمجة', 'الرياضة'],
            ],
        ],
    ]);

    // Test validation: selecting "أخرى" without typing text should fail
    Livewire::test(FormSubmit::class, ['slug' => 'other-submit-form'])
        ->set('answers.f_sel', 'أخرى')
        ->set('answers.f_multi', ['البرمجة', 'أخرى'])
        ->call('submit')
        ->assertHasErrors(['other_answers.f_sel', 'other_answers.f_multi']);

    // Test successful submission: providing custom text should succeed
    Livewire::test(FormSubmit::class, ['slug' => 'other-submit-form'])
        ->set('answers.f_sel', 'أخرى')
        ->set('other_answers.f_sel', 'أخضر مخصص')
        ->set('answers.f_multi', ['البرمجة', 'أخرى'])
        ->set('other_answers.f_multi', 'القراءة الحرة')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSet('submitted', true);

    $response = FormResponse::where('form_id', $form->id)->first();
    expect($response)->not->toBeNull();
    expect($response->answers['f_sel'])->toBe('أخرى: أخضر مخصص');
    expect($response->answers['f_multi'])->toBe(['البرمجة', 'أخرى: القراءة الحرة']);
});

it('links a response only to one of the supervisor\'s own students, and only within the open form', function () {
    $form = Form::create([
        'supervisor_id' => $this->supervisor->id, 'title' => 'نموذج النطاق', 'slug' => 'scope-form', 'color' => '#14b8a6',
        'fields' => [['id' => 'f_name', 'type' => 'text', 'label' => 'الاسم', 'required' => true, 'is_student_name' => true]],
    ]);
    $otherForm = Form::create([
        'supervisor_id' => Supervisor::factory()->create()->id, 'title' => 'نموذج آخر', 'slug' => 'other-form', 'color' => '#14b8a6',
        'fields' => [['id' => 'f_name', 'type' => 'text', 'label' => 'الاسم', 'required' => true, 'is_student_name' => true]],
    ]);
    $response = FormResponse::create(['form_id' => $form->id, 'answers' => ['f_name' => 'طالب']]);
    $foreignResponse = FormResponse::create(['form_id' => $otherForm->id, 'answers' => ['f_name' => 'غريب']]);
    $outsider = Student::factory()->create([
        'circle_id' => Circle::create(['name' => 'حلقة مرحلة أخرى', 'stage_id' => Stage::create(['name' => 'مرحلة أخرى'])->id])->id,
    ]);

    $this->actingAs($this->supervisor, 'supervisor');

    Livewire::test(FormResponses::class, ['formId' => $form->id])
        ->call('openLinkModal', $response->id)
        ->set('linkStudentId', $outsider->id)
        ->call('linkToExistingStudent')
        ->assertHasErrors('linkStudentId');

    expect(fn () => Livewire::test(FormResponses::class, ['formId' => $form->id])->call('openCreateModal', $foreignResponse->id))
        ->toThrow(ModelNotFoundException::class);

    expect($response->fresh()->student_id)->toBeNull()
        ->and($foreignResponse->fresh()->student_id)->toBeNull();
});
