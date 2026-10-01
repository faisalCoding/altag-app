<?php

namespace Database\Factories;

use App\Models\ScheduleTrack;
use App\Models\ScheduleWeek;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScheduleWeek>
 */
class ScheduleWeekFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'schedule_track_id' => ScheduleTrack::factory(),
            'week_number' => 1,
            'is_draft' => false,
        ];
    }

    public function draft(): static
    {
        return $this->state(fn () => ['is_draft' => true]);
    }
}
