<?php

namespace App\Livewire\Supervisor;

use App\Ai\SurveyQuestionExtractor;
use App\Models\Circle;
use App\Models\Form;
use App\Models\Stage;
use App\Models\User;
use App\Services\SurveyAssignmentService;
use App\Services\SurveyTextParser;
use App\Support\SurveyFieldTypes;
use Flux\Flux;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;
use Livewire\Component;
use Livewire\WithFileUploads;

class FormBuilder extends Component
{
    use WithFileUploads;

    public ?int $formId = null;

    public bool $isEditing = false;

    // Form settings
    public string $title = '';

    public ?string $description = null;

    public string $color = '#7a2727';

    public string $slug = '';

    public $header_image_file = null;

    public ?string $header_image_path = null;

    public ?string $policy_text = null;

    public ?string $success_text = null;

    public bool $is_public_report = false;

    public bool $is_supervisor_shared = false;

    // Fields
    public array $fields = [];

    // ── Delivery ──────────────────────────────────────────────────────
    /** @var array<string, mixed> The audience rule, in shared_with's shape. */
    public array $audience = [];

    public ?string $due_date = null;

    public bool $is_blocking = false;

    public string $status = 'draft';

    /** The block a supervisor pastes to have it broken into questions. */
    public string $pastedQuestions = '';

    /** @var array<int, array<string, mixed>> Parsed but not yet accepted into the form. */
    public array $parsedPreview = [];

    public bool $aiWorking = false;

    public function mount(?int $formId = null): void
    {
        if ($formId) {
            $this->formId = $formId;
            $this->isEditing = true;

            [$author, $role] = $this->author();

            $form = Form::where('id', $formId)
                ->where(function ($q) use ($author, $role) {
                    $q->where(fn ($owned) => $owned->where('created_by_id', $author->id)
                        ->where('created_by_type', $role));

                    // Supervisors may still share a form with one another, and a
                    // manager oversees everything the academy asks.
                    if ($role === 'supervisor') {
                        // Forms made before ownership became a morph carry only
                        // supervisor_id; their author must not lose them.
                        $q->orWhere('supervisor_id', $author->id)
                            ->orWhere('is_supervisor_shared', true);
                    }
                    if ($role === 'manager') {
                        $q->orWhereRaw('1 = 1');
                    }
                })
                ->firstOrFail();

            $this->title = $form->title;
            $this->description = $form->description;
            $this->color = $form->color;
            $this->slug = $form->slug;
            $this->header_image_path = $form->header_image_path;
            $this->policy_text = $form->policy_text;
            $this->success_text = $form->success_text;
            $this->is_public_report = $form->is_public_report ?? false;
            $this->is_supervisor_shared = $form->is_supervisor_shared ?? false;
            $this->fields = $form->fields ?? [];
            $this->audience = $form->audience ?? [];
            $this->due_date = $form->due_date?->toDateString();
            $this->is_blocking = $form->is_blocking ?? false;
            $this->status = $form->status ?? 'draft';
        } else {
            $this->slug = Str::random(8);
            // Default first field
            $this->addField('text', 'الاسم الكامل', true, true, false);
        }
    }

    /**
     * The author and the role they are building as.
     *
     * Forms used to belong to supervisors alone; managers and teachers own them
     * now too, so every ownership question routes through here rather than
     * reading one guard directly.
     *
     * @return array{0: User, 1: string}
     */
    private function author(): array
    {
        foreach (['manager', 'supervisor', 'teacher'] as $guard) {
            if ($user = auth()->guard($guard)->user()) {
                return [$user, $guard];
            }
        }

        abort(403);
    }

    public function addField(string $type = 'text', string $label = '', bool $required = false, bool $isName = false, bool $isUsername = false): void
    {
        if (! SurveyFieldTypes::exists($type)) {
            return;
        }

        $this->fields[] = array_merge([
            'id' => 'field_'.uniqid(),
            'type' => $type,
            // A section divider asks nothing, so it is never required of anyone.
            'required' => SurveyFieldTypes::isLayout($type) ? false : $required,
            'label' => $label,
            'options' => [],
            'is_student_name' => $isName,
            'is_student_username' => $isUsername,
        ], SurveyFieldTypes::defaultsFor($type));
    }

    /**
     * Break the pasted block into questions and show them for review.
     *
     * Nothing reaches the form here: the supervisor sees what the parser made
     * of their text and accepts it deliberately, so a bad guess is corrected
     * before it becomes the survey rather than after.
     */
    public function parsePastedQuestions(): void
    {
        $this->parsedPreview = SurveyTextParser::parse($this->pastedQuestions);

        if ($this->parsedPreview === []) {
            Flux::toast('لم يُعثر على أسئلة في النص الملصوق', variant: 'warning');
        }
    }

