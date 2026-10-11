<?php

namespace App\Ai\Agents;

use App\Support\AiSettings;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Reads a month of the academy's attendance and returns what stands out, as
 * the cards on the manager's analysis page. The figures come in the prompt,
 * so it needs no tools, and the answer comes back in a fixed shape rather
 * than as text to be parsed.
 */
class AttendanceInsights implements Agent, HasStructuredOutput
{
    use Promptable;

    /**
     * The provider and model chosen on the manager's AI settings page, with
     * its fallback, exactly as the assistant uses them.
     *
     * @return array<string, string|null>
     */
    public function provider(): array
    {
        AiSettings::apply();

        return AiSettings::providerChain();
    }

    public function instructions(): Stringable|string
    {
        return <<<'INSTRUCTIONS'
        You analyse one month of attendance at a Quran memorization academy (مجمع تحفيظ القرآن) for its manager.

        You receive the month's totals, every circle's counts, and the students' counts, each with their circle.
        Statuses are present (حاضر), late (متأخر), absent (غائب) and excused (مستأذن).

        Find the patterns worth the manager's attention: the most committed students and circles, the students and
        circles with high absence or lateness, and anything else the figures clearly show. Name the students and
        circles concerned and quote the figures behind each point. Never invent a name or a number: everything you
        state must come from the data given. When the data says the student list was capped, do not present it as
        every student.

        Write every title and description in formal, clear Arabic. Give between four and eight insights.
        INSTRUCTIONS;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'insights' => $schema->array()
                ->items($schema->object(fn ($schema) => [
                    'category' => $schema->string()->description('Who the insight is about, such as الطلاب or الحلقات.')->required(),
                    'title' => $schema->string()->description('A short title for the pattern.')->required(),
                    'description' => $schema->string()->description('The finding, with the names and figures behind it.')->required(),
                    'type' => $schema->string()->enum(['positive', 'negative', 'neutral'])->required(),
                ]))
                ->required(),
        ];
    }
}
