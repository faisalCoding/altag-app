<?php

namespace App\Livewire\Public;

use App\Models\Form;
use App\Models\FormResponse;
use App\Models\User;
use App\Services\SurveyAssignmentService;
use App\Support\SurveyFieldTypes;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;
use Livewire\Component;
use Livewire\WithFileUploads;

class FormSubmit extends Component
{
    use WithFileUploads;

    public string $slug;

    public Form $form;

    public array $answers = [];

    public array $temp_uploads = [];

    public array $date_parts = [];

    public array $other_answers = [];

    public bool $submitted = false;

    public function mount(string $slug): void
    {
        $this->slug = $slug;
        $this->form = Form::where('slug', $slug)->firstOrFail();

        // Initialize default answers
        foreach ($this->form->fields as $field) {
            // A section divider is a heading, never an answer.
            if (SurveyFieldTypes::isLayout($field['type'])) {
                continue;
            }

            if ($field['type'] === 'multiselect') {
                $this->answers[$field['id']] = [];
            } elseif ($field['type'] === 'date') {
                $this->answers[$field['id']] = '';
                $this->date_parts[$field['id']] = [
                    'day' => '',
                    'month' => '',
                    'year' => '',
                ];
            } else {
                $this->answers[$field['id']] = '';
            }

            if ($field['allow_other'] ?? false) {
                $this->other_answers[$field['id']] = '';
            }
        }
    }