    /**
     * Accept the reviewed questions, appending them after what is already built.
     */
    public function applyParsedQuestions(): void
    {
        if ($this->parsedPreview === []) {
            return;
        }

        $added = 0;
        foreach ($this->parsedPreview as $field) {
            // The registry has the final say, even over our own parser.
            if (! SurveyFieldTypes::exists($field['type'] ?? '')) {
                continue;
            }
            $this->fields[] = $field;
            $added++;
        }

        $this->discardParsedQuestions();

        Flux::toast("تمت إضافة {$added} سؤالاً", variant: 'success');
    }

    /**
     * Ask the model to make better sense of the same pasted text.
     *
     * Offered as a second opinion on top of the rule parser, never as a
     * replacement for review: what comes back lands in the same preview the
     * supervisor already accepts or discards deliberately. A provider that is
     * down leaves the rule-parsed preview exactly as it was.
     */
    public function enhanceWithAi(): void
    {
        if (trim($this->pastedQuestions) === '') {
            Flux::toast('الصق الأسئلة أولاً', variant: 'warning');

            return;
        }

        $this->aiWorking = true;

        $improved = SurveyQuestionExtractor::extract($this->pastedQuestions);

        $this->aiWorking = false;

        if ($improved === []) {
            Flux::toast('تعذّر التحسين بالذكاء — ما فكّكته القواعد باقٍ كما هو', variant: 'warning');

            return;
        }

        $this->parsedPreview = $improved;

        Flux::toast('حسّن الذكاء التفكيك — راجعه قبل الإضافة', variant: 'success');
    }

    public function discardParsedQuestions(): void
    {
        $this->pastedQuestions = '';
        $this->parsedPreview = [];
    }

    public function removeField(int $index): void
    {
        unset($this->fields[$index]);
        $this->fields = array_values($this->fields);
    }

    public function moveUp(int $index): void
    {
        if ($index > 0) {
            $temp = $this->fields[$index - 1];
            $this->fields[$index - 1] = $this->fields[$index];
            $this->fields[$index] = $temp;
        }
    }

    public function moveDown(int $index): void
    {
        if ($index < count($this->fields) - 1) {
            $temp = $this->fields[$index + 1];
            $this->fields[$index + 1] = $this->fields[$index];
            $this->fields[$index] = $temp;
        }
    }

    public function addOption(int $fieldIndex): void
    {
        $this->fields[$fieldIndex]['options'][] = '';
    }

    public function removeOption(int $fieldIndex, int $optionIndex): void
    {
        unset($this->fields[$fieldIndex]['options'][$optionIndex]);
        $this->fields[$fieldIndex]['options'] = array_values($this->fields[$fieldIndex]['options']);
    }

    public function toggleNameDesignation(int $index): void
    {
        $currentState = $this->fields[$index]['is_student_name'] ?? false;

        // Uncheck all other name designations
        foreach ($this->fields as $i => $field) {
            $this->fields[$i]['is_student_name'] = false;
        }

        // Set current designation to toggled state
        $this->fields[$index]['is_student_name'] = ! $currentState;
    }

    public function toggleUsernameDesignation(int $index): void
    {
        $currentState = $this->fields[$index]['is_student_username'] ?? false;

        // Uncheck all other username designations
        foreach ($this->fields as $i => $field) {
            $this->fields[$i]['is_student_username'] = false;
        }

        // Set current designation to toggled state
        $this->fields[$index]['is_student_username'] = ! $currentState;
    }

