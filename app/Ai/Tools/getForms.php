<?php

namespace App\Ai\Tools;

use App\Models\Form;
use App\Models\FormAssignment;
use App\Support\ArabicSearch;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * The academy's forms and surveys (النماذج والاستبيانات): each one's state,
 * how many answered, who it was sent to and has yet to answer, and — for the
 * questions with choices — how the answers fall.
 *
 * Free-text answers are never sent: forms ask for national ids, phone numbers
 * and the like, and none of that may leave the server for the AI provider.
 */
class getForms implements Tool
{
    private const MAX_PENDING = 200;

    private const STATUSES = ['draft' => 'مسودة', 'published' => 'منشور', 'closed' => 'مغلق', 'archived' => 'مؤرشف'];

    public function description(): Stringable|string
    {
        return 'Get the forms and surveys (النماذج والاستبيانات): each one\'s status, due date, number of responses, completion of the '
            .'people it was assigned to and who has not answered yet, and for every question with choices how many chose each. '
            .'Free-text answers are not available, by design. Pass "form" (part of a title) to focus on one.';
    }

    public function handle(Request $request): Stringable|string
    {
        $forms = Form::query()
            ->when($request['form'] ?? null, fn ($q, $title) => ArabicSearch::where($q, 'title', $title))
            ->withCount('responses')
            ->latest()
            ->get();

        $focused = filled($request['form'] ?? null);

        return json_encode([
            'forms' => $forms->map(function (Form $form) use ($focused) {
                $row = [
                    'title' => $form->title,
                    'status' => $form->isPublished() ? 'منشور' : (self::STATUSES[$form->status] ?? 'مسودة'),
                    'published_at' => $form->published_at?->format('Y-m-d'),
                    'due_date' => $form->due_date?->format('Y-m-d'),
                    'responses' => $form->responses_count,
                    'assignments' => $form->completion(),
                ];

                if ($focused) {
                    $row['not_answered'] = FormAssignment::query()
                        ->where('form_id', $form->id)
                        ->where('status', '!=', 'completed')
                        ->with('user:id,name')
                        ->limit(self::MAX_PENDING)
                        ->get()
                        ->map(fn (FormAssignment $assignment) => trim((string) $assignment->user?->name))
                        ->filter()
                        ->values()
                        ->all();
                    $row['questions'] = $this->questions($form);
                }

                return $row;
            })->all(),
            'note' => $focused ? null : 'Pass "form" to see one form\'s questions, answer counts and who has not answered.',
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Each question with how many answered it, and for a question with
     * choices how many chose each — the text of an answer never leaves.
     *
     * @return array<int, array<string, mixed>>
     */
    private function questions(Form $form): array
    {
        $answers = $form->responses()->pluck('answers');

        return collect($form->fields ?? [])->map(function (array $field) use ($answers) {
            $id = $field['id'] ?? null;
            $given = $answers->map(fn ($answer) => is_array($answer) ? ($answer[$id] ?? null) : null)
                ->filter(fn ($value) => $value !== null && $value !== '' && $value !== []);

            $question = ['question' => $field['label'] ?? '', 'answered' => $given->count()];

            if (! empty($field['options'])) {
                $question['choices'] = $given->flatMap(fn ($value) => is_array($value) ? $value : [$value])
                    ->map(fn ($value) => (string) $value)
                    ->countBy()
                    ->sortDesc()
                    ->all();
            }

            return $question;
        })->values()->all();
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'form' => $schema->string()->description('Part of a form\'s title, to see its questions and who has not answered.'),
        ];
    }
}