    public function submit(): void
    {
        // Assemble date parts into standard YYYY-MM-DD format
        foreach ($this->form->fields as $field) {
            if ($field['type'] === 'date') {
                $fieldId = $field['id'];
                $day = $this->date_parts[$fieldId]['day'] ?? '';
                $month = $this->date_parts[$fieldId]['month'] ?? '';
                $year = $this->date_parts[$fieldId]['year'] ?? '';

                if ($day && $month && $year) {
                    $this->answers[$fieldId] = sprintf('%04d-%02d-%02d', $year, $month, $day);
                } else {
                    $this->answers[$fieldId] = '';
                }
            }
        }

        $rules = [];
        $messages = [];

        foreach ($this->form->fields as $field) {
            $fieldId = $field['id'];
            $label = $field['label'];
            $allowOther = $field['allow_other'] ?? false;

            if (SurveyFieldTypes::isLayout($field['type'])) {
                continue;
            }

            // A scale answer must land inside the range the question declares, so
            // a tampered payload cannot store an 11 on a five-point rating.
            if ($bounds = SurveyFieldTypes::scaleBounds($field)) {
                $rule = ($field['required'] ? 'required' : 'nullable')
                    ."|integer|min:{$bounds['min']}|max:{$bounds['max']}";
                $rules["answers.{$fieldId}"] = $rule;
                $messages["answers.{$fieldId}.required"] = "حقل ({$label}) مطلوب.";
                $messages["answers.{$fieldId}.integer"] = "قيمة غير صالحة لحقل ({$label}).";
                $messages["answers.{$fieldId}.min"] = "قيمة خارج المقياس لحقل ({$label}).";
                $messages["answers.{$fieldId}.max"] = "قيمة خارج المقياس لحقل ({$label}).";

                continue;
            }

            if ($field['type'] === 'yesno') {
                $rules["answers.{$fieldId}"] = ($field['required'] ? 'required' : 'nullable').'|in:نعم,لا';
                $messages["answers.{$fieldId}.required"] = "حقل ({$label}) مطلوب.";
                $messages["answers.{$fieldId}.in"] = "قيمة غير صالحة لحقل ({$label}).";

                continue;
            }

            if ($allowOther) {
                $hasOtherSelected = false;
                if ($field['type'] === 'select') {
                    $hasOtherSelected = ($this->answers[$fieldId] ?? '') === 'أخرى';
                } elseif ($field['type'] === 'multiselect') {
                    $hasOtherSelected = in_array('أخرى', $this->answers[$fieldId] ?? []);
                }

                if ($hasOtherSelected) {
                    $rules["other_answers.{$fieldId}"] = 'required|string|max:255';
                    $messages["other_answers.{$fieldId}.required"] = "يرجى تحديد وكتابة الخيار المخصص لحقل ({$label}).";
                }
            }

            if ($field['type'] === 'image') {
                if ($field['required']) {
                    $rules["temp_uploads.{$fieldId}"] = 'required|image|max:10240'; // 10MB limit
                    $messages["temp_uploads.{$fieldId}.required"] = "حقل ({$label}) مطلوب.";
                } else {
                    $rules["temp_uploads.{$fieldId}"] = 'nullable|image|max:10240';
                }
            } else {
                if ($field['required']) {
                    if ($field['type'] === 'multiselect') {
                        $rules["answers.{$fieldId}"] = 'required|array|min:1';
                        $messages["answers.{$fieldId}.required"] = "حقل ({$label}) يتطلب اختيار خيار واحد على الأقل.";
                    } else {
                        $rules["answers.{$fieldId}"] = 'required';
                        $messages["answers.{$fieldId}.required"] = "حقل ({$label}) مطلوب.";
                    }
                } else {
                    $rules["answers.{$fieldId}"] = 'nullable';
                }
            }
        }

        $this->validate($rules, $messages);

        // Process final answers
        $finalAnswers = [];

        foreach ($this->form->fields as $field) {
            $fieldId = $field['id'];

            if (SurveyFieldTypes::isLayout($field['type'])) {
                continue;
            }

            if ($field['type'] === 'image') {
                if (isset($this->temp_uploads[$fieldId]) && $this->temp_uploads[$fieldId]) {
                    $file = $this->temp_uploads[$fieldId];

                    // Compress and convert to WebP
                    $manager = new ImageManager(new Driver);
                    $tempPath = $file->getRealPath();
                    $image = $manager->decode($tempPath);

                    // Scale down if extremely large
                    if ($image->width() > 1000) {
                        $image->scale(width: 1000);
                    }

                    // Encode as WebP (quality 75)
                    $webpData = $image->encode(new WebpEncoder(75))->toString();
                    $filename = 'form_submissions/'.uniqid().'.webp';
                    Storage::disk('public')->put($filename, $webpData);

                    $finalAnswers[$fieldId] = $filename;
                } else {
                    $finalAnswers[$fieldId] = null;
                }
            } else {
                $answer = $this->answers[$fieldId] ?? null;
                $allowOther = $field['allow_other'] ?? false;

                if ($allowOther && $answer !== null) {
                    if ($field['type'] === 'select' && $answer === 'أخرى') {
                        $answer = 'أخرى: '.($this->other_answers[$fieldId] ?? '');
                    } elseif ($field['type'] === 'multiselect' && in_array('أخرى', $answer)) {
                        $key = array_search('أخرى', $answer);
                        if ($key !== false) {
                            $answer[$key] = 'أخرى: '.($this->other_answers[$fieldId] ?? '');
                        }
                    }
                }

                $finalAnswers[$fieldId] = $answer;
            }
        }

        // Save Response
        $response = FormResponse::create([
            'form_id' => $this->form->id,
            'answers' => $finalAnswers,
            'is_processed' => false,
        ]);

        // A signed-in respondent may owe this form. Closing their assignment is
        // what lifts the gate for them and moves the response rate; a stranger
        // arriving by the public link simply has none to close.
        if ($user = self::currentUser()) {
            SurveyAssignmentService::completeFor($this->form, $user->id, $response->id);
        }

        $this->submitted = true;
    }

    /**
     * Whoever is signed in, under whichever guard. The public page is reachable
     * by everyone, so this is often nobody.
     */
    private static function currentUser(): ?User
    {
        foreach (['manager', 'supervisor', 'teacher', 'student', 'guardian', 'staff'] as $guard) {
            if ($user = auth()->guard($guard)->user()) {
                return $user;
            }
        }

        return null;
    }

    public function render()
    {
        return view('livewire.public.form-submit')
            ->layout('layouts.blank'); // We will render the full HTML page in the view directly
    }
}