    public function save(bool $redirect = true): void
    {
        $this->slug = Str::slug($this->slug);

        $this->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'color' => 'required|string|max:7',
            'slug' => 'required|string|alpha_dash|unique:forms,slug,'.$this->formId,
            'header_image_file' => 'nullable|image|max:5120', // 5MB max
            'policy_text' => 'nullable|string',
            'success_text' => 'nullable|string',
            'is_public_report' => 'boolean',
            'is_supervisor_shared' => 'boolean',
            'due_date' => 'nullable|date',
            'is_blocking' => 'boolean',
            'fields' => 'required|array|min:1',
            'fields.*.label' => 'required|string|max:255',
            'fields.*.type' => 'required|in:'.SurveyFieldTypes::validationList(),
            'fields.*.allow_other' => 'nullable|boolean',
            'fields.*.max' => 'nullable|integer|min:3|max:10',
        ], [
            'fields.*.label.required' => 'يجب إدخال تسمية للحقل.',
            'fields.*.max.min' => 'أقل مقياس رضا هو ٣ درجات.',
            'fields.*.max.max' => 'أعلى مقياس رضا هو ١٠ درجات.',
            'slug.unique' => 'رابط الاستمارة مستخدم بالفعل، يرجى كتابة رابط آخر.',
            'slug.alpha_dash' => 'يجب أن يحتوي الرابط على أحرف صغيرة وأرقام وشُرط فقط.',
        ]);

        // Process header image if uploaded
        if ($this->header_image_file) {
            $manager = new ImageManager(new Driver);
            $tempPath = $this->header_image_file->getRealPath();

            // Read image and scale if too wide
            $image = $manager->decode($tempPath);
            if ($image->width() > 1200) {
                $image->scale(width: 1200);
            }

            // Encode as WebP
            $webpData = $image->encode(new WebpEncoder(80))->toString();
            $filename = 'form_headers/'.uniqid().'_header.webp';
            Storage::disk('public')->put($filename, $webpData);

            // Delete old file if exists
            if ($this->header_image_path) {
                Storage::disk('public')->delete($this->header_image_path);
            }

            $this->header_image_path = $filename;
        }

        $data = [
            'title' => $this->title,
            'description' => $this->description,
            'color' => $this->color,
            'slug' => $this->slug,
            'header_image_path' => $this->header_image_path,
            'policy_text' => $this->policy_text,
            'success_text' => $this->success_text,
            'is_public_report' => $this->is_public_report,
            'is_supervisor_shared' => $this->is_supervisor_shared,
            'fields' => $this->fields,
            'audience' => $this->audience,
            'due_date' => $this->due_date ?: null,
            'is_blocking' => $this->is_blocking,
            'status' => $this->status,
        ];

        if ($this->formId) {
            [$author, $role] = $this->author();
            $form = Form::findOrFail($this->formId);

            // Ownership is preserved on edit: a shared form may be improved by any
            // supervisor, and a manager may edit anything, but neither takes it over.
            abort_unless(
                ($form->created_by_id === $author->id && $form->created_by_type === $role)
                    || ($role === 'supervisor' && $form->is_supervisor_shared)
                    || $role === 'manager',
                403
            );

            $form->update($data);
        } else {
            [$author, $role] = $this->author();
            $data['created_by_id'] = $author->id;
            $data['created_by_type'] = $role;
            $data['supervisor_id'] = $role === 'supervisor' ? $author->id : null;
            $form = Form::create($data);

            // Remembered so a follow-on publish has a form to work with, and so a
            // second save edits this one rather than creating another.
            $this->formId = $form->id;
            $this->isEditing = true;
        }

        if (! $redirect) {
            return;
        }

        Flux::toast($this->isEditing ? 'تم تعديل النموذج بنجاح' : 'تم حفظ النموذج بنجاح', variant: 'success');
        // Back to whichever role's list they came from.
        [, $role] = $this->author();
        $this->redirectRoute("{$role}.forms");
    }

    /**
     * Save, then ask the audience.
     *
     * Publishing is separated from saving on purpose: a survey is written and
     * rewritten many times, and each save must not fire notifications at people.
     * Only this button asks anyone anything.
     */
    public function publish(): void
    {
        if ($this->audience === [] || ! collect($this->audience)->filter()->count()) {
            Flux::toast('اختر من تُوجَّه إليه الاستبانة قبل النشر', variant: 'warning');

            return;
        }

        [$author, $role] = $this->author();

        // The audience arrives from a form the author controls, so it is narrowed
        // here — on the server — to the people that role may actually ask.
        $this->audience = SurveyAssignmentService::clampToAuthor($this->audience, $author, $role);

        if ($this->audience === []) {
            Flux::toast('لا أحد ضمن نطاقك في هذا التوجيه', variant: 'warning');

            return;
        }

        $this->status = 'published';
        $this->save(redirect: false);

        $form = Form::findOrFail($this->formId);
        $form->forceFill(['published_at' => $form->published_at ?? now()])->save();

        $added = SurveyAssignmentService::sync($form);
        $notified = SurveyAssignmentService::notifyPending($form);

        Flux::toast(
            $added > 0
                ? "تم النشر وإسنادها إلى {$added} شخصاً، وأُشعر منهم {$notified}"
                : 'تم النشر — لا أحد جديد ليُسنَد إليه',
            variant: 'success'
        );
    }

    /** How many the current audience rule would reach, shown before publishing. */
    public function audienceSize(): int
    {
        $draft = new Form(['audience' => $this->audience]);

        return SurveyAssignmentService::resolveAudience($draft)->count();
    }

    public function render()
    {
        return view('livewire.supervisor.form-builder', [
            'fieldTypes' => SurveyFieldTypes::all(),
            'stages' => Stage::orderBy('name')->get(['id', 'name']),
            'circleList' => Circle::orderBy('name')->get(['id', 'name', 'stage_id']),
            'audienceRoles' => [
                'guardian' => 'أولياء الأمور',
                'student' => 'الطلاب',
                'teacher' => 'المعلمون',
                'supervisor' => 'المشرفون',
                'manager' => 'المديرون',
            ],
            'likertScale' => SurveyFieldTypes::likertScale(),
        ]);
    }
}
