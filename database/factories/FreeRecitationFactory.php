<?php

namespace Database\Factories;

use App\Models\FreeRecitation;
use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FreeRecitation>
 */
class FreeRecitationFactory extends Factory
{
    /**
     * An ungraded hifz entry for today, with no range yet.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'student_id' => Student::factory(),
            'type' => 'hifz',
            'recited_on' => now('Asia/Riyadh')->toDateString(),
            'achievement' => null,
            'graded_at' => null,
        ];
    }

    public function hifz(): static
    {
        return $this->state(['type' => 'hifz']);
    }

    public function review(): static
    {
        return $this->state(['type' => 'review']);
    }

    /** Graded with 0 (لم يسمع) to 3 (ممتاز), stamped now. */
    public function graded(int $grade): static
    {
        return $this->state(['achievement' => $grade, 'graded_at' => now()]);
    }
}
